<?php
/**
 * SEO System - Calendario visual de publicaciones sociales.
 *
 * Lee la misma agenda del Programador (entradas/landings + campañas) y la
 * presenta por mes. No crea otra tabla ni duplica programaciones.
 */

defined('ABSPATH') || exit;

/**
 * @param string $month YYYY-MM
 * @param string $provider
 * @return string
 */
function seo_social_calendar_admin_url($month = '', $provider = '')
{
    $args = array();
    if ($month !== '') {
        $args['social_month'] = $month;
    }
    if ($provider !== '') {
        $args['social_calendar_provider'] = sanitize_key($provider);
    }

    return function_exists('seo_social_network_admin_url')
        ? seo_social_network_admin_url('calendar', $args)
        : add_query_arg(
            array_merge(
                array(
                    'page'          => 'seo-menu-marketing',
                    'tab'           => 'social',
                    'social_subtab' => 'calendar',
                ),
                $args
            ),
            admin_url('admin.php')
        );
}

/**
 * @param string $value
 * @return DateTimeImmutable
 */
function seo_social_calendar_month($value)
{
    $timezone = wp_timezone();
    $value = trim((string) $value);

    if (preg_match('/^\d{4}-\d{2}$/', $value)) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value . '-01', $timezone);
        if ($date instanceof DateTimeImmutable && $date->format('Y-m') === $value) {
            return $date;
        }
    }

    return (new DateTimeImmutable('now', $timezone))->modify('first day of this month')->setTime(0, 0, 0);
}

/**
 * Convierte la agenda canónica del Programador en eventos de calendario.
 *
 * @return array<int,array<string,mixed>>
 */
function seo_social_calendar_events()
{
    if (!function_exists('seo_social_network_scheduler_exportable_agenda_rows')) {
        return array();
    }

    $providers = function_exists('seo_social_network_get_providers')
        ? seo_social_network_get_providers()
        : array();
    $timezone = wp_timezone();
    $events = array();

    foreach ((array) seo_social_network_scheduler_exportable_agenda_rows() as $row) {
        $row = array_values((array) $row);
        if (count($row) < 7) {
            continue;
        }

        $content_id = absint($row[0]);
        $title = sanitize_text_field((string) $row[1]);
        $raw_type = (string) $row[2];
        $provider = sanitize_key((string) $row[3]);
        $raw_date = trim((string) $row[4]);
        $status = sanitize_key((string) $row[6]);

        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $raw_date, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable || (is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count'])))) {
            continue;
        }

        $type_key = 'other';
        $type_label = 'Otro';
        if ('post' === $raw_type) {
            $type_key = 'post';
            $type_label = 'Entrada';
        } elseif ('page' === $raw_type) {
            $type_key = 'page';
            $type_label = 'Landing';
        } elseif (false !== stripos($raw_type, 'camp')) {
            $type_key = 'campaign';
            $type_label = 'Oferta';
        }

        $provider_label = ucfirst($provider);
        if (isset($providers[$provider]['label'])) {
            $provider_label = (string) $providers[$provider]['label'];
        }

        $events[] = array(
            'content_id'     => $content_id,
            'title'          => $title !== '' ? $title : ('Contenido #' . $content_id),
            'type_key'       => $type_key,
            'type_label'     => $type_label,
            'provider'       => $provider,
            'provider_label' => $provider_label,
            'timestamp'      => $date->getTimestamp(),
            'date_key'       => $date->format('Y-m-d'),
            'time'           => $date->format('H:i'),
            'status'         => $status,
            'edit_url'       => $content_id ? get_edit_post_link($content_id) : '',
        );
    }

    usort(
        $events,
        static function ($a, $b) {
            $diff = ((int) $a['timestamp']) <=> ((int) $b['timestamp']);
            if (0 !== $diff) {
                return $diff;
            }
            return strcmp((string) $a['provider'], (string) $b['provider']);
        }
    );

    return $events;
}

/**
 * @param array<int,array<string,mixed>> $events
 * @return bool
 */
