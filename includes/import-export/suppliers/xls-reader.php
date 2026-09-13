<?php
/**
 * SEO System - lector ligero de Excel XLS BIFF8/OLE.
 *
 * Lector autocontenido para tarifas antiguas .xls. No requiere Composer,
 * PhpSpreadsheet, exec, LibreOffice ni extensiones externas de Excel.
 * Extrae valores de celda y URLs de registros HLINK.
 *
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SEO_XLS_Segment_Reader' ) ) {
    final class SEO_XLS_Segment_Reader {
        private $segments = [];
        private $segment  = 0;
        private $offset   = 0;

        public function __construct( array $segments ) {
            $this->segments = array_values( $segments );
        }

        private function remaining() {
            return isset( $this->segments[ $this->segment ] )
                ? strlen( $this->segments[ $this->segment ] ) - $this->offset
                : 0;
        }

        private function next_segment() {
            $this->segment++;
            $this->offset = 0;
            if ( ! isset( $this->segments[ $this->segment ] ) ) {
                throw new RuntimeException( 'Fin inesperado del SST.' );
            }
        }

        public function read( $length ) {
            $length = (int) $length;
            $out = '';
            while ( $length > 0 ) {
                if ( ! isset( $this->segments[ $this->segment ] ) ) {
                    throw new RuntimeException( 'Fin inesperado del flujo SST.' );
                }
                $remaining = $this->remaining();
                if ( $remaining <= 0 ) {
                    $this->next_segment();
                    continue;
                }
                $take = min( $length, $remaining );
                $out .= substr( $this->segments[ $this->segment ], $this->offset, $take );
                $this->offset += $take;
                $length -= $take;
            }
            return $out;
        }

        public function u8() {
            return ord( $this->read( 1 ) );
        }

        public function u16() {
            $v = unpack( 'v', $this->read( 2 ) );
            return (int) $v[1];
        }

        public function u32() {
            $v = unpack( 'V', $this->read( 4 ) );
            return (int) $v[1];
        }

        public function read_chars( $count, $high_byte ) {
            $left = (int) $count;
            $high = (bool) $high_byte;
            $out  = '';

            while ( $left > 0 ) {
                if ( $this->remaining() <= 0 ) {
                    $this->next_segment();
                    // Cuando CONTINUE corta el array de caracteres, su primer
                    // byte indica la codificación del tramo siguiente.
                    $high = (bool) ( $this->u8() & 0x01 );
                }

                $bytes_per_char = $high ? 2 : 1;
                $can_read       = intdiv( max( 0, $this->remaining() ), $bytes_per_char );

                if ( $can_read <= 0 ) {
                    // Caso extremo: un carácter queda partido entre segmentos.
                    $raw = $this->read( $bytes_per_char );
                    $out .= seo_xls_biff_decode_text( $raw, $high );
                    $left--;
                    continue;
                }

                $take = min( $left, $can_read );
                $raw  = $this->read( $take * $bytes_per_char );
                $out .= seo_xls_biff_decode_text( $raw, $high );
                $left -= $take;
            }

            return $out;
        }
    }
}

if ( ! function_exists( 'seo_xls_biff_decode_text' ) ) {
    function seo_xls_biff_decode_text( $raw, $unicode = true ) {
        if ( '' === $raw ) {
            return '';
        }
        $from = $unicode ? 'UTF-16LE' : 'Windows-1252';
        if ( function_exists( 'mb_convert_encoding' ) ) {
            return (string) mb_convert_encoding( $raw, 'UTF-8', $from );
        }
        if ( function_exists( 'iconv' ) ) {
            $converted = @iconv( $from, 'UTF-8//IGNORE', $raw );
            if ( false !== $converted ) {
                return (string) $converted;
            }
        }
        return $unicode ? preg_replace( '/\x00/', '', $raw ) : $raw;
    }
}

if ( ! function_exists( 'seo_xls_biff_u16' ) ) {
    function seo_xls_biff_u16( $data, $offset ) {
        $v = unpack( 'v', substr( $data, $offset, 2 ) );
        return (int) ( $v[1] ?? 0 );
    }
}

if ( ! function_exists( 'seo_xls_biff_u32' ) ) {
    function seo_xls_biff_u32( $data, $offset ) {
        $v = unpack( 'V', substr( $data, $offset, 4 ) );
        return (int) ( $v[1] ?? 0 );
    }
}

if ( ! function_exists( 'seo_xls_biff_sector_chain' ) ) {
    function seo_xls_biff_sector_chain( $start, array $fat, $max = 100000 ) {
        $end  = 0xFFFFFFFE;
        $free = 0xFFFFFFFF;
        $out  = [];
        $sid  = (int) $start;
        $seen = [];

        while ( $sid >= 0 && $sid < 0xFFFFFFFA && $sid !== $end && $sid !== $free && count( $out ) < $max ) {
            if ( isset( $seen[ $sid ] ) || ! isset( $fat[ $sid ] ) ) {
                break;
            }
            $seen[ $sid ] = true;
            $out[] = $sid;
            $sid = (int) $fat[ $sid ];
        }
        return $out;
    }
}

if ( ! function_exists( 'seo_xls_biff_read_ole_stream' ) ) {
    function seo_xls_biff_read_ole_stream( $data, array $chain, $sector_size, $size = null ) {
        $out = '';
        foreach ( $chain as $sid ) {
            $offset = ( (int) $sid + 1 ) * $sector_size;
            $out .= substr( $data, $offset, $sector_size );
        }
        return null === $size ? $out : substr( $out, 0, (int) $size );
    }
}

if ( ! function_exists( 'seo_xls_biff_workbook_stream' ) ) {
    function seo_xls_biff_workbook_stream( $path ) {
        $data = @file_get_contents( $path );
        if ( false === $data || strlen( $data ) < 512 ) {
            return new WP_Error( 'xls_light_open', 'No se pudo abrir el XLS.' );
        }
        if ( "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" !== substr( $data, 0, 8 ) ) {
            return new WP_Error( 'xls_light_signature', 'El archivo no es un XLS OLE válido.' );
        }

        $sector_size      = 1 << seo_xls_biff_u16( $data, 30 );
        $mini_sector_size = 1 << seo_xls_biff_u16( $data, 32 );
        $first_dir        = seo_xls_biff_u32( $data, 48 );
        $mini_cutoff      = seo_xls_biff_u32( $data, 56 );
        $first_minifat    = seo_xls_biff_u32( $data, 60 );
        $num_minifat      = seo_xls_biff_u32( $data, 64 );
        $first_difat      = seo_xls_biff_u32( $data, 68 );
        $num_difat        = seo_xls_biff_u32( $data, 72 );

        if ( $sector_size < 512 || $sector_size > 4096 ) {
            return new WP_Error( 'xls_light_sector', 'Tamaño de sector OLE no compatible.' );
        }

        $fat_sectors = [];
        for ( $i = 0; $i < 109; $i++ ) {
            $sid = seo_xls_biff_u32( $data, 76 + $i * 4 );
            if ( $sid < 0xFFFFFFFA ) {
                $fat_sectors[] = $sid;
            }
        }

        $difat_sid = $first_difat;
        for ( $d = 0; $d < $num_difat && $difat_sid < 0xFFFFFFFA; $d++ ) {
            $offset = ( $difat_sid + 1 ) * $sector_size;
            $sector = substr( $data, $offset, $sector_size );
            $count  = intdiv( $sector_size, 4 );
            for ( $i = 0; $i < $count - 1; $i++ ) {
                $sid = seo_xls_biff_u32( $sector, $i * 4 );
                if ( $sid < 0xFFFFFFFA ) {
                    $fat_sectors[] = $sid;
                }
            }
            $difat_sid = seo_xls_biff_u32( $sector, ( $count - 1 ) * 4 );
        }

        $fat = [];
        foreach ( array_unique( $fat_sectors ) as $sid ) {
            $offset = ( (int) $sid + 1 ) * $sector_size;
            $sector = substr( $data, $offset, $sector_size );
            $count  = intdiv( strlen( $sector ), 4 );
            for ( $i = 0; $i < $count; $i++ ) {
                $fat[] = seo_xls_biff_u32( $sector, $i * 4 );
            }
        }

        if ( empty( $fat ) ) {
            return new WP_Error( 'xls_light_fat', 'No se pudo leer la FAT del XLS.' );
        }

        $dir_chain = seo_xls_biff_sector_chain( $first_dir, $fat );
        $dir_data  = seo_xls_biff_read_ole_stream( $data, $dir_chain, $sector_size );
        $entries   = [];

        for ( $offset = 0; $offset + 128 <= strlen( $dir_data ); $offset += 128 ) {
            $entry = substr( $dir_data, $offset, 128 );
            $name_len = seo_xls_biff_u16( $entry, 64 );
            $name = '';
            if ( $name_len >= 2 && $name_len <= 64 ) {
                $name = seo_xls_biff_decode_text( substr( $entry, 0, $name_len - 2 ), true );
            }
            $entries[] = [
                'name'  => $name,
                'type'  => ord( $entry[66] ),
                'start' => seo_xls_biff_u32( $entry, 116 ),
                'size'  => seo_xls_biff_u32( $entry, 120 ),
            ];
        }

        if ( empty( $entries ) ) {
            return new WP_Error( 'xls_light_directory', 'No se pudo leer el directorio OLE.' );
        }

        $root = $entries[0];
        $root_chain  = seo_xls_biff_sector_chain( $root['start'], $fat );
        $root_stream = seo_xls_biff_read_ole_stream( $data, $root_chain, $sector_size, $root['size'] );

        $minifat = [];
        if ( $num_minifat > 0 && $first_minifat < 0xFFFFFFFA ) {
            $mf_chain = seo_xls_biff_sector_chain( $first_minifat, $fat );
            $mf_data  = seo_xls_biff_read_ole_stream( $data, $mf_chain, $sector_size, $num_minifat * $sector_size );
            $count = intdiv( strlen( $mf_data ), 4 );
            for ( $i = 0; $i < $count; $i++ ) {
                $minifat[] = seo_xls_biff_u32( $mf_data, $i * 4 );
            }
        }

        $workbook = null;
        foreach ( $entries as $entry ) {
            if ( in_array( $entry['name'], [ 'Workbook', 'Book' ], true ) ) {
                $workbook = $entry;
                break;
            }
        }
        if ( ! $workbook ) {
            return new WP_Error( 'xls_light_workbook', 'No se encontró el flujo Workbook dentro del XLS.' );
        }

        if ( $workbook['size'] < $mini_cutoff && ! empty( $minifat ) ) {
            $parts = [];
            $sid   = (int) $workbook['start'];
            $seen  = [];
            while ( $sid >= 0 && $sid < 0xFFFFFFFA && ! isset( $seen[ $sid ] ) && isset( $minifat[ $sid ] ) ) {
                $seen[ $sid ] = true;
                $parts[] = substr( $root_stream, $sid * $mini_sector_size, $mini_sector_size );
                $sid = (int) $minifat[ $sid ];
            }
            return substr( implode( '', $parts ), 0, $workbook['size'] );
        }

        $wb_chain = seo_xls_biff_sector_chain( $workbook['start'], $fat );
        return seo_xls_biff_read_ole_stream( $data, $wb_chain, $sector_size, $workbook['size'] );
    }
}

if ( ! function_exists( 'seo_xls_biff_records' ) ) {
    function seo_xls_biff_records( $stream ) {
        $records = [];
        $length  = strlen( $stream );
        $offset  = 0;
        while ( $offset + 4 <= $length ) {
            $id   = seo_xls_biff_u16( $stream, $offset );
            $size = seo_xls_biff_u16( $stream, $offset + 2 );
            if ( $offset + 4 + $size > $length ) {
                break;
            }
            $records[] = [
                'offset' => $offset,
                'id'     => $id,
                'data'   => substr( $stream, $offset + 4, $size ),
            ];
            $offset += 4 + $size;
        }
        return $records;
    }
}

if ( ! function_exists( 'seo_xls_biff_sst' ) ) {
    function seo_xls_biff_sst( array $records ) {
        $segments = [];
        $start = -1;
        foreach ( $records as $i => $record ) {
            if ( 0x00FC === $record['id'] ) {
                $start = $i;
                $segments[] = $record['data'];
                for ( $j = $i + 1; isset( $records[ $j ] ) && 0x003C === $records[ $j ]['id']; $j++ ) {
                    $segments[] = $records[ $j ]['data'];
                }
                break;
            }
        }
        if ( $start < 0 || empty( $segments ) ) {
            return [];
        }

        try {
            $reader = new SEO_XLS_Segment_Reader( $segments );
            $reader->u32(); // total strings
            $unique = $reader->u32();
            $strings = [];
            for ( $i = 0; $i < $unique; $i++ ) {
                $chars = $reader->u16();
                $flags = $reader->u8();
                $high  = (bool) ( $flags & 0x01 );
                $ext   = (bool) ( $flags & 0x04 );
                $rich  = (bool) ( $flags & 0x08 );
                $runs  = $rich ? $reader->u16() : 0;
                $extra = $ext ? $reader->u32() : 0;
                $strings[] = $reader->read_chars( $chars, $high );
                if ( $runs > 0 ) {
                    $reader->read( 4 * $runs );
                }
                if ( $extra > 0 ) {
                    $reader->read( $extra );
                }
            }
            return $strings;
        } catch ( Throwable $e ) {
            return [];
        }
    }
}

if ( ! function_exists( 'seo_xls_biff_hlink_url' ) ) {
    function seo_xls_biff_hlink_url( $payload ) {
        // URLMoniker guarda normalmente la URL como UTF-16LE. Buscar http(s)
        // directamente evita depender de flags/CLSID específicos de Office.
        foreach ( [ "h\x00t\x00t\x00p\x00s\x00:\x00/\x00/\x00", "h\x00t\x00t\x00p\x00:\x00/\x00/\x00" ] as $needle ) {
            $pos = strpos( $payload, $needle );
            if ( false === $pos ) {
                continue;
            }
            $raw = '';
            $len = strlen( $payload );
            for ( $i = $pos; $i + 1 < $len; $i += 2 ) {
                $pair = substr( $payload, $i, 2 );
                if ( "\x00\x00" === $pair ) {
                    break;
                }
                $raw .= $pair;
            }
            $url = trim( seo_xls_biff_decode_text( $raw, true ) );
            if ( preg_match( '#^https?://#i', $url ) ) {
                return $url;
            }
        }

        if ( preg_match( '#https?://[^\x00\s]+#i', $payload, $m ) ) {
            return trim( $m[0] );
        }
        return '';
    }
}

if ( ! function_exists( 'seo_xls_biff_rk_value' ) ) {
    function seo_xls_biff_rk_value( $rk ) {
        $rk = (int) $rk;
        $divide = (bool) ( $rk & 0x01 );
        $is_int = (bool) ( $rk & 0x02 );
        if ( $is_int ) {
            $value = ( $rk & 0xFFFFFFFC );
            if ( $value & 0x80000000 ) {
                $value -= 0x100000000;
            }
            $value >>= 2;
        } else {
            // RK real: 30 bits superiores de un IEEE754 double.
            $packed = pack( 'V2', 0, $rk & 0xFFFFFFFC );
            $u = unpack( 'd', $packed );
            $value = (float) $u[1];
        }
        return $divide ? $value / 100 : $value;
    }
}

if ( ! function_exists( 'seo_xls_biff_rows' ) ) {
    function seo_xls_biff_rows( $path ) {
        $stream = seo_xls_biff_workbook_stream( $path );
        if ( is_wp_error( $stream ) ) {
            return $stream;
        }
        $records = seo_xls_biff_records( $stream );
        if ( empty( $records ) ) {
            return new WP_Error( 'xls_light_records', 'No se encontraron registros BIFF en el XLS.' );
        }
        $sst = seo_xls_biff_sst( $records );

        // Primera hoja visible: BOUNDSHEET (0x0085) contiene offset absoluto BOF.
        $sheet_offset = null;
        foreach ( $records as $record ) {
            if ( 0x0085 === $record['id'] && strlen( $record['data'] ) >= 4 ) {
                $sheet_offset = seo_xls_biff_u32( $record['data'], 0 );
                break;
            }
        }
        if ( null === $sheet_offset ) {
            return new WP_Error( 'xls_light_sheet', 'No se encontró ninguna hoja en el XLS.' );
        }

        $rows       = [];
        $hyperlinks = [];
        $offset     = (int) $sheet_offset;
        $length     = strlen( $stream );

        while ( $offset + 4 <= $length ) {
            $id   = seo_xls_biff_u16( $stream, $offset );
            $size = seo_xls_biff_u16( $stream, $offset + 2 );
            if ( $offset + 4 + $size > $length ) {
                break;
            }
            $payload = substr( $stream, $offset + 4, $size );
            $offset += 4 + $size;

            if ( 0x000A === $id ) { // EOF de hoja
                break;
            }

            if ( 0x00FD === $id && $size >= 10 ) { // LABELSST
                $row = seo_xls_biff_u16( $payload, 0 );
                $col = seo_xls_biff_u16( $payload, 2 );
                $idx = seo_xls_biff_u32( $payload, 6 );
                $rows[ $row ][ $col ] = isset( $sst[ $idx ] ) ? $sst[ $idx ] : '';
            } elseif ( 0x0203 === $id && $size >= 14 ) { // NUMBER
                $row = seo_xls_biff_u16( $payload, 0 );
                $col = seo_xls_biff_u16( $payload, 2 );
                $v = unpack( 'd', substr( $payload, 6, 8 ) );
                $rows[ $row ][ $col ] = $v[1];
            } elseif ( 0x027E === $id && $size >= 10 ) { // RK
                $row = seo_xls_biff_u16( $payload, 0 );
                $col = seo_xls_biff_u16( $payload, 2 );
                $rows[ $row ][ $col ] = seo_xls_biff_rk_value( seo_xls_biff_u32( $payload, 6 ) );
            } elseif ( 0x00BD === $id && $size >= 6 ) { // MULRK
                $row       = seo_xls_biff_u16( $payload, 0 );
                $first_col = seo_xls_biff_u16( $payload, 2 );
                $last_col  = seo_xls_biff_u16( $payload, $size - 2 );
                $count     = $last_col - $first_col + 1;
                for ( $i = 0; $i < $count; $i++ ) {
                    $rk_offset = 4 + $i * 6 + 2; // salta XF
                    if ( $rk_offset + 4 <= $size - 2 ) {
                        $rows[ $row ][ $first_col + $i ] = seo_xls_biff_rk_value( seo_xls_biff_u32( $payload, $rk_offset ) );
                    }
                }
            } elseif ( 0x0204 === $id && $size >= 8 ) { // LABEL BIFF5/legacy
                $row   = seo_xls_biff_u16( $payload, 0 );
                $col   = seo_xls_biff_u16( $payload, 2 );
                $chars = seo_xls_biff_u16( $payload, 6 );
                $rows[ $row ][ $col ] = seo_xls_biff_decode_text( substr( $payload, 8, $chars ), false );
            } elseif ( 0x01B8 === $id && $size >= 8 ) { // HLINK
                $row = seo_xls_biff_u16( $payload, 0 );
                $col = seo_xls_biff_u16( $payload, 4 );
                $url = seo_xls_biff_hlink_url( $payload );
                if ( '' !== $url ) {
                    $hyperlinks[ $row ][ $col ] = $url;
                }
            }
        }

        foreach ( $hyperlinks as $row => $cols ) {
            foreach ( $cols as $col => $url ) {
                $rows[ $row ][ $col ] = $url;
            }
        }

        if ( empty( $rows ) ) {
            return new WP_Error( 'xls_light_empty', 'El lector XLS no encontró celdas utilizables.' );
        }

        ksort( $rows, SORT_NUMERIC );
        $max_col = 0;
        foreach ( $rows as $cols ) {
            if ( ! empty( $cols ) ) {
                $max_col = max( $max_col, max( array_keys( $cols ) ) );
            }
        }

        $dense = [];
        $last_row = max( array_keys( $rows ) );
        for ( $row = 0; $row <= $last_row; $row++ ) {
            $line = array_fill( 0, $max_col + 1, '' );
            foreach ( (array) ( $rows[ $row ] ?? [] ) as $col => $value ) {
                $line[ (int) $col ] = $value;
            }
            $dense[] = $line;
        }
        return $dense;
    }
}
