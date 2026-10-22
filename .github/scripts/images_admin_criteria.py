"""Guarded, temporary development patch. Scope: taxocommerce-images only."""
from pathlib import Path
import re
root = Path('taxocommerce-images')

def once(text, old, new):
    if text.count(old) != 1:
        raise RuntimeError('Source mismatch: ' + old[:100])
    return text.replace(old, new, 1)

def method(text, name, replacement):
    match = re.search(r'\t(?:public|private|protected) (?:static )?function ' + name + r'\(', text)
    if not match:
        raise RuntimeError('Missing method ' + name)
    end = text.find('\n\t/**', match.start())
    if end < 0:
        raise RuntimeError('Missing method boundary ' + name)
    return text[:match.start()] + replacement.rstrip() + '\n' + text[end:]

p = root / 'includes/class-taxocommerce-images-assignment.php'
s = p.read_text()
s = once(s, 'const PAGE_SIZE  = 8;', 'const PAGE_SIZE  = 25;')
needle = "\t\t$limit = max( 1, min( 8, absint( $limit ) ) );\n\t\t$terms = self::related_terms( $scope, $id );"
s = once(s, needle, '''\t\t$limit = max( 1, min( 8, absint( $limit ) ) );
\t\t// A product never borrows the image of another product or a category.
\t\tif ( 'product' === $scope ) {
\t\t\t$own = array();
\t\t\tforeach ( array_unique( array_map( 'absint', explode( ',', (string) get_post_meta( $id, '_product_image_gallery', true ) ) ) ) as $image ) {
\t\t\t\tif ( self::valid_media( $image ) ) {
\t\t\t\t\t$own[] = array( 'id' => $image, 'label' => __( 'Imagen de este mismo producto', 'taxocommerce-images' ), 'term_id' => 0 );
\t\t\t\t\tif ( count( $own ) >= $limit ) { break; }
\t\t\t\t}
\t\t\t}
\t\t\treturn $own;
\t\t}
\t\t$terms = self::related_terms( $scope, $id );''')
s = method(s, 'handle_assign_auto', r'''	public static function process_assignment_batch( $scope, $after_id = 0 ) {
		if ( ! current_user_can( 'manage_options' ) || ! self::valid_scope( $scope ) ) { return new WP_Error( 'permission', 'Sin permisos o tipo no válido.' ); }
		$after_id = absint( $after_id );
		$post_filter = static function ( $where, $query ) use ( $scope, $after_id ) {
			global $wpdb;
			if ( $scope === $query->get( 'post_type' ) && $query->get( 'meta_query' ) ) { $where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after_id ); }
			return $where;
		};
		$term_args = static function ( $args, $taxonomies ) use ( $scope ) {
			if ( in_array( $scope, (array) $taxonomies, true ) && ! empty( $args['meta_query'] ) ) { $args['orderby'] = 'term_id'; $args['order'] = 'ASC'; }
			return $args;
		};
		$term_filter = static function ( $clauses, $taxonomies, $args ) use ( $scope, $after_id ) {
			global $wpdb;
			if ( in_array( $scope, (array) $taxonomies, true ) && ! empty( $args['meta_query'] ) ) { $clauses['where'] .= $wpdb->prepare( ' AND t.term_id > %d', $after_id ); }
			return $clauses;
		};
		add_filter( 'posts_where', $post_filter, 10, 2 );
		add_filter( 'get_terms_args', $term_args, 10, 2 );
		add_filter( 'terms_clauses', $term_filter, 10, 3 );
		try { $report = self::missing( $scope, 1, self::BULK_LIMIT ); }
		finally {
			remove_filter( 'posts_where', $post_filter, 10 );
			remove_filter( 'get_terms_args', $term_args, 10 );
			remove_filter( 'terms_clauses', $term_filter, 10 );
		}
		$assigned = 0;
		foreach ( $report['items'] as $item ) {
			$after_id = max( $after_id, absint( $item['id'] ) );
			$candidates = self::candidates( $scope, $item['id'], 1 );
			if ( $candidates && self::apply( $scope, $item['id'], $candidates[0]['id'] ) ) { ++$assigned; }
		}
		$all = self::missing( $scope, 1, 1 );
		return array( 'seen' => count( $report['items'] ), 'assigned' => $assigned, 'cursor' => $after_id, 'remaining' => $all['total'], 'done' => count( $report['items'] ) < self::BULK_LIMIT || 0 === $all['total'] );
	}

	public static function handle_assign_auto() {
		$data = self::guard( 'taxocommerce_images_assign_auto' );
		$key = 'taxocommerce_images_assign_cursor_' . get_current_user_id() . '_' . $data['scope'];
		$result = self::process_assignment_batch( $data['scope'], absint( get_transient( $key ) ) );
		if ( is_wp_error( $result ) ) { self::redirect( $data['scope'], 'error', $result->get_error_message() ); }
		if ( $result['done'] ) { delete_transient( $key ); } else { set_transient( $key, $result['cursor'], DAY_IN_SECONDS ); }
		$message = sprintf(
			/* translators: 1: reviewed objects, 2: assigned images, 3: all remaining objects. */
			__( 'Revisados: %1$d. Asignados: %2$d. Pendientes totales: %3$d.', 'taxocommerce-images' ),
			$result['seen'], $result['assigned'], $result['remaining']
		);
		$message .= $result['done'] ? ' Pasada terminada; los casos sin imagen propia siguen pendientes.' : ' El siguiente clic continúa con el siguiente lote.';
		self::redirect( $data['scope'], 'ok', $message );
	}
''')
p.write_text(s)