function seo_social_calendar_has_same_network_collision($events)
{
    $counts = array();

    foreach ((array) $events as $event) {
        $provider = sanitize_key((string) ($event['provider'] ?? ''));
        if ($provider === '') {
            continue;
        }

        $type = sanitize_key((string) ($event['type_key'] ?? 'other'));
        $content_id = absint($event['content_id'] ?? 0);
        $is_news = 'post' === $type
            && $content_id
            && function_exists('seo_social_network_is_news_content')
            && seo_social_network_is_news_content($content_id);

        if ('campaign' === $type) {
            $bucket = 'campaign';
        } elseif ($is_news) {
            $bucket = 'news';
        } else {
            $bucket = 'editorial';
        }

        if (!isset($counts[$provider])) {
            $counts[$provider] = array(
                'campaign'  => 0,
                'news'      => 0,
                'editorial' => 0,
            );
        }

        $counts[$provider][$bucket]++;

        // Las tres franjas son compatibles el mismo dia:
        // 18:00 oferta, 20:00 Noticias y 22:00 entrada/landing.
        // Solo se considera duplicado si una misma red tiene mas de una
        // publicacion dentro de la misma franja funcional.
        if (
            $counts[$provider]['campaign'] > 1
            || $counts[$provider]['news'] > 1
            || $counts[$provider]['editorial'] > 1
        ) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<int,array<string,mixed>> $events
 * @return array<string,array<int,array<string,mixed>>>
 */
function seo_social_calendar_group_by_day($events)
{
    $days = array();
    foreach ((array) $events as $event) {
        $key = (string) ($event['date_key'] ?? '');
        if ($key === '') {
            continue;
        }
        if (!isset($days[$key])) {
            $days[$key] = array();
        }
        $days[$key][] = $event;
    }
    return $days;
}

/**
 * Render de la subpestaña Calendario.
 */
function seo_social_calendar_render_admin()
{
    $month = seo_social_calendar_month(isset($_GET['social_month']) ? wp_unslash($_GET['social_month']) : '');
    $month_key = $month->format('Y-m');
    $month_start = $month->setTime(0, 0, 0);
    $month_end = $month->modify('last day of this month')->setTime(23, 59, 59);
    $today = new DateTimeImmutable('today', wp_timezone());
    $today_key = $today->format('Y-m-d');

    $provider_filter = isset($_GET['social_calendar_provider'])
        ? sanitize_key(wp_unslash($_GET['social_calendar_provider']))
        : '';

    $providers = function_exists('seo_social_network_get_providers')
        ? seo_social_network_get_providers()
        : array();

    $events = array_values(
        array_filter(
            seo_social_calendar_events(),
            static function ($event) use ($month_start, $month_end, $provider_filter) {
                $timestamp = (int) ($event['timestamp'] ?? 0);
                if ($timestamp < $month_start->getTimestamp() || $timestamp > $month_end->getTimestamp()) {
                    return false;
                }
                if ($provider_filter !== '' && (string) ($event['provider'] ?? '') !== $provider_filter) {
                    return false;
                }
                return true;
            }
        )
    );

    $by_day = seo_social_calendar_group_by_day($events);
    $event_count = count($events);
    $occupied_days = count($by_day);
    $collision_days = 0;
    foreach ($by_day as $day_events) {
        if (seo_social_calendar_has_same_network_collision($day_events)) {
            $collision_days++;
        }
    }

    $future_start = $today > $month_start ? $today : $month_start;
    $empty_future_days = 0;
    if ($future_start <= $month_end) {
        for ($cursor = $future_start; $cursor <= $month_end; $cursor = $cursor->modify('+1 day')) {
            if (empty($by_day[$cursor->format('Y-m-d')])) {
                $empty_future_days++;
            }
        }
    }

    $previous = $month->modify('-1 month')->format('Y-m');
    $next = $month->modify('+1 month')->format('Y-m');
    $current = (new DateTimeImmutable('now', wp_timezone()))->format('Y-m');

    echo '<section class="seo-social-card seo-social-calendar-head">';
    echo '<div class="seo-social-intro"><div><h2>Calendario de publicaciones</h2><p>Vista mensual de la misma agenda del Programador: ofertas a las 18:00, Noticias a las 20:00 y entradas/landings a las 22:00. Las tres franjas pueden convivir el mismo día; el aviso se reserva para duplicados dentro de una misma franja y red.</p></div><span class="seo-social-state is-scheduled">Agenda visual</span></div>';

    echo '<div class="seo-social-calendar-toolbar">';
    echo '<div class="seo-social-calendar-nav">';
    echo '<a class="button" href="' . esc_url(seo_social_calendar_admin_url($previous, $provider_filter)) . '">&larr; Mes anterior</a>';
    echo '<a class="button" href="' . esc_url(seo_social_calendar_admin_url($current, $provider_filter)) . '">Hoy</a>';
    echo '<a class="button" href="' . esc_url(seo_social_calendar_admin_url($next, $provider_filter)) . '">Mes siguiente &rarr;</a>';
    echo '</div>';

    echo '<form method="get" class="seo-social-calendar-filter">';
    echo '<input type="hidden" name="page" value="seo-menu-marketing">';
    echo '<input type="hidden" name="tab" value="social">';
    echo '<input type="hidden" name="social_subtab" value="calendar">';
    echo '<input type="hidden" name="social_month" value="' . esc_attr($month_key) . '">';
    echo '<label><span>Red</span><select name="social_calendar_provider"><option value="">Todas las redes</option>';
    foreach ($providers as $provider_key => $provider) {
        $label = isset($provider['label']) ? (string) $provider['label'] : ucfirst($provider_key);
        echo '<option value="' . esc_attr($provider_key) . '" ' . selected($provider_filter, $provider_key, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label><button class="button" type="submit">Aplicar</button></form>';
    echo '</div>';

    echo '<div class="seo-social-calendar-title-row"><h3>' . esc_html(ucfirst(wp_date('F Y', $month->getTimestamp(), wp_timezone()))) . '</h3>';
    echo '<div class="seo-social-calendar-legend">';
    echo '<span class="seo-social-calendar-legend-item is-post">Entrada</span>';
    echo '<span class="seo-social-calendar-legend-item is-page">Landing</span>';
    echo '<span class="seo-social-calendar-legend-item is-campaign">Oferta</span>';
    echo '<span class="seo-social-calendar-legend-item is-collision">Duplicado real</span>';
    echo '</div></div>';

    echo '<div class="seo-social-calendar-metrics">';
    echo '<div><strong>' . esc_html((string) $event_count) . '</strong><span>publicaciones</span></div>';
    echo '<div><strong>' . esc_html((string) $occupied_days) . '</strong><span>días ocupados</span></div>';
    echo '<div><strong>' . esc_html((string) $empty_future_days) . '</strong><span>días libres futuros</span></div>';
    echo '<div class="' . ($collision_days ? 'has-warning' : '') . '"><strong>' . esc_html((string) $collision_days) . '</strong><span>días con duplicado real</span></div>';
    echo '</div>';
    echo '</section>';

    $weekday_labels = array('Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom');
    $grid_start = $month_start->modify('-' . ((int) $month_start->format('N') - 1) . ' days');
    $grid_end = $month_end->modify('+' . (7 - (int) $month_end->format('N')) . ' days');

    echo '<section class="seo-social-card seo-social-calendar-card">';
    echo '<div class="seo-social-calendar-scroll"><div class="seo-social-calendar-grid">';
    foreach ($weekday_labels as $weekday) {
        echo '<div class="seo-social-calendar-weekday">' . esc_html($weekday) . '</div>';
    }

    for ($day = $grid_start; $day <= $grid_end; $day = $day->modify('+1 day')) {
        $date_key = $day->format('Y-m-d');
        $in_month = $day->format('Y-m') === $month_key;
        $is_today = $date_key === $today_key;
        $is_past = $day < $today;
        $day_events = $in_month && isset($by_day[$date_key]) ? $by_day[$date_key] : array();
        $collision = seo_social_calendar_has_same_network_collision($day_events);
        $is_empty_future = $in_month && !$is_past && !$day_events;

        $classes = array('seo-social-calendar-day');
        if (!$in_month) {
            $classes[] = 'is-outside';
        }
        if ($is_today) {
            $classes[] = 'is-today';
        }
        if ($is_past) {
            $classes[] = 'is-past';
        }
        if ($collision) {
            $classes[] = 'has-collision';
        }
        if ($is_empty_future) {
            $classes[] = 'is-empty-future';
        }

        echo '<div class="' . esc_attr(implode(' ', $classes)) . '">';
        echo '<div class="seo-social-calendar-day-head"><span class="seo-social-calendar-day-number">' . esc_html($day->format('j')) . '</span>';
        if ($in_month && count($day_events) > 1) {
            echo '<span class="seo-social-calendar-day-count">' . esc_html((string) count($day_events)) . '</span>';
        }
        echo '</div>';

        if ($in_month && $day_events) {
            foreach ($day_events as $event) {
                $type_key = sanitize_html_class((string) $event['type_key']);
                $title = (string) $event['title'];
                $link = (string) $event['edit_url'];

                echo '<div class="seo-social-calendar-event is-' . esc_attr($type_key) . '" title="' . esc_attr($event['type_label'] . ' · ' . $event['provider_label'] . ' · ' . $event['time'] . ' · ' . $title) . '">';
                echo '<div class="seo-social-calendar-event-meta"><span>' . esc_html((string) $event['time']) . '</span><span>' . esc_html((string) $event['provider_label']) . '</span></div>';
                if ($link !== '') {
                    echo '<a href="' . esc_url($link) . '">' . esc_html($title) . '</a>';
                } else {
                    echo '<span class="seo-social-calendar-event-title">' . esc_html($title) . '</span>';
                }
                echo '<small>' . esc_html((string) $event['type_label']) . '</small>';
                echo '</div>';
            }
        } elseif ($is_empty_future) {
            echo '<span class="seo-social-calendar-empty-label">Sin publicaciones</span>';
        }

        echo '</div>';
    }

    echo '</div></div>';
    echo '</section>';

    echo '<section class="seo-social-card">';
    echo '<div class="seo-social-intro"><div><h2>Vista compacta</h2><p>Listado cronológico del mes. Útil en móvil y para revisar rápidamente horas, redes y duplicados reales.</p></div></div>';

    if (!$events) {
        echo '<p class="seo-social-code-note">No hay publicaciones programadas para este mes' . ($provider_filter !== '' ? ' en la red seleccionada' : '') . '.</p>';
        echo '</section>';
        return;
    }

    echo '<div class="seo-social-calendar-list">';
    foreach ($by_day as $date_key => $day_events) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $date_key, wp_timezone());
        if (!$date instanceof DateTimeImmutable) {
            continue;
        }
        $collision = seo_social_calendar_has_same_network_collision($day_events);
        echo '<div class="seo-social-calendar-list-day' . ($collision ? ' has-collision' : '') . '">';
        echo '<div class="seo-social-calendar-list-date"><strong>' . esc_html(wp_date('D j M', $date->getTimestamp(), wp_timezone())) . '</strong><span>' . esc_html((string) count($day_events)) . ' publicación' . (count($day_events) === 1 ? '' : 'es') . '</span></div>';
        echo '<div class="seo-social-calendar-list-events">';
        foreach ($day_events as $event) {
            echo '<div class="seo-social-calendar-list-event is-' . esc_attr(sanitize_html_class((string) $event['type_key'])) . '">';
            echo '<strong>' . esc_html((string) $event['time']) . '</strong><span class="seo-social-calendar-list-provider">' . esc_html((string) $event['provider_label']) . '</span>';
            if (!empty($event['edit_url'])) {
                echo '<a href="' . esc_url((string) $event['edit_url']) . '">' . esc_html((string) $event['title']) . '</a>';
            } else {
                echo '<span>' . esc_html((string) $event['title']) . '</span>';
            }
            echo '<small>' . esc_html((string) $event['type_label']) . '</small>';
            echo '</div>';
        }
        echo '</div></div>';
    }
    echo '</div>';
    echo '</section>';
}
