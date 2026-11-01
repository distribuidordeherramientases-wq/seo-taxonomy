<?php
defined('ABSPATH') || exit;

function tccampaigns_calendar_url($args = array()) {
    return add_query_arg(
        array_merge(array('page'=>'taxocommerce-campaigns','tab'=>'calendar'), is_array($args) ? $args : array()),
        admin_url('admin.php')
    );
}

function tccampaigns_calendar_month() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
    $raw = isset($_GET['campaign_month']) ? sanitize_text_field(wp_unslash($_GET['campaign_month'])) : '';
    if (preg_match('/^\d{4}-\d{2}$/', $raw)) return $raw;
    return wp_date('Y-m', time(), wp_timezone());
}

function tccampaigns_calendar_rows($month) {
    global $wpdb;
    $tables = tccampaigns_tables();
    $tz = wp_timezone();
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $tz);
    if (!$start) return array();
    $end = $start->modify('last day of this month')->setTime(23,59,59);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh campaign/social publication inventory query; stale cached data would affect scheduling.
    return (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.*, COUNT(cp.id) AS product_count
             FROM %i c
             LEFT JOIN %i cp ON cp.campaign_id=c.id
             WHERE c.start_at <= %s AND c.end_at >= %s
             GROUP BY c.id ORDER BY c.start_at ASC,c.id ASC",
            $tables['campaigns'],
            $tables['products'],
            $end->format('Y-m-d H:i:s'),
            $start->format('Y-m-d H:i:s')
        )
    );
}

function tccampaigns_calendar_render() {
    $month = tccampaigns_calendar_month();
    $tz = wp_timezone();
    $first = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $tz);
    if (!$first) return;
    $prev = $first->modify('-1 month')->format('Y-m');
    $next = $first->modify('+1 month')->format('Y-m');
    $days = (int) $first->format('t');
    $offset = (int) $first->format('N') - 1;
    $campaigns = tccampaigns_calendar_rows($month);
    $social = function_exists('tccampaigns_social_scheduled_rows') ? tccampaigns_social_scheduled_rows() : array();

    $events = array();
    foreach ($campaigns as $campaign) {
        $start = max($first->getTimestamp(), tccampaigns_timestamp($campaign->start_at));
        $month_end = $first->modify('last day of this month')->setTime(23,59,59)->getTimestamp();
        $end = min($month_end, tccampaigns_timestamp($campaign->end_at));
        for ($ts = (new DateTimeImmutable('@'.$start))->setTimezone($tz)->setTime(12,0)->getTimestamp(); $ts <= $end; $ts += DAY_IN_SECONDS) {
            $day = wp_date('Y-m-d', $ts, $tz);
            $events[$day][] = array(
                'kind'=>'campaign',
                'label'=>(string)$campaign->name,
                'id'=>absint($campaign->id),
                'enabled'=>!empty($campaign->is_enabled),
                'products'=>absint($campaign->product_count),
            );
        }
    }
    foreach ($social as $row) {
        $ts = absint($row['timestamp'] ?? 0);
        if (!$ts || wp_date('Y-m', $ts, $tz) !== $month) continue;
        $day = wp_date('Y-m-d', $ts, $tz);
        $events[$day][] = array(
            'kind'=>'social',
            'label'=>(string)($row['product_name'] ?? ''),
            'campaign'=>(string)($row['campaign_name'] ?? ''),
            'provider'=>(string)($row['provider'] ?? ''),
            'time'=>wp_date('H:i',$ts,$tz),
        );
    }

    echo '<section class="tcc-card"><div class="tcc-calendar-head"><div><h2>Calendario de campañas y ofertas</h2><p>Agenda propia de Campañas. Muestra periodos comerciales y publicaciones de oferta; no mezcla entradas, páginas ni Noticias.</p></div><div>';
    echo '<a class="button" href="'.esc_url(tccampaigns_calendar_url(array('campaign_month'=>$prev))).'">‹</a> ';
    echo '<strong>'.esc_html(wp_date('F Y',$first->getTimestamp(),$tz)).'</strong> ';
    echo '<a class="button" href="'.esc_url(tccampaigns_calendar_url(array('campaign_month'=>$next))).'">›</a></div></div>';
    echo '<div class="tcc-calendar-grid">';
    foreach (array('Lun','Mar','Mié','Jue','Vie','Sáb','Dom') as $label) echo '<div class="tcc-calendar-week">'.esc_html($label).'</div>';
    for($i=0;$i<$offset;$i++) echo '<div class="tcc-calendar-day is-empty"></div>';
    for($d=1;$d<=$days;$d++){
        $date=sprintf('%s-%02d',$month,$d);
        $today=$date===wp_date('Y-m-d',time(),$tz);
        echo '<div class="tcc-calendar-day'.($today?' is-today':'').'"><strong>'.esc_html((string) $d).'</strong>';
        foreach((array)($events[$date]??array()) as $event){
            if('campaign'===$event['kind']){
                $url=tccampaigns_admin_url(array('campaign_id'=>$event['id']));
                echo '<a class="tcc-event tcc-event-campaign'.($event['enabled']?'':' is-disabled').'" href="'.esc_url($url).'">'.esc_html($event['label']).'<small>'.esc_html((string)$event['products']).' productos</small></a>';
            }else{
                echo '<div class="tcc-event tcc-event-social"><b>'.esc_html($event['time']).' '.esc_html(ucfirst($event['provider'])).'</b><small>'.esc_html($event['campaign'].' · '.$event['label']).'</small></div>';
            }
        }
        echo '</div>';
    }
    echo '</div></section>';
}

function tccampaigns_calendar_styles() {
    echo '<style>
    .tcc-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin:0 0 18px}.tcc-calendar-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start}.tcc-calendar-head h2{margin-top:0}.tcc-calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;margin-top:18px}.tcc-calendar-week{font-weight:700;text-align:center;padding:7px}.tcc-calendar-day{min-height:125px;border:1px solid #dcdcde;border-radius:6px;padding:7px;background:#fff}.tcc-calendar-day.is-empty{background:#f6f7f7}.tcc-calendar-day.is-today{outline:2px solid #2271b1}.tcc-event{display:block;margin-top:6px;padding:6px;border-radius:5px;text-decoration:none;font-size:12px;line-height:1.25}.tcc-event small{display:block;margin-top:3px}.tcc-event-campaign{background:#eef6ff;color:#174f7a;border-left:3px solid #2271b1}.tcc-event-campaign.is-disabled{opacity:.55}.tcc-event-social{background:#f0f8ed;color:#205c20;border-left:3px solid #46b450}@media(max-width:900px){.tcc-calendar-grid{display:block}.tcc-calendar-week,.tcc-calendar-day.is-empty{display:none}.tcc-calendar-day{min-height:0;margin-bottom:8px}}
    </style>';
}