p = root / 'includes/class-taxocommerce-images.php'
s = p.read_text()
s = once(s, "'http'       => __( 'Disponibilidad HTTP', 'taxocommerce-images' ),", "'labels'     => __( 'Etiquetas y atributos', 'taxocommerce-images' ),")
s = once(s, "case 'http':\n\t\t\t\t\t$this->render_http();", "case 'labels':\n\t\t\t\t\t$this->render_labels();")
s = method(s, 'render_http', "\tprivate function render_labels() { TaxoCommerce_Images_Maintenance::render( 'labels' ); }")
s = method(s, 'render_references', "\tprivate function render_references() { TaxoCommerce_Images_Maintenance::render( 'cleanup' ); }")
s = once(s, "\tprivate function render_assignment() {\n", "\tprivate function render_assignment() {\n\t\techo '<div class=\"notice notice-info inline\"><p>' . esc_html__( 'Productos: solo se propone una imagen de su propia galería. Nunca la de otro producto. Los informes muestran 25 filas y los contadores incluyen todos los pendientes.', 'taxocommerce-images' ) . '</p></div>';\n")
s = once(s, "\tprivate function render_summary() {\n", '''\tprivate function render_summary() {
\t\techo '<h2>' . esc_html__( 'Todos los elementos sin imagen asignada', 'taxocommerce-images' ) . '</h2><div class="tcc-stat-grid">';
\t\tforeach ( TaxoCommerce_Images_Assignment::scopes() as $scope => $label ) {
\t\t\t$pending = TaxoCommerce_Images_Assignment::missing( $scope, 1, 1 );
\t\t\techo '<div class="card"><strong>' . esc_html( number_format_i18n( $pending['total'] ) ) . '</strong> ' . esc_html( $label ) . '</div>';
\t\t}
\t\techo '</div>';
''')
p.write_text(s)
p = root / 'taxocommerce-images.php'
s = p.read_text()
s = re.sub(r'Version: 0\.2\.[0-9]+', 'Version: 0.2.3', s, count=1)
s = re.sub(r"define\( 'TAXOCOMMERCE_IMAGES_VERSION', '[^']+' \);", "define( 'TAXOCOMMERCE_IMAGES_VERSION', '0.2.3' );", s, count=1)
for short, cls in [('http', 'HTTP'), ('references', 'References')]:
    s = s.replace("require_once TAXOCOMMERCE_IMAGES_PATH . 'includes/class-taxocommerce-images-" + short + ".php';\n", '')
    s = s.replace('TaxoCommerce_Images_' + cls + '::boot();\n', '')
s = once(s, "require_once TAXOCOMMERCE_IMAGES_PATH . 'includes/class-taxocommerce-images.php';", "require_once TAXOCOMMERCE_IMAGES_PATH . 'includes/class-taxocommerce-images.php';\nrequire_once TAXOCOMMERCE_IMAGES_PATH . 'includes/class-taxocommerce-images-maintenance.php';")
s = once(s, 'TaxoCommerce_Images_Assignment::boot();', 'TaxoCommerce_Images_Assignment::boot();\nTaxoCommerce_Images_Maintenance::boot();')
p.write_text(s)
for name in ['class-taxocommerce-images-http.php', 'class-taxocommerce-images-references.php']:
    (root / 'includes' / name).unlink()
p = root / 'includes/class-taxocommerce-images-maintenance.php'
s = p.read_text()
s = s.replace('Analiza toda la biblioteca. Al resolver, las duplicadas idénticas se consolidan cuando sus referencias pueden trasladarse con seguridad. Las imágenes sin uso se vuelven a comprobar y se borran. No se conservan copias permanentes ni se añade un historial de cambios.', 'Analiza toda la biblioteca sin límite de 8 MB para comparar archivos. Informa de originales idénticos y medios sin referencias conocidas. Borrado y consolidación automáticos no habilitados en esta entrega; no se elimina ninguna imagen durante el análisis.')
s = re.sub(r"\t\t<\?php if \( 'cleanup' === \$mode \) : \?><button.*?Resolver sin uso y duplicadas</button><\?php endif; \?>", "\t\t<?php if ( 'cleanup' === $mode ) : ?><a class=\"button\" href=\"<?php echo esc_url( admin_url( 'upload.php?mode=list' ) ); ?>\">Revisar en Media</a><?php endif; ?>", s)
p.write_text(s)
p = root / 'includes/legacy-reference-audit.php'
s = p.read_text()
s = once(s, 'foreach ($option_rows as $row) {', "foreach ($option_rows as $row) {\n            if (in_array($row->option_name, array('taxocommerce_images_cleanup_report_v2','taxocommerce_images_labels_report_v2','taxocommerce_images_mutation_lock','taxocommerce_images_audit_state'), true)) { continue; }")
p.write_text(s)
p = root / 'readme.txt'
s = re.sub(r'Stable tag: 0\.2\.[0-9]+', 'Stable tag: 0.2.3', p.read_text())
s = s.replace('== Changelog ==', '== Changelog ==\n\n= 0.2.3 =\n* Products only reuse their own gallery; missing product images stay reported.\n* 25-row reports, full KPIs, and resumable cursors past unresolved rows.\n* Replaced HTTP inspection with local ALT, title and dimensions review.\n* Full-library duplicate/unused scan, persistent results and exact original hashes.\n* No new permanent JPG backups or administrator history.\n* Automatic deletion/consolidation is not enabled; cleanup remains read-only.\n')
p.write_text(s)
print('Applied Images-only source changes.')
