<?php

defined('ABSPATH') || exit;

/**
 * Auditor de datos canonicos.
 *
 * Principios:
 * - PRO y sus fuentes canonicas son la verdad de origen.
 * - El auditor observa, contrasta, prioriza y propone revision humana.
 * - No reindexa, no entrena, no corrige taxonomias, no mueve productos.
 * - Una correlacion semantica nunca se presenta como causalidad demostrada.
 */
final class SEO_Auditor {
    const REPORT_OPTION = 'seo_auditor_last_report'; // legacy: se conserva para compatibilidad
    const CATALOG_REPORT_OPTION = 'seo_auditor_last_catalog_report';
    const ACADEMY_REPORT_OPTION = 'seo_auditor_last_academy_report';
    const HISTORY_OPTION = 'seo_auditor_history';
    const REPORT_VERSION = 6;
    const MAX_BEHAVIOR_PROBES = 60;
    const MAX_BEHAVIOR_CROSS_PROBES = 24;
    const MAX_BEHAVIOR_FAQ_PROBES = 18;
    const MAX_BEHAVIOR_CATEGORY_PROBES = 12;
    const MAX_BEHAVIOR_EDITORIAL_PROBES = 6;
    const BEHAVIOR_TOP_LIMIT = 8;
    const MAX_FINDINGS = 1200;
    const MAX_CATEGORY_PROFILES = 250;
    const MAX_ENTITY_FINDINGS_PER_RULE = 60;
    const MAX_SYSTEMIC_PATTERNS = 50;

    private static $findings = array();
    private static $rule_counts = array();

    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        // Acciones legacy: desde 0.6.0 apuntan a la auditoria de catalogo.
        add_action('admin_post_seo_auditor_run', array(__CLASS__, 'handle_run'));
        add_action('admin_post_seo_auditor_export', array(__CLASS__, 'handle_export'));

        // Auditorias separadas: catalogo y Academia/Estudiante nunca se ejecutan juntas.
        add_action('admin_post_seo_auditor_run_catalog', array(__CLASS__, 'handle_run_catalog'));
        add_action('admin_post_seo_auditor_run_academy', array(__CLASS__, 'handle_run_academy'));
        add_action('admin_post_seo_auditor_export_catalog', array(__CLASS__, 'handle_export_catalog'));
        add_action('admin_post_seo_auditor_export_academy', array(__CLASS__, 'handle_export_academy'));
    }

    public static function enqueue($hook) {
        if (false === strpos((string) $hook, 'seo-dependiente')) {
            return;
        }
        if (sanitize_key((string) ($_GET['tab'] ?? '')) !== 'auditor') {
            return;
        }
        if (defined('SEO_AUDITOR_URL')) {
            wp_enqueue_style('seo-auditor', SEO_AUDITOR_URL . 'assets/css/seo-auditor.css', array('seo-dependiente'), SEO_AUDITOR_VERSION);
        }
    }

    public static function handle_run() {
        // Compatibilidad con enlaces/formularios antiguos: ejecuta SOLO catalogo.
        self::guard_action('seo_auditor_run');
        self::execute_catalog_audit_and_redirect();
    }

    public static function handle_run_catalog() {
        self::guard_action('seo_auditor_run_catalog');
        self::execute_catalog_audit_and_redirect();
    }

    private static function execute_catalog_audit_and_redirect() {
        @set_time_limit(600);
        $report = self::run_catalog_audit();
        update_option(self::CATALOG_REPORT_OPTION, $report, false);
        // Mantener la opcion historica para consumidores externos que aun la lean.
        update_option(self::REPORT_OPTION, $report, false);
        self::append_history($report);
        wp_safe_redirect(add_query_arg(array('page'=>'seo-dependiente','tab'=>'auditor','audited'=>'catalog'), admin_url('admin.php')));
        exit;
    }

    public static function handle_run_academy() {
        self::guard_action('seo_auditor_run_academy');
        @set_time_limit(600);
        $report = self::run_academy_audit();
        update_option(self::ACADEMY_REPORT_OPTION, $report, false);
        wp_safe_redirect(add_query_arg(array('page'=>'seo-dependiente','tab'=>'auditor','audited'=>'academy','audit_view'=>'academia'), admin_url('admin.php')));
        exit;
    }

    public static function handle_export() {
        // Compatibilidad: el export historico pasa a ser el JSON de catalogo.
        self::guard_action('seo_auditor_export');
        self::stream_json_report(self::last_catalog_report(), 'seo-auditor-catalogo');
    }

    public static function handle_export_catalog() {
        self::guard_action('seo_auditor_export_catalog');
        self::stream_json_report(self::last_catalog_report(), 'seo-auditor-catalogo');
    }

    public static function handle_export_academy() {
        self::guard_action('seo_auditor_export_academy');
        self::stream_json_report(self::last_academy_report(), 'seo-auditor-academia');
    }

    private static function stream_json_report($report, $filename_prefix) {
        if (!$report || !is_array($report)) {
            wp_die(esc_html__('No existe una auditoria guardada para exportar.', 'seo-taxonomy'));
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename_prefix . '-' . gmdate('Ymd-His') . '.json') . '"');
        echo wp_json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    public static function render_tab() {
        if (!current_user_can(self::capability())) {
            wp_die(esc_html__('No tienes permisos para acceder al Auditor.', 'seo-taxonomy'));
        }
        $catalog_report = self::last_catalog_report();
        $academy_report = self::last_academy_report();
        $history = (array) get_option(self::HISTORY_OPTION, array());
        $view = sanitize_key((string) ($_GET['audit_view'] ?? 'summary'));
        if (!in_array($view, array('summary','chain','findings','categories','architecture','behavior','academia'), true)) {
            $view = 'summary';
        }

        $audited = sanitize_key((string)($_GET['audited'] ?? ''));
        if ('catalog' === $audited) {
            echo '<div class="notice notice-success is-dismissible"><p>Auditoria de catalogo completada. No se ha ejecutado Academia ni se ha modificado contenido o conocimiento.</p></div>';
        } elseif ('academy' === $audited) {
            echo '<div class="notice notice-success is-dismissible"><p>Auditoria de Academia/Estudiante completada. No se ha recorrido el catalogo completo ni se ha modificado conocimiento.</p></div>';
        }

        echo '<section class="seo-auditor">';
        echo '<div class="seo-auditor__hero">';
        echo '<div><h2>Auditor</h2><p>Dos auditorias independientes en la misma pestaña. <strong>Catalogo</strong> revisa productos, categorias, Vocabulary, FAQs, arquitectura e indice. <strong>Academia / Estudiante</strong> revisa lecciones, runs, promocion de reglas, snapshots y estado del aprendizaje. Ejecuta y exporta solo la que necesites.</p></div>';
        echo '</div>';

        self::render_audit_actions($catalog_report, $academy_report);

        if (!$catalog_report && !$academy_report) {
            self::render_empty();
            echo '</section>';
            return;
        }

        self::render_subnav($view);
        if ('chain' === $view) {
            if ($academy_report) self::render_chain($academy_report); else self::render_missing_scope('Academia / Estudiante');
        } elseif ('academia' === $view) {
            if ($academy_report) self::render_academia($academy_report); else self::render_missing_scope('Academia / Estudiante');
        } elseif ('findings' === $view) {
            if ($catalog_report) self::render_findings($catalog_report); else self::render_missing_scope('catalogo');
        } elseif ('categories' === $view) {
            if ($catalog_report) self::render_categories($catalog_report); else self::render_missing_scope('catalogo');
        } elseif ('architecture' === $view) {
            if ($catalog_report) self::render_architecture($catalog_report); else self::render_missing_scope('catalogo');
        } elseif ('behavior' === $view) {
            if ($catalog_report) self::render_behavior($catalog_report); else self::render_missing_scope('catalogo');
        } else {
            self::render_summary($catalog_report, $history, $academy_report);
        }
        echo '</section>';
    }

    private static function render_audit_actions($catalog_report, $academy_report) {
        echo '<div class="seo-auditor__audit-actions">';

        echo '<div class="seo-auditor__audit-card"><div><strong>Auditoria de catalogo</strong><p>Fuente canonica, productos, categorias, Vocabulary, FAQs, relaciones, arquitectura, indice y pruebas del motor.</p>';
        if ($catalog_report) echo '<span class="description">Ultima: '.esc_html((string)($catalog_report['generated_at']??'')).' · '.esc_html((string)($catalog_report['execution_seconds']??0)).' s</span>';
        echo '</div><div class="seo-auditor__actions">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_auditor_run_catalog">';
        wp_nonce_field('seo_auditor_run_catalog');
        submit_button($catalog_report ? 'Repetir auditoria de catalogo' : 'Auditar catalogo', 'primary', 'submit', false);
        echo '</form>';
        if ($catalog_report) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_auditor_export_catalog">';
            wp_nonce_field('seo_auditor_export_catalog');
            submit_button('Descargar JSON catalogo', 'secondary', 'submit', false);
            echo '</form>';
        }
        echo '</div></div>';

        echo '<div class="seo-auditor__audit-card"><div><strong>Auditoria de Academia / Estudiante</strong><p>Lecciones v2, Entrenador, runs, diagnosticos, reglas promocionadas, academy_stage y snapshots. No recorre el inventario completo.</p>';
        if ($academy_report) echo '<span class="description">Ultima: '.esc_html((string)($academy_report['generated_at']??'')).' · '.esc_html((string)($academy_report['execution_seconds']??0)).' s</span>';
        echo '</div><div class="seo-auditor__actions">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_auditor_run_academy">';
        wp_nonce_field('seo_auditor_run_academy');
        submit_button($academy_report ? 'Repetir auditoria de Academia' : 'Auditar Academia', 'secondary', 'submit', false);
        echo '</form>';
        if ($academy_report) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_auditor_export_academy">';
            wp_nonce_field('seo_auditor_export_academy');
            submit_button('Descargar JSON Academia', 'secondary', 'submit', false);
            echo '</form>';
        }
        echo '</div></div>';
        echo '</div>';
    }

    private static function render_missing_scope($label) {
        echo '<div class="notice notice-info inline"><p>No hay una auditoria guardada de '.esc_html($label).'. Ejecuta su boton arriba; no hace falta ejecutar la otra auditoria.</p></div>';
    }

    public static function last_report() {
        // Compatibilidad externa: desde 0.6.0 equivale al ultimo informe de catalogo.
        return self::last_catalog_report();
    }

    public static function last_catalog_report() {
        $report = get_option(self::CATALOG_REPORT_OPTION, array());
        if (is_array($report) && $report) return $report;
        $legacy = get_option(self::REPORT_OPTION, array());
        return is_array($legacy) && $legacy ? self::catalog_report_from_legacy($legacy) : array();
    }

    public static function last_academy_report() {
        $report = get_option(self::ACADEMY_REPORT_OPTION, array());
        if (is_array($report) && $report) return $report;
        $legacy = get_option(self::REPORT_OPTION, array());
        return is_array($legacy) && !empty($legacy['academy']) ? self::academy_report_from_legacy($legacy) : array();
    }

    public static function run_audit() {
        // Alias historico: ya no ejecuta Academia para evitar informes monoliticos.
        return self::run_catalog_audit();
    }

    public static function run_catalog_audit() {
        self::$findings = array();
        self::$rule_counts = array();
        $started = microtime(true);

        $inventory = self::collect_inventory();
        $data_state = self::audit_data_state($inventory);
        $object_vocabulary = self::load_object_vocabulary();
        $source_integrity = self::audit_source_integrity($inventory);

        $category_profiles = array();
        $architecture_profiles = array('hub_secondary'=>array(),'hub_primary'=>array(),'cluster'=>array());
        $relation_data = array('category_to_secondary'=>array(),'secondary_to_primary'=>array(),'primary_to_cluster'=>array());

        // El indice es una capa derivada: se audita, pero no decide si podemos revisar la fuente canonica.
        self::audit_index_state($inventory);

        $caps = (array)($data_state['capabilities'] ?? array());
        if (!empty($caps['products'])) {
            self::audit_products((array)$inventory['products'], $object_vocabulary, (array)$inventory['categories']);
        }
        if (!empty($caps['categories'])) {
            self::audit_categories((array)$inventory['categories'], (array)$inventory['category_products'], $object_vocabulary, (array)$inventory['category_content']);
        }
        if (!empty($caps['editorial'])) {
            self::audit_editorial((array)$inventory['editorial'], $object_vocabulary);
        }
        if (!empty($caps['faqs'])) {
            self::audit_faqs(
                (array)$inventory['faqs'],
                (array)$inventory['published_product_ids'],
                (array)$inventory['categories'],
                (array)$inventory['products'],
                $object_vocabulary,
                (array)$inventory['category_content']
            );
        }
        if (!empty($caps['architecture'])) {
            $relation_data = self::audit_relations((array)$inventory['relations'], (array)$inventory['categories'], (array)$inventory['category_products']);
        }
        if (!empty($caps['products']) && !empty($caps['categories'])) {
            $category_profiles = self::audit_category_homogeneity(
                (array)$inventory['products'],
                (array)$inventory['categories'],
                (array)$inventory['category_products'],
                $object_vocabulary
            );
        }
        if (!empty($caps['architecture'])) {
            $architecture_profiles = self::audit_architecture_sizing($relation_data, (array)$inventory['category_products']);
            self::audit_architecture_content($relation_data, (array)$inventory['categories']);
        }

        // Preflight conductual independiente de Academia. Ejecuta el motor real
        // sin log de clientes, sin academy_stage y sin mutar aprendizaje.
        $behavior = self::audit_behavior($inventory, $object_vocabulary, $data_state);

        usort(self::$findings, static function($a, $b) {
            $weights = array('critical'=>4,'high'=>3,'medium'=>2,'low'=>1,'info'=>0);
            $wa = $weights[$a['severity'] ?? 'info'] ?? 0;
            $wb = $weights[$b['severity'] ?? 'info'] ?? 0;
            if ($wa !== $wb) return $wb <=> $wa;
            return strcmp((string)($a['code'] ?? ''), (string)($b['code'] ?? ''));
        });

        $summary = array('findings'=>count(self::$findings),'critical'=>0,'high'=>0,'medium'=>0,'low'=>0,'info'=>0,'entities_to_review'=>0);
        $entities = array();
        foreach (self::$findings as $f) {
            $sev = (string)($f['severity'] ?? 'info');
            if (isset($summary[$sev])) $summary[$sev]++;
            $key = (string)($f['entity_type'] ?? '') . ':' . (string)($f['entity_id'] ?? '');
            if ($key !== ':') $entities[$key] = true;
        }
        $summary['entities_to_review'] = count($entities);
        $systemic_patterns = self::build_systemic_patterns(self::$findings);
        $action_plan = self::build_action_plan($systemic_patterns);
        $source_quality = self::build_source_quality($inventory, self::$findings);
        $pre_academy_gate = self::build_pre_academy_gate($source_quality, $behavior);

        $public_inventory = $inventory;
        unset(
            $public_inventory['products'], $public_inventory['index_products'], $public_inventory['product_posts'],
            $public_inventory['published_product_ids'], $public_inventory['categories'], $public_inventory['category_products'],
            $public_inventory['category_content'], $public_inventory['editorial'], $public_inventory['faqs'],
            $public_inventory['relations'], $public_inventory['seo_nodes']
        );

        return array(
            'schema'=>array('name'=>'seo_data_auditor','version'=>self::REPORT_VERSION),
            'auditor_version'=>SEO_AUDITOR_VERSION,
            'dependiente_version'=>defined('SEO_DEPENDIENTE_VERSION') ? SEO_DEPENDIENTE_VERSION : '',
            'generated_at'=>current_time('mysql'),
            'generated_at_gmt'=>gmdate('Y-m-d H:i:s'),
            'execution_seconds'=>round(microtime(true)-$started, 3),
            'mode'=>'manual_read_only',
            'audit_scope'=>'catalog',
            'audit_status'=>'catalog_completed',
            'summary'=>$summary,
            'data_state'=>$data_state,
            'inventory'=>$public_inventory,
            'source_quality'=>$source_quality,
            'behavior_audit'=>$behavior,
            'pre_academy_gate'=>$pre_academy_gate,
            'rule_counts'=>self::$rule_counts,
            'source_integrity'=>$source_integrity,
            'systemic_patterns'=>$systemic_patterns,
            'action_plan'=>$action_plan,
            'findings'=>array_slice(self::$findings, 0, self::MAX_FINDINGS),
            'category_profiles'=>array_slice($category_profiles, 0, self::MAX_CATEGORY_PROFILES),
            'architecture_profiles'=>$architecture_profiles,
            'notes'=>array(
                'read_only'=>true,
                'no_content_mutation'=>true,
                'no_learning_mutation'=>true,
                'no_reindex'=>true,
                'canonical_wordpress_is_primary_source'=>true,
                'dependiente_index_is_derived_evidence'=>true,
                'index_mismatch_does_not_suppress_source_audit'=>true,
                'semantic_flags_are_review_signals'=>true,
                'faq_relation_key'=>'object_type+object_id',
                'faq_owner_scope'=>array('product','product_cat'),
                'faq_to_editorial_relation_inferred'=>false,
                'product_identity_ambiguity_is_separate_from_duplicate_product'=>true,
                'academy_l6_failures_are_reclassified_against_source_identity'=>false,
                'legacy_academy_ignored'=>true,
                'systemic_patterns_prioritized_over_mass_entity_findings'=>true,
                'behavior_probes_independent_from_academia'=>true,
                'behavior_probes_customer_log_written'=>false,
                'behavior_probes_academy_stage_used'=>false,
                'behavior_probe_sample_is_deterministic_not_exhaustive'=>true,
                'academy_audit_separate'=>true,
                'academy_data_not_included'=>true,
            ),
        );
    }


    public static function run_academy_audit() {
        self::$findings = array();
        self::$rule_counts = array();
        $started = microtime(true);

        // Auditoria deliberadamente aislada del catalogo. No carga productos,
        // categorias, FAQs del catalogo ni ejecuta el motor de busqueda.
        $academy = self::audit_academia(array(), false);
        $learning = self::audit_learning_state($academy);
        $learning_chain = self::build_academy_learning_chain($academy, $learning);

        usort(self::$findings, static function($a, $b) {
            $weights = array('critical'=>4,'high'=>3,'medium'=>2,'low'=>1,'info'=>0);
            $wa = $weights[$a['severity'] ?? 'info'] ?? 0;
            $wb = $weights[$b['severity'] ?? 'info'] ?? 0;
            if ($wa !== $wb) return $wb <=> $wa;
            return strcmp((string)($a['code'] ?? ''), (string)($b['code'] ?? ''));
        });

        $summary = array('findings'=>count(self::$findings),'critical'=>0,'high'=>0,'medium'=>0,'low'=>0,'info'=>0,'entities_to_review'=>0);
        $entities = array();
        foreach (self::$findings as $f) {
            $sev = (string)($f['severity'] ?? 'info');
            if (isset($summary[$sev])) $summary[$sev]++;
            $key = (string)($f['entity_type'] ?? '') . ':' . (string)($f['entity_id'] ?? '');
            if ($key !== ':') $entities[$key] = true;
        }
        $summary['entities_to_review'] = count($entities);
        $systemic_patterns = self::build_systemic_patterns(self::$findings);
        $action_plan = self::build_action_plan($systemic_patterns);

        return array(
            'schema'=>array('name'=>'seo_academy_auditor','version'=>self::REPORT_VERSION),
            'auditor_version'=>SEO_AUDITOR_VERSION,
            'dependiente_version'=>defined('SEO_DEPENDIENTE_VERSION') ? SEO_DEPENDIENTE_VERSION : '',
            'generated_at'=>current_time('mysql'),
            'generated_at_gmt'=>gmdate('Y-m-d H:i:s'),
            'execution_seconds'=>round(microtime(true)-$started, 3),
            'mode'=>'manual_read_only',
            'audit_scope'=>'academy',
            'audit_status'=>(string)($learning_chain['status'] ?? 'unknown'),
            'summary'=>$summary,
            'academy'=>$academy,
            'student'=>$learning,
            'learning_chain'=>$learning_chain,
            'rule_counts'=>self::$rule_counts,
            'systemic_patterns'=>$systemic_patterns,
            'action_plan'=>$action_plan,
            'findings'=>array_slice(self::$findings, 0, self::MAX_FINDINGS),
            'notes'=>array(
                'read_only'=>true,
                'catalog_audit_separate'=>true,
                'catalog_inventory_not_loaded'=>true,
                'source_identity_cross_check_disabled'=>true,
                'no_content_mutation'=>true,
                'no_learning_mutation'=>true,
                'no_reindex'=>true,
            ),
        );
    }

    private static function catalog_report_from_legacy($legacy) {
        $report = (array)$legacy;
        unset($report['academy'], $report['student'], $report['learning_chain']);
        $report['audit_scope'] = 'catalog';
        $report['audit_status'] = 'legacy_catalog_snapshot';
        return $report;
    }

    private static function academy_report_from_legacy($legacy) {
        $legacy = (array)$legacy;
        $findings = array_values(array_filter((array)($legacy['findings']??array()), static function($f) {
            $root = (string)($f['root_cause']??'');
            $type = (string)($f['entity_type']??'');
            $code = (string)($f['code']??'');
            return 'lesson' === $type || 0 === strpos($root, 'ACADEMIA') || 0 === strpos($code, 'learning_') || 0 === strpos($code, 'academy_');
        }));
        $summary = array('findings'=>count($findings),'critical'=>0,'high'=>0,'medium'=>0,'low'=>0,'info'=>0,'entities_to_review'=>0);
        $entities=array();
        foreach($findings as $f){$sev=(string)($f['severity']??'info');if(isset($summary[$sev]))$summary[$sev]++;$key=(string)($f['entity_type']??'').':'.(string)($f['entity_id']??'');if($key!==':')$entities[$key]=true;}
        $summary['entities_to_review']=count($entities);
        return array(
            'schema'=>array('name'=>'seo_academy_auditor','version'=>self::REPORT_VERSION),
            'auditor_version'=>(string)($legacy['auditor_version']??''),
            'dependiente_version'=>(string)($legacy['dependiente_version']??''),
            'generated_at'=>(string)($legacy['generated_at']??''),
            'generated_at_gmt'=>(string)($legacy['generated_at_gmt']??''),
            'execution_seconds'=>0,
            'mode'=>'legacy_split_read_only',
            'audit_scope'=>'academy',
            'audit_status'=>(string)($legacy['audit_status']??'legacy_academy_snapshot'),
            'summary'=>$summary,
            'academy'=>(array)($legacy['academy']??array()),
            'student'=>(array)($legacy['student']??array()),
            'learning_chain'=>(array)($legacy['learning_chain']??array()),
            'findings'=>$findings,
            'notes'=>array('legacy_full_report_split'=>true,'read_only'=>true),
        );
    }

    private static function collect_inventory() {
        global $wpdb;

        $status = class_exists('SEO_Dependiente_Index') ? SEO_Dependiente_Index::status() : array();
        $published_rows = (array)$wpdb->get_results(
            "SELECT ID,post_title,post_name,post_excerpt,post_content,post_modified,post_modified_gmt
             FROM {$wpdb->posts}
             WHERE post_type='product' AND post_status='publish'
             ORDER BY ID ASC",
            ARRAY_A
        );

        // 1) Fuente canonica: wp_posts + taxonomias + postmeta. Nunca depende del indice del Dependiente.
        $products = array();
        $product_posts = array();
        $published_product_ids = array();
        foreach ($published_rows as $row) {
            $pid = absint($row['ID'] ?? 0);
            if (!$pid) continue;
            $published_product_ids[$pid] = true;
            $products[$pid] = array(
                'product_id'=>$pid,
                'title'=>(string)($row['post_title'] ?? ''),
                'slug'=>(string)($row['post_name'] ?? ''),
                'excerpt'=>(string)($row['post_excerpt'] ?? ''),
                'description'=>(string)($row['post_content'] ?? ''),
                'seo_title'=>'',
                'seo_description'=>'',
                'sku'=>'',
                'identifiers'=>array(),
                'shipping'=>array(),
                'brand_name'=>'',
                'categories'=>array(),
                'tags'=>array(),
                'attributes'=>array(),
                'taxonomies'=>array(),
                'modified_at'=>(string)($row['post_modified'] ?? ''),
                'post_modified_gmt'=>(string)($row['post_modified_gmt'] ?? ''),
            );
            $product_posts[$pid] = array(
                'ID'=>$pid,
                'post_name'=>(string)($row['post_name'] ?? ''),
                'excerpt_length'=>self::strlen(trim(wp_strip_all_tags((string)($row['post_excerpt'] ?? '')))),
                'content_length'=>self::strlen(trim(wp_strip_all_tags(strip_shortcodes((string)($row['post_content'] ?? ''))))),
                'post_modified'=>(string)($row['post_modified'] ?? ''),
                'post_modified_gmt'=>(string)($row['post_modified_gmt'] ?? ''),
            );
        }

        $seo_attr_stats=array('table_present'=>false,'total'=>0,'published_rows'=>0,'products_with_values'=>0,'empty_rows'=>0,'orphan_products'=>0,'nonpublished_products'=>0,'duplicate_excess_rows'=>0,'reference_unverified'=>false);
        if ($published_product_ids) {
            $meta_rows = (array)$wpdb->get_results(
                "SELECT pm.post_id,pm.meta_key,pm.meta_value
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id AND p.post_type='product' AND p.post_status='publish'
                 WHERE pm.meta_key IN ('_sku','_product_attributes','_yoast_wpseo_title','_yoast_wpseo_metadesc','rank_math_title','rank_math_description','_aioseo_title','_aioseo_description','_seo_proveedor_id_externo','_seo_proveedor_mpn','_global_unique_id','_wc_gla_gtin','_alg_ean','_ean','ean','_gtin','gtin','wpm_gtin_code','_weight','_length','_width','_height')",
                ARRAY_A
            );
            foreach ($meta_rows as $m) {
                $pid = absint($m['post_id'] ?? 0);
                if (!$pid || empty($products[$pid])) continue;
                $key = (string)($m['meta_key'] ?? '');
                $value = (string)($m['meta_value'] ?? '');
                if ('_sku' === $key && '' === (string)$products[$pid]['sku']) $products[$pid]['sku'] = trim($value);
                elseif (in_array($key,array('_yoast_wpseo_title','rank_math_title','_aioseo_title'),true) && '' === (string)$products[$pid]['seo_title']) $products[$pid]['seo_title'] = trim($value);
                elseif (in_array($key,array('_yoast_wpseo_metadesc','rank_math_description','_aioseo_description'),true) && '' === (string)$products[$pid]['seo_description']) $products[$pid]['seo_description'] = trim($value);
                elseif ('_product_attributes' === $key) $products[$pid]['_product_attributes_meta'] = $value;
                elseif (in_array($key,array('_seo_proveedor_id_externo','_seo_proveedor_mpn','_global_unique_id','_wc_gla_gtin','_alg_ean','_ean','ean','_gtin','gtin','wpm_gtin_code'),true) && trim($value)!=='') $products[$pid]['identifiers'][$key]=trim($value);
                elseif (in_array($key,array('_weight','_length','_width','_height'),true) && trim($value)!=='') $products[$pid]['shipping'][$key]=trim($value);
            }

            $term_rows = (array)$wpdb->get_results(
                "SELECT tr.object_id,tt.taxonomy,t.term_id,t.slug,t.name
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                 INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
                 INNER JOIN {$wpdb->posts} p ON p.ID=tr.object_id AND p.post_type='product' AND p.post_status='publish'
                 ORDER BY tr.object_id,tt.taxonomy,t.term_id",
                ARRAY_A
            );

            $category_products = array();
            foreach ($term_rows as $tr) {
                $pid = absint($tr['object_id'] ?? 0);
                if (!$pid || empty($products[$pid])) continue;
                $tax = sanitize_key((string)($tr['taxonomy'] ?? ''));
                $term = array('id'=>absint($tr['term_id'] ?? 0),'slug'=>(string)($tr['slug'] ?? ''),'name'=>(string)($tr['name'] ?? ''));
                if ('product_cat' === $tax) {
                    $products[$pid]['categories'][] = $term;
                    $category_products[$term['id']][$pid] = true;
                } elseif ('product_tag' === $tax) {
                    $products[$pid]['tags'][] = $term;
                } elseif (0 === strpos($tax,'pa_')) {
                    $label = function_exists('wc_attribute_label') ? wc_attribute_label($tax) : ucwords(str_replace(array('pa_','-','_'),array('',' ',' '),$tax));
                    self::append_product_attribute($products[$pid], $tax, $label, (string)$term['name'], 'taxonomy');
                } elseif (in_array($tax,array('product_brand','pwb-brand','yith_product_brand'),true)) {
                    if ('' === (string)$products[$pid]['brand_name']) $products[$pid]['brand_name'] = (string)$term['name'];
                    $products[$pid]['taxonomies'][$tax][] = $term;
                } elseif (!in_array($tax,array('product_type','product_visibility','product_shipping_class'),true)) {
                    $products[$pid]['taxonomies'][$tax][] = $term;
                }
            }

            // Atributos SEO canonicos usados tambien por el indice/Academia.
            $seo_attr_table=$wpdb->prefix.'seo_attributes';
            if(self::table_exists($seo_attr_table)){
                $seo_attr_stats['table_present']=true;
                $seo_attr_stats['total']=absint($wpdb->get_var("SELECT COUNT(*) FROM {$seo_attr_table}"));
                $seo_attr_stats['empty_rows']=absint($wpdb->get_var("SELECT COUNT(*) FROM {$seo_attr_table} WHERE TRIM(COALESCE(attribute_type,''))='' OR TRIM(COALESCE(attribute_value,''))=''"));
                $joined_product_rows=absint($wpdb->get_var("SELECT COUNT(*) FROM {$seo_attr_table} a INNER JOIN {$wpdb->posts} p ON p.ID=a.product_id AND p.post_type='product'"));
                if($seo_attr_stats['total']>0 && 0===$joined_product_rows){
                    // En algunas instalaciones seo_attributes usa un identificador comercial/derivado en product_id.
                    // Sin confirmar el contrato de la tabla, no declaramos el 100% como huerfano.
                    $seo_attr_stats['reference_unverified']=true;
                    $seo_attr_stats['orphan_products']=0;
                    $seo_attr_stats['nonpublished_products']=0;
                } else {
                    $seo_attr_stats['orphan_products']=absint($wpdb->get_var("SELECT COUNT(*) FROM {$seo_attr_table} a LEFT JOIN {$wpdb->posts} p ON p.ID=a.product_id AND p.post_type='product' WHERE p.ID IS NULL"));
                    $seo_attr_stats['nonpublished_products']=absint($wpdb->get_var("SELECT COUNT(*) FROM {$seo_attr_table} a INNER JOIN {$wpdb->posts} p ON p.ID=a.product_id AND p.post_type='product' WHERE p.post_status<>'publish'"));
                }
                $seo_attr_stats['duplicate_excess_rows']=absint($wpdb->get_var("SELECT COALESCE(SUM(x.c-1),0) FROM (SELECT COUNT(*) c FROM {$seo_attr_table} GROUP BY product_id,ambito,attribute_type,attribute_value HAVING COUNT(*)>1) x"));
                $seo_attr_rows=(array)$wpdb->get_results("SELECT a.product_id,a.ambito,a.attribute_type,a.attribute_value FROM {$seo_attr_table} a INNER JOIN {$wpdb->posts} p ON p.ID=a.product_id AND p.post_type='product' AND p.post_status='publish' WHERE TRIM(COALESCE(a.attribute_type,''))<>'' AND TRIM(COALESCE(a.attribute_value,''))<>'' ORDER BY a.product_id,a.attribute_type,a.id",ARRAY_A);
                $seo_attr_stats['published_rows']=count($seo_attr_rows);$seo_attr_product_ids=array();
                foreach($seo_attr_rows as $a){$pid=absint($a['product_id']??0);if(!$pid||empty($products[$pid]))continue;$seo_attr_product_ids[$pid]=true;$type=trim((string)($a['attribute_type']??''));$label=ucwords(str_replace(array('_','-'),' ',$type));foreach(self::split_attribute_values($a['attribute_value']??'') as $av)self::append_product_attribute($products[$pid],$type,$label,$av,'seo_taxonomy');}
                $seo_attr_stats['products_with_values']=count($seo_attr_product_ids);
            }

            // Atributos locales de WooCommerce que no son taxonomias globales.
            foreach ($products as $pid=>&$product) {
                $raw = isset($product['_product_attributes_meta']) ? maybe_unserialize($product['_product_attributes_meta']) : array();
                if (is_array($raw)) {
                    foreach ($raw as $akey=>$adata) {
                        if (!is_array($adata)) continue;
                        if (!empty($adata['is_taxonomy'])) continue; // Ya se cargo desde term_relationships.
                        $label = trim((string)($adata['name'] ?? $akey));
                        $value = (string)($adata['value'] ?? '');
                        foreach (preg_split('/\s*\|\s*/u',$value) ?: array() as $av) {
                            $av = trim((string)$av);
                            if ($av !== '') self::append_product_attribute($product, (string)$akey, $label, $av, 'postmeta');
                        }
                    }
                }
                unset($product['_product_attributes_meta'],$product['_attr_map']);
            }
            unset($product);
        } else {
            $category_products = array();
        }

        $terms = get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false));
        $categories = array();
        if (!is_wp_error($terms)) {
            foreach ((array)$terms as $term) $categories[absint($term->term_id)] = $term;
        }
        $category_products = array_intersect_key($category_products, $categories);

        // Contenido SEO propio del sistema, cuando existe. Se usa como evidencia adicional, nunca para sustituir wp_posts.
        $seo_nodes = array();
        $category_content = array();
        $nodes = $wpdb->prefix . 'seo_nodes';
        if (self::table_exists($nodes)) {
            $node_rows = (array)$wpdb->get_results(
                "SELECT object_type,object_id,seo_role,keywords FROM {$nodes} WHERE status=1 ORDER BY object_type,object_id,seo_role",
                ARRAY_A
            );
            foreach ($node_rows as $nr) {
                $ot = sanitize_key((string)($nr['object_type'] ?? ''));
                $oid = absint($nr['object_id'] ?? 0);
                $role = sanitize_key((string)($nr['seo_role'] ?? ''));
                if (!$oid || !$role) continue;
                $value = (string)($nr['keywords'] ?? '');
                $seo_nodes[$ot.':'.$oid][$role] = $value;
                if (in_array($ot,array('category','product_cat'),true)) $category_content[$oid][$role] = $value;
                if ('product' === $ot && isset($products[$oid])) {
                    if ('seo_title' === $role && '' === (string)$products[$oid]['seo_title']) $products[$oid]['seo_title'] = $value;
                    if (in_array($role,array('seo_description','meta_description'),true) && '' === (string)$products[$oid]['seo_description']) $products[$oid]['seo_description'] = $value;
                }
            }
        }

        $editorial = (array)$wpdb->get_results(
            "SELECT ID,post_type,post_title,post_name,post_excerpt,post_content,post_modified FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') ORDER BY ID ASC",
            ARRAY_A
        );

        // FAQs: relacion canonica = object_type + object_id. El texto nunca decide el owner.
        $faq_table = $wpdb->prefix . 'seo_faq';
        $faqs = array();
        $faq_stats = array('total'=>0,'active_all'=>0,'active_supported'=>0,'inactive'=>0,'type1_active'=>0,'type2_active'=>0,'type3_active'=>0,'other_active'=>0,'orphan_categories_active'=>0,'orphan_products_active'=>0,'nonpublished_products_active'=>0);
        if (self::table_exists($faq_table)) {
            $faq_stats['total'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$faq_table}"));
            $faq_stats['active_all'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$faq_table} WHERE active=1"));
            $faq_stats['active_supported'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$faq_table} WHERE active=1 AND object_type IN (2,3)"));
            $faq_stats['inactive'] = max(0,$faq_stats['total']-$faq_stats['active_all']);
            $faq_stats['type1_active'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$faq_table} WHERE active=1 AND object_type=1"));
            $faq_stats['type2_active'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$faq_table} WHERE active=1 AND object_type=2"));
            $faq_stats['type3_active'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$faq_table} WHERE active=1 AND object_type=3"));
            $faq_stats['other_active'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$faq_table} WHERE active=1 AND object_type NOT IN (1,2,3)"));
            $faq_stats['orphan_categories_active'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$faq_table} f LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=f.object_id AND tt.taxonomy='product_cat' WHERE f.active=1 AND f.object_type=2 AND tt.term_id IS NULL"));
            $faq_stats['orphan_products_active'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$faq_table} f LEFT JOIN {$wpdb->posts} p ON p.ID=f.object_id WHERE f.active=1 AND f.object_type=3 AND (p.ID IS NULL OR p.post_type<>'product')"));
            $faq_stats['nonpublished_products_active'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$faq_table} f INNER JOIN {$wpdb->posts} p ON p.ID=f.object_id AND p.post_type='product' WHERE f.active=1 AND f.object_type=3 AND p.post_status<>'publish'"));
            $faqs = (array)$wpdb->get_results("SELECT id,object_type,object_id,question,LEFT(answer,2000) answer,CHAR_LENGTH(TRIM(answer)) answer_length,active,updated_at FROM {$faq_table} WHERE active=1 ORDER BY id ASC", ARRAY_A);
        }

        $rel_table = $wpdb->prefix . 'seo_relations';
        $relations = self::table_exists($rel_table) ? (array)$wpdb->get_results("SELECT source_type,source_id,target_type,target_id,relation_type FROM {$rel_table} ORDER BY source_type,source_id,target_type,target_id,relation_type", ARRAY_A) : array();

        $vocab_table = $wpdb->prefix . 'seo_vocabulary';
        $ov_table = $wpdb->prefix . 'seo_object_vocabulary';
        $vocab_count = self::table_exists($vocab_table) ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$vocab_table} WHERE active=1")) : 0;
        $vocab_total = self::table_exists($vocab_table) ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$vocab_table}")) : 0;
        $ov_stats = array('total'=>0,'managed_total'=>0,'active_managed'=>0,'inactive_managed'=>0,'orphan_vocabulary'=>0,'orphan_objects'=>0,'by_type'=>array());
        if (self::table_exists($ov_table)) {
            $ov_stats['total'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$ov_table}"));
            $ov_stats['managed_total'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$ov_table} WHERE object_type IN ('product','product_cat','post','page')"));
            $ov_stats['active_managed'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$ov_table} WHERE status=1 AND object_type IN ('product','product_cat','post','page')"));
            $ov_stats['inactive_managed'] = max(0,$ov_stats['managed_total']-$ov_stats['active_managed']);
            if (self::table_exists($vocab_table)) $ov_stats['orphan_vocabulary'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$ov_table} ov LEFT JOIN {$vocab_table} v ON v.id=ov.vocabulary_id WHERE ov.status=1 AND ov.object_type IN ('product','product_cat','post','page') AND v.id IS NULL"));
            $orphan_sql = "SELECT COUNT(*) FROM {$ov_table} ov WHERE ov.status=1 AND ov.object_type IN ('product','product_cat','post','page') AND ((ov.object_type='product' AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID=ov.object_id AND p.post_type='product')) OR (ov.object_type='post' AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID=ov.object_id AND p.post_type='post')) OR (ov.object_type='page' AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID=ov.object_id AND p.post_type='page')) OR (ov.object_type='product_cat' AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id=ov.object_id AND tt.taxonomy='product_cat')))";
            $ov_stats['orphan_objects'] = absint($wpdb->get_var($orphan_sql));
            foreach (array('product','product_cat','post','page') as $ot) {
                $ov_stats['by_type'][$ot] = array('total'=>absint($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ov_table} WHERE object_type=%s",$ot))),'active'=>absint($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ov_table} WHERE object_type=%s AND status=1",$ot))));
            }
        }

        // 2) Indice del Dependiente: evidencia derivada, separada de la fuente canonica.
        $index_products = array();
        $indexed_category_ids = array();
        $indexed_with_categories = 0;
        $indexed_with_vocabulary = 0;
        $index_table = class_exists('SEO_Dependiente_Index') ? SEO_Dependiente_Index::table() : '';
        $index_exists = $index_table && self::table_exists($index_table);
        $index_verification = array();
        $index_stale_rows = 0;
        $index_stale_ids = array();
        $index_max_updated_at = '';
        if ($index_exists) {
            $rows = (array)$wpdb->get_results("SELECT product_id,title,excerpt,brand_name,categories_json,tags_json,vocabulary_json,attributes_json,post_modified_gmt,updated_at FROM {$index_table} ORDER BY product_id ASC", ARRAY_A);
            foreach ($rows as $row) {
                $d = class_exists('SEO_Dependiente_Index') ? SEO_Dependiente_Index::decode_row($row) : $row;
                $pid = absint($d['product_id'] ?? 0); if (!$pid) continue;
                $index_products[$pid] = $d;
                if (!empty($d['categories'])) $indexed_with_categories++;
                foreach ((array)($d['vocabulary'] ?? array()) as $items) { if (!empty($items)) { $indexed_with_vocabulary++; break; } }
                foreach ((array)($d['categories'] ?? array()) as $cat) { $cid=absint($cat['id']??0); if($cid)$indexed_category_ids[$cid]=true; }
            }
            $index_max_updated_at = (string)$wpdb->get_var("SELECT COALESCE(MAX(updated_at),'') FROM {$index_table}");
            $index_stale_rows = absint($wpdb->get_var("SELECT COUNT(*) FROM {$index_table} i INNER JOIN {$wpdb->posts} p ON p.ID=i.product_id AND p.post_type='product' AND p.post_status='publish' WHERE COALESCE(NULLIF(i.post_modified_gmt,'0000-00-00 00:00:00'),'') <> COALESCE(NULLIF(p.post_modified_gmt,'0000-00-00 00:00:00'),'')"));
            if ($index_stale_rows) $index_stale_ids = array_map('absint',(array)$wpdb->get_col("SELECT i.product_id FROM {$index_table} i INNER JOIN {$wpdb->posts} p ON p.ID=i.product_id AND p.post_type='product' AND p.post_status='publish' WHERE COALESCE(NULLIF(i.post_modified_gmt,'0000-00-00 00:00:00'),'') <> COALESCE(NULLIF(p.post_modified_gmt,'0000-00-00 00:00:00'),'') ORDER BY i.product_id ASC LIMIT 25"));
        }
        if (class_exists('SEO_Dependiente_Index') && method_exists('SEO_Dependiente_Index','verification_report')) $index_verification = (array)SEO_Dependiente_Index::verification_report(25);

        $invalid_index_category_ids = array_values(array_diff(array_keys($indexed_category_ids),array_keys($categories)));
        sort($invalid_index_category_ids,SORT_NUMERIC);
        $published_products = count($published_product_ids);
        $indexed = count($index_products);
        $overlap_ids = array_intersect_key($index_products,$published_product_ids);
        $overlap = count($overlap_ids);
        $indexable_products = isset($index_verification['indexable']) ? absint($index_verification['indexable']) : (isset($status['indexable']) ? absint($status['indexable']) : null);
        $excluded_hidden = isset($index_verification['excluded_hidden']) ? absint($index_verification['excluded_hidden']) : (isset($status['excluded_hidden']) ? absint($status['excluded_hidden']) : null);

        return array(
            'catalog_status'=>$status,
            'canonical_products_loaded'=>count($products),
            'published_products'=>$published_products,
            'published_product_ids'=>$published_product_ids,
            'index_table_present'=>$index_exists,
            'index_verification'=>$index_verification,
            'index_verified'=>!empty($index_verification['verified']),
            'indexed_products'=>$indexed,
            'products_loaded_for_comparison'=>$indexed,
            'indexable_products'=>$indexable_products,
            'hidden_products'=>$excluded_hidden,
            'index_current_product_overlap'=>$overlap,
            'index_overlap_ratio'=>$indexed ? round($overlap/$indexed,4) : 0,
            'index_coverage_ratio'=>$published_products ? round($overlap/$published_products,4) : 0,
            'index_missing_products'=>absint($index_verification['missing'] ?? 0),
            'index_extra_products'=>absint($index_verification['extra'] ?? 0),
            'index_missing_ids'=>array_values(array_filter(array_map('absint',(array)($index_verification['missing_ids'] ?? array())))),
            'index_extra_ids'=>array_values(array_filter(array_map('absint',(array)($index_verification['extra_ids'] ?? array())))),
            'index_stale_rows'=>$index_stale_rows,
            'index_stale_ids'=>array_values(array_filter($index_stale_ids)),
            'index_max_updated_at'=>$index_max_updated_at,
            'indexed_products_with_categories'=>$indexed_with_categories,
            'indexed_products_with_vocabulary'=>$indexed_with_vocabulary,
            'indexed_category_ids_total'=>count($indexed_category_ids),
            'invalid_index_category_refs'=>count($invalid_index_category_ids),
            'invalid_index_category_ids'=>array_slice($invalid_index_category_ids,0,25),
            'categories_total'=>count($categories),
            'categories_with_products'=>count(array_filter($category_products)),
            'posts_published'=>count(array_filter($editorial,static function($r){return 'post'===($r['post_type']??'');})),
            'pages_published'=>count(array_filter($editorial,static function($r){return 'page'===($r['post_type']??'');})),
            'faqs_total'=>$faq_stats['total'],'faqs_active'=>$faq_stats['active_supported'],'faqs_active_all_owner_types'=>$faq_stats['active_all'],'faqs_inactive'=>$faq_stats['inactive'],'faqs_active_legacy_hub'=>$faq_stats['type1_active'],'faqs_active_categories'=>$faq_stats['type2_active'],'faqs_active_products'=>$faq_stats['type3_active'],'faqs_active_unknown_owner'=>$faq_stats['other_active'],'faq_orphan_categories_active'=>$faq_stats['orphan_categories_active'],'faq_orphan_products_active'=>$faq_stats['orphan_products_active'],'faq_nonpublished_products_active'=>$faq_stats['nonpublished_products_active'],
            'seo_attributes_table_present'=>!empty($seo_attr_stats['table_present']),'seo_attributes_total'=>absint($seo_attr_stats['total']??0),'seo_attributes_published_rows'=>absint($seo_attr_stats['published_rows']??0),'seo_attributes_products_with_values'=>absint($seo_attr_stats['products_with_values']??0),'seo_attributes_empty_rows'=>absint($seo_attr_stats['empty_rows']??0),'seo_attributes_orphan_products'=>absint($seo_attr_stats['orphan_products']??0),'seo_attributes_nonpublished_products'=>absint($seo_attr_stats['nonpublished_products']??0),'seo_attributes_duplicate_excess_rows'=>absint($seo_attr_stats['duplicate_excess_rows']??0),'seo_attributes_reference_unverified'=>!empty($seo_attr_stats['reference_unverified']),
            'vocabulary_total'=>$vocab_total,'vocabulary_active'=>$vocab_count,'object_vocabulary_total'=>$ov_stats['total'],'object_vocabulary_managed_total'=>$ov_stats['managed_total'],'object_vocabulary_relations'=>$ov_stats['active_managed'],'object_vocabulary_inactive'=>$ov_stats['inactive_managed'],'object_vocabulary_orphan_vocabulary'=>$ov_stats['orphan_vocabulary'],'object_vocabulary_orphan_objects'=>$ov_stats['orphan_objects'],'object_vocabulary_by_type'=>$ov_stats['by_type'],'semantic_relations'=>count($relations),
            'products'=>$products,'index_products'=>$index_products,'product_posts'=>$product_posts,'categories'=>$categories,'category_content'=>$category_content,'category_products'=>$category_products,'editorial'=>$editorial,'faqs'=>$faqs,'relations'=>$relations,'seo_nodes'=>$seo_nodes,
        );
    }

    private static function audit_data_state($inventory) {
        $source_issues = array();
        $source_warnings = array();
        $index_issues = array();

        $published = absint($inventory['published_products'] ?? 0);
        $loaded = absint($inventory['canonical_products_loaded'] ?? 0);
        $categories = absint($inventory['categories_total'] ?? 0);
        $indexed = absint($inventory['indexed_products'] ?? 0);
        $overlap = absint($inventory['index_current_product_overlap'] ?? 0);
        $ratio = (float)($inventory['index_overlap_ratio'] ?? 0);
        $stale = absint($inventory['index_stale_rows'] ?? 0);
        $invalid_categories = absint($inventory['invalid_index_category_refs'] ?? 0);

        if ($published > 0 && $loaded !== $published) {
            $source_issues[] = 'canonical_products_incomplete';
            self::finding('data_canonical_product_mismatch','critical','system',0,'Fuente canonica de productos incompleta','wp_posts publica productos que el Auditor no ha podido cargar en su inventario canonico.',array('published'=>$published,'loaded'=>$loaded),'Revisar la consulta/copia de wp_posts antes de usar Academia.');
        }
        if ($published > 0 && $categories < 1) {
            $source_warnings[] = 'product_cat_unavailable';
            self::finding('data_categories_missing','high','system',0,'Taxonomia product_cat no disponible','Hay productos publicados pero no se han podido cargar categorias de producto.',array('published_products'=>$published,'categories_total'=>$categories),'Revisar terms/term_taxonomy/term_relationships. La auditoria de productos puede continuar, pero la de categorias queda incompleta.');
        }

        if (empty($inventory['index_table_present']) || $indexed < 1) {
            $index_issues[] = 'index_missing';
            self::finding('data_index_missing','high','system',0,'Indice del Dependiente no disponible','La capa derivada de busqueda no esta disponible. Esto no impide auditar wp_posts, taxonomias, Vocabulary ni FAQs.',array('indexed'=>$indexed),'Reindexar Dependiente antes de evaluar su recuperacion, pero no detener la auditoria de datos canonicos.');
        } elseif (!empty($inventory['index_verification']) && empty($inventory['index_verified'])) {
            $index_issues[] = 'index_not_verified';
            self::finding('data_index_exact_mismatch','high','system',0,'Indice del Dependiente no coincide con el universo indexable','La verificacion oficial por IDs detecta filas que faltan o sobran. Se registra como defecto de la capa derivada, no como defecto del catalogo.',array('published'=>$published,'indexed'=>$indexed,'matching'=>$overlap,'missing'=>absint($inventory['index_missing_products']??0),'extra'=>absint($inventory['index_extra_products']??0),'missing_ids'=>array_slice((array)($inventory['index_missing_ids']??array()),0,25),'extra_ids'=>array_slice((array)($inventory['index_extra_ids']??array()),0,25)),'Terminar/repetir el reindexado. No corregir productos para que coincidan con un indice viejo.');
        } elseif ($indexed > 0 && $ratio < 0.95) {
            $index_issues[] = 'index_post_mismatch';
            self::finding('data_index_post_mismatch','high','system',0,'Indice y productos canonicos no corresponden','Una parte importante de los IDs del indice no resuelve al inventario publicado actual.',array('indexed'=>$indexed,'matching_published_products'=>$overlap,'ratio'=>$ratio),'Reindexar la capa del Dependiente. La auditoria canonica continua.');
        }
        if ($stale > 0) {
            $index_issues[] = 'index_stale';
            self::finding('data_index_stale_content','high','system',0,'Indice con contenido desactualizado','Hay productos modificados despues de la version guardada en el indice.',array('stale_rows'=>$stale,'sample_product_ids'=>array_slice((array)($inventory['index_stale_ids']??array()),0,25)),'Reindexar Dependiente; no bloquear la revision de la fuente canonica.');
        }
        if ($invalid_categories > 0) {
            $index_issues[] = 'index_invalid_categories';
            self::finding('data_index_invalid_category_refs','high','system',0,'Indice con categorias inexistentes','La capa derivada conserva IDs de product_cat que ya no existen.',array('invalid_refs'=>$invalid_categories,'sample_category_ids'=>array_slice((array)($inventory['invalid_index_category_ids']??array()),0,25)),'Reindexar y revisar sincronizacion de terminos si persiste.');
        }

        $caps = array(
            'products'=>$loaded > 0 || $published === 0,
            'categories'=>$categories > 0,
            'editorial'=>true,
            'faqs'=>!empty($inventory['faqs_total']) || self::table_exists($GLOBALS['wpdb']->prefix.'seo_faq'),
            'vocabulary'=>absint($inventory['vocabulary_active'] ?? 0) > 0,
            'architecture'=>absint($inventory['semantic_relations'] ?? 0) > 0,
        );
        $can_source = empty($source_issues);
        $status = !$can_source ? 'incomplete_source' : ($index_issues ? 'ready_with_index_warnings' : 'ready');
        $message = !$can_source
            ? 'La fuente canonica tiene un problema estructural. Se ejecutan los chequeos que siguen siendo fiables por capa.'
            : ($index_issues ? 'La fuente canonica puede auditarse. El indice del Dependiente presenta incidencias separadas y no bloquea estos chequeos.' : 'Fuente canonica e indice disponibles: auditoria completa por capas.');

        return array(
            'status'=>$status,
            'source_status'=>$can_source ? 'ready' : 'incomplete',
            'index_status'=>$index_issues ? 'review' : 'ready',
            'can_source_audit'=>$can_source,
            'can_deep_audit'=>$can_source,
            'capabilities'=>$caps,
            'source_blockers'=>$source_issues,
            'source_warnings'=>$source_warnings,
            'index_issues'=>$index_issues,
            'blockers'=>$source_issues,
            'warnings'=>array_merge($source_warnings,$index_issues),
            'index_verified'=>!empty($inventory['index_verified']),
            'indexed_products'=>$indexed,
            'published_products'=>$published,
            'canonical_products_loaded'=>$loaded,
            'matching_product_ids'=>$overlap,
            'id_overlap_ratio'=>$ratio,
            'message'=>$message,
        );
    }

    private static function load_object_vocabulary() {
        global $wpdb;
        $ov = $wpdb->prefix . 'seo_object_vocabulary';
        $v = $wpdb->prefix . 'seo_vocabulary';
        if (!self::table_exists($ov) || !self::table_exists($v)) return array();
        $rows = (array)$wpdb->get_results(
            "SELECT ov.object_type,ov.object_id,v.id vocabulary_id,v.semantic_group,v.slug,v.label
             FROM {$ov} ov INNER JOIN {$v} v ON v.id=ov.vocabulary_id AND v.active=1
             WHERE ov.status=1 AND ov.object_type IN ('product','product_cat','post','page')
             ORDER BY ov.object_type,ov.object_id,v.semantic_group,v.id",
            ARRAY_A
        );
        $map = array();
        foreach ($rows as $r) {
            $key = sanitize_key((string)$r['object_type']) . ':' . absint($r['object_id']);
            $map[$key][] = array(
                'id'=>absint($r['vocabulary_id']),
                'group'=>sanitize_key((string)$r['semantic_group']),
                'slug'=>(string)$r['slug'],
                'label'=>(string)$r['label'],
            );
        }
        return $map;
    }

    private static function audit_index_state($inventory) {
        $indexed = absint($inventory['indexed_products'] ?? 0);
        $indexable = absint($inventory['indexable_products'] ?? 0);
        if (empty($inventory['index_table_present'])) return;
        if (!empty($inventory['index_verification'])) {
            if (empty($inventory['index_verified'])) {
                self::finding('index_incomplete','high','system',0,'Indice del Dependiente incompleto','La verificacion fuerte del propio Dependiente no pasa. Es una incidencia tecnica de la capa derivada.',array('indexed'=>$indexed,'indexable'=>$indexable,'missing'=>absint($inventory['index_missing_products']??0),'extra'=>absint($inventory['index_extra_products']??0)),'Completar y verificar el indice antes de evaluar retrieval/ranking. No frenar la auditoria de datos canonicos.');
            }
            return;
        }
        if ($indexable > 0 && $indexed !== $indexable) {
            self::finding('index_incomplete','high','system',0,'Indice del Dependiente incompleto',"El Auditor ve {$indexed} productos indexados frente a {$indexable} indexables.",array('indexed'=>$indexed,'indexable'=>$indexable),'Completar el indice antes de evaluar retrieval/ranking.');
        }
    }

    private static function audit_products($products, $object_vocabulary, $categories=array()) {
        $title_groups = self::build_product_identity_groups($products);
        $token_index = self::build_title_token_index($products);

        foreach ($products as $pid=>$p) {
            $title = trim((string)($p['title'] ?? ''));
            $excerpt = trim(wp_strip_all_tags((string)($p['excerpt'] ?? '')));
            $description = trim(wp_strip_all_tags(strip_shortcodes((string)($p['description'] ?? ''))));
            $seo_description = trim(wp_strip_all_tags((string)($p['seo_description'] ?? '')));
            $slug = trim((string)($p['slug'] ?? ''));
            $vocab = (array)($object_vocabulary['product:'.$pid] ?? array());

            if ($title === '') self::finding('product_missing_title','critical','product',$pid,'Producto sin titulo','El producto publicado no tiene titulo.',array(),'Completar el titulo antes de usarlo como fuente de Academia.');
            if ($excerpt === '') self::finding('product_missing_excerpt','medium','product',$pid,$title ?: "Producto #{$pid}",'Excerpt/descripcion corta vacia','', 'Completar una descripcion corta que identifique el mismo producto que el titulo.');
            if ($description === '') self::finding('product_missing_description','medium','product',$pid,$title ?: "Producto #{$pid}",'Descripcion larga vacia','', 'Completar una descripcion tecnica/comercial coherente con titulo, atributos y categoria.');
            if ($slug === '') self::finding('product_missing_slug','high','product',$pid,$title ?: "Producto #{$pid}",'Slug vacio','', 'Asignar un slug estable y descriptivo.');
            if (empty($p['categories'])) self::finding('product_without_category','high','product',$pid,$title ?: "Producto #{$pid}",'Producto sin categoria de catalogo','', 'Asignar una categoria canonica antes de usarlo en lecciones de arquitectura.');
            if (!$vocab) self::finding('product_without_vocabulary','medium','product',$pid,$title ?: "Producto #{$pid}",'Producto sin Vocabulary activo','', 'Revisar tipo, rol, aplicacion, plataforma y subtipo.');

            // Coherencia interna: detecta especialmente descripciones cruzadas/desplazadas.
            if ($title !== '' && self::strlen($excerpt) >= 35) {
                $score = self::text_alignment_ratio($title,$excerpt);
                if (count(self::identity_tokens($title)) >= 2 && $score < 0.20) {
                    self::finding('product_title_excerpt_drift','high','product',$pid,$title,'Titulo y excerpt parecen describir objetos distintos',array('alignment'=>round($score,3),'excerpt'=>self::snippet($excerpt,220)),'Revisar el registro fuente: es compatible con una columna desplazada o excerpt copiado de otro producto.');
                }
            }
            if ($title !== '' && self::strlen($description) >= 80) {
                $score = self::text_alignment_ratio($title,$description);
                if (count(self::identity_tokens($title)) >= 2 && $score < 0.16) {
                    $candidate = self::best_crossed_product_candidate($pid,$description,$products,$token_index);
                    if ($candidate) {
                        self::finding('product_description_identity_drift','high','product',$pid,$title,'La descripcion larga parece corresponder mejor a otro producto',array('alignment'=>round($score,3),'description'=>self::snippet($description,260),'possible_other_product'=>$candidate),'Comparar con la fuente original. Corregir solo si la descripcion pertenece realmente a otro ID.');
                    } elseif ($score < 0.08) {
                        self::finding('product_description_alignment_weak','low','product',$pid,$title,'Descripcion con poca coincidencia literal con el titulo',array('alignment'=>round($score,3),'description'=>self::snippet($description,260)),'Revisar solo como senal. Una descripcion generica o tecnica puede ser correcta aunque repita pocas palabras del titulo.');
                    }
                }
            }
            $seo_title = trim(wp_strip_all_tags((string)($p['seo_title'] ?? '')));
            if ($seo_title !== '' && $title !== '' && self::strlen($seo_title) >= 12) {
                $score = self::text_alignment_ratio($title,$seo_title);
                if ($score < 0.20) self::finding('product_seo_title_drift','medium','product',$pid,$title,'Titulo SEO poco alineado con el titulo canonico',array('alignment'=>round($score,3),'seo_title'=>self::snippet($seo_title,180)),'Comprobar que el titulo SEO corresponde a este producto y no a otra fila/importacion.');
            }
            if ($seo_description !== '' && $title !== '' && self::strlen($seo_description) >= 40) {
                $score = self::text_alignment_ratio($title,$seo_description);
                if ($score < 0.16) self::finding('product_seo_description_drift','medium','product',$pid,$title,'Meta description poco alineada con la identidad del producto',array('alignment'=>round($score,3),'seo_description'=>self::snippet($seo_description,220)),'Comprobar que la meta description corresponde a este producto y no a otra fila/importacion.');
            }

            $identity_text=$title.' '.$excerpt.' '.$description.' '.(string)($p['brand_name']??'').' '.implode(' ',self::vocab_labels($vocab)).' '.self::attribute_values_text($p['attributes']??array());
            foreach((array)($p['categories']??array()) as $cat)$identity_text.=' '.(string)($cat['name']??'');
            $identity_norm=self::norm($identity_text);
            foreach((array)($p['tags']??array()) as $tag){$tag_name=trim((string)($tag['name']??''));if($tag_name===''||self::generic_product_tag($tag_name))continue;if(!self::tag_supported_by_identity($tag_name,$identity_norm)){self::finding('product_tag_identity_drift','low','product',$pid,$title?:"Producto #{$pid}",'Etiqueta sin apoyo claro en la identidad del producto',array('tag'=>$tag_name),'Comprobar si la etiqueta describe realmente el producto. Es una senal de revision, no una orden de eliminarla.');break;}}

            foreach ((array)($p['attributes'] ?? array()) as $attr) {
                if (!is_array($attr)) continue;
                $label = trim((string)($attr['label'] ?? $attr['name'] ?? ''));
                $values = array_values(array_filter(array_map('trim',(array)($attr['values'] ?? array())),static function($v){return $v!=='';}));
                if ($label !== '' && !$values) self::finding('attribute_without_value','medium','product',$pid,$title ?: "Producto #{$pid}","Atributo sin valor: {$label}",array('attribute'=>$label),'Completar el valor o retirar el atributo vacio.');
                if ($label === '' && $values) self::finding('attribute_without_name','medium','product',$pid,$title ?: "Producto #{$pid}",'Atributo con valor pero sin nombre',array('values'=>array_slice($values,0,5)),'Corregir el nombre del atributo para que pueda interpretarse y compararse.');
                foreach($values as $av){if(self::attribute_value_suspicious($av)){self::finding('attribute_value_malformed','medium','product',$pid,$title ?: "Producto #{$pid}",'Valor de atributo con formato sospechoso',array('attribute'=>$label,'value'=>self::snippet($av,220)),'Revisar si el valor procede de una columna desplazada, serializacion, HTML o texto que no pertenece al atributo.');break;}}
            }

            $spec_conflicts = self::attribute_spec_conflicts($title,$p['attributes'] ?? array());
            if ($spec_conflicts) self::finding('product_attribute_title_conflict','high','product',$pid,$title ?: "Producto #{$pid}",'Atributos numericos contradicen el titulo',array('conflicts'=>$spec_conflicts),'Determinar que valor es correcto en la fuente original antes de entrenar o generar FAQs.');

            // No tratamos una diferencia TIPO/SUBTIPO producto↔categoria como incompatibilidad por si sola:
            // la categoria puede ser deliberadamente mas general. Los desajustes reales se detectan con
            // outliers y deriva agregada de categoria, que aportan contexto de cohorte y reducen falsos positivos.
        }

        // Identificadores estables repetidos entre IDs distintos: senal fuerte de duplicidad o importacion incorrecta.
        $sku_groups=array();$stable_groups=array();foreach($products as $pid=>$p){$sku=trim((string)($p['sku']??''));if($sku)$sku_groups[self::norm($sku)][absint($pid)]=true;foreach((array)($p['identifiers']??array()) as $ik=>$iv){$iv=trim((string)$iv);if($iv)$stable_groups[$ik.':'.self::norm($iv)][absint($pid)]=true;}}
        foreach($sku_groups as $value=>$ids_map)if(count($ids_map)>1)self::finding('source_duplicate_sku','high','product_group','sku:'.$value,'SKU duplicado','El mismo SKU aparece en varios product_id publicados.',array('product_ids'=>array_keys($ids_map),'sku'=>$value),'Revisar si son productos duplicados, variantes mal importadas o un SKU reutilizado incorrectamente.');
        foreach($stable_groups as $key=>$ids_map)if(count($ids_map)>1){list($kind,$value)=array_pad(explode(':',$key,2),2,'');self::finding('source_duplicate_identifier','high','product_group',$key,'Identificador comercial duplicado','El mismo identificador estable aparece en varios product_id publicados.',array('product_ids'=>array_keys($ids_map),'identifier_type'=>$kind,'value'=>$value),'Revisar GTIN/EAN/MPN/referencia/proveedor antes de consolidar o entrenar.');}

        // Identidad: mismo titulo no significa siempre duplicado. Distingue duplicado probable de variante legitima mal identificada.
        foreach ($title_groups as $norm=>$ids) {
            if (count($ids) < 2) continue;
            $analysis = self::analyze_identity_group($ids,$products);
            $sample_title = (string)($products[reset($ids)]['title'] ?? 'Titulos de producto duplicados');
            if (!empty($analysis['probable_duplicate'])) {
                self::finding('source_duplicate_product_candidate','high','product_group',$norm,$sample_title,'Posibles productos duplicados en la fuente',array('products'=>$analysis['products'],'count'=>count($ids),'differentiators'=>$analysis['differentiators'],'unique_differentiators'=>$analysis['unique_differentiators']??array()),'Revisar si son duplicados reales. No fusionar automaticamente: confirmar SKU/referencia/proveedor y contenido.');
            } else {
                $identity_severity=!empty($analysis['underdetermined_ids'])?'high':'medium';
                self::finding('source_owner_identity_ambiguous',$identity_severity,'product_group',$norm,$sample_title,'Identidad de producto ambigua: varios IDs comparten el mismo titulo',array('products'=>$analysis['products'],'count'=>count($ids),'differentiators'=>$analysis['differentiators'],'unique_differentiators'=>$analysis['unique_differentiators']??array(),'underdetermined_ids'=>$analysis['underdetermined_ids']??array()),!empty($analysis['underdetermined_ids'])?'No hay un diferenciador canonico suficiente para todos los IDs. Revisar duplicidad, modelo, SKU, referencia, medida o capacidad antes de Academia.':'Son variantes distinguibles por datos canonicos. Diferenciar el titulo cuando sea util y hacer que Academia incluya un diferenciador natural; nunca adivinar por titulo ni pasar el owner_id esperado al motor.');
            }
        }
    }

    private static function audit_categories($categories, $category_products, $object_vocabulary, $category_content) {
        foreach ($categories as $cid=>$term) {
            $count = count((array)($category_products[$cid] ?? array()));
            $node = (array)($category_content[$cid] ?? array());
            $excerpt = trim(wp_strip_all_tags((string)($node['excerpt'] ?? '')));
            $desc = trim(wp_strip_all_tags((string)($node['description'] ?? $term->description)));
            $name = (string)$term->name;
            $name_norm = self::norm($name);
            $slug_norm = self::norm(str_replace('-', ' ', (string)$term->slug));
            $vocab = (array)($object_vocabulary['product_cat:'.$cid] ?? array());
            $identity = trim($name.' '.implode(' ',self::vocab_labels($vocab)));

            if ($count === 0) self::finding('category_empty','low','category',$cid,$name,'Categoria sin productos publicados','', 'Comprobar si debe mantenerse por estrategia, recibir productos o retirarse de la arquitectura comercial.');
            if ($count > 0 && $excerpt === '') self::finding('category_missing_excerpt','low','category',$cid,$name,'Categoria con productos y excerpt vacio',array('products'=>$count),'Completar un resumen corto que delimite la familia.');
            if ($count > 0 && $desc === '') self::finding('category_missing_description','medium','category',$cid,$name,'Categoria con productos y descripcion vacia',array('products'=>$count),'Completar una descripcion que explique que productos pertenecen y cuales no.');
            if ($count > 0 && !$vocab) self::finding('category_without_vocabulary','medium','category',$cid,$name,'Categoria sin Vocabulary activo',array('products'=>$count),'Revisar Vocabulary para conectar categoria, productos y hubs con conceptos canonicos.');
            if ($count === 1) self::finding('category_single_product','low','category',$cid,$name,'Categoria con un solo producto',array('products'=>1),'Revisar si merece categoria propia o si debe fusionarse con una hermana semanticamente cercana.');
            if ($name_norm && $slug_norm && self::token_jaccard($name_norm,$slug_norm) < 0.34) self::finding('category_name_slug_drift','low','category',$cid,$name,'Nombre y slug poco alineados',array('slug'=>(string)$term->slug),'Revisar si el slug sigue representando la categoria actual.');

            if ($excerpt !== '' && self::strlen($excerpt) >= 35 && self::text_alignment_ratio($identity,$excerpt) < 0.16) {
                self::finding('category_excerpt_identity_drift','medium','category',$cid,$name,'Excerpt de categoria poco alineado con su identidad',array('excerpt'=>self::snippet($excerpt,220),'vocabulary'=>self::vocab_labels($vocab)),'Comprobar que el excerpt pertenece a esta categoria y no a otra fila/importacion.');
            }
            if ($desc !== '' && self::strlen($desc) >= 80 && self::text_alignment_ratio($identity,$desc) < 0.14) {
                self::finding('category_description_identity_drift','high','category',$cid,$name,'Descripcion de categoria posiblemente cruzada o desalineada',array('description'=>self::snippet($desc,260),'vocabulary'=>self::vocab_labels($vocab)),'Revisar la descripcion contra nombre, productos y Vocabulary antes de usar la categoria como fuente de Academia.');
            }
        }
    }

    private static function audit_editorial($editorial, $object_vocabulary) {
        $duplicates = array();
        foreach ($editorial as $row) {
            $id = absint($row['ID'] ?? 0);
            $type = sanitize_key((string)($row['post_type'] ?? ''));
            $title = trim((string)($row['post_title'] ?? ''));
            $slug = trim((string)($row['post_name'] ?? ''));
            $content = trim(wp_strip_all_tags(strip_shortcodes((string)($row['post_content'] ?? ''))));
            $excerpt = trim(wp_strip_all_tags((string)($row['post_excerpt'] ?? '')));
            $key = $type . ':' . $id;
            $vocab = (array)($object_vocabulary[$key] ?? array());
            $norm = self::norm($title);
            if ($norm) $duplicates[$type.':'.$norm][$id] = $title;

            if ($title === '') self::finding('editorial_missing_title','critical',$type,$id,"{$type} #{$id}",'Contenido editorial sin titulo','', 'Completar el titulo.');
            if ($slug === '') self::finding('editorial_missing_slug','high',$type,$id,$title ?: "{$type} #{$id}",'Contenido editorial sin slug','', 'Asignar un slug estable.');
            if (self::strlen($content) < 120) self::finding('editorial_thin_content','low',$type,$id,$title ?: "{$type} #{$id}",'Contenido editorial muy corto',array('characters'=>self::strlen($content)),'Revisar si aporta suficiente informacion para ser una fuente util.');
            if (!$vocab && self::strlen($content) >= 120) self::finding('editorial_without_vocabulary','medium',$type,$id,$title ?: "{$type} #{$id}",'Contenido editorial sin Vocabulary activo','', 'Asignar Vocabulary solo cuando represente realmente el tema y la intencion del contenido.');

            if ($vocab) {
                $haystack = self::norm($title . ' ' . $excerpt);
                $matched = false;
                foreach ($vocab as $v) {
                    $label = self::norm((string)($v['label'] ?? $v['slug'] ?? ''));
                    if ($label && self::meaningful_overlap($haystack, $label)) { $matched = true; break; }
                }
                if (!$matched && $haystack !== '') {
                    self::finding('editorial_vocab_title_drift','low',$type,$id,$title ?: "{$type} #{$id}",'Vocabulary sin reflejo claro en titulo/excerpt',array('vocabulary'=>array_slice(wp_list_pluck($vocab,'label'),0,12)),'Comprobar manualmente si el Vocabulary describe realmente el contenido. Es una senal semantica, no una prueba de error.');
                }
            }
        }
        foreach ($duplicates as $key=>$group) {
            if (count($group) < 2) continue;
            $parts = explode(':',$key,2);
            self::finding('duplicate_editorial_title','medium',$parts[0].'_group',$parts[1],'Titulos editoriales duplicados','Varios contenidos publicados tienen el mismo titulo normalizado.',array('ids'=>array_slice(array_keys($group),0,20),'count'=>count($group),'title'=>reset($group)),'Diferenciar el proposito de cada contenido o consolidar duplicados si compiten por la misma intencion.');
        }
    }

    private static function audit_faqs($faqs, $published_product_ids=array(), $categories=array(), $products=array(), $object_vocabulary=array(), $category_content=array()) {
        $seen = array();
        foreach ($faqs as $faq) {
            $id = absint($faq['id'] ?? 0);
            $ot = absint($faq['object_type'] ?? 0);
            $oid = absint($faq['object_id'] ?? 0);
            $q = trim(wp_strip_all_tags((string)($faq['question'] ?? '')));
            $answer = trim(wp_strip_all_tags((string)($faq['answer'] ?? '')));
            $answer_length = isset($faq['answer_length']) ? absint($faq['answer_length']) : self::strlen($answer);
            if (!in_array($ot,array(2,3),true)) {
                self::finding('faq_invalid_owner_type','critical','faq',$id,$q ?: "FAQ #{$id}",'FAQ fuera del modelo canonico de owner',array('object_type'=>$ot,'object_id'=>$oid),'Las FAQs deben pertenecer exclusivamente a producto o categoria.');
                continue;
            }
            $owner_ok = false; $owner_title = ''; $owner_text = '';
            if (3 === $ot) {
                $owner_ok = isset($published_product_ids[$oid]);
                if ($owner_ok && isset($products[$oid])) {
                    $p = (array)$products[$oid];
                    $owner_title = (string)($p['title'] ?? get_the_title($oid));
                    $owner_text = $owner_title.' '.(string)($p['excerpt']??'').' '.implode(' ',self::vocab_labels((array)($object_vocabulary['product:'.$oid]??array()))).' '.self::attribute_values_text($p['attributes']??array());
                }
            } else {
                $owner_ok = isset($categories[$oid]);
                if ($owner_ok) {
                    $owner_title = (string)$categories[$oid]->name;
                    $node = (array)($category_content[$oid]??array());
                    $owner_text = $owner_title.' '.(string)($node['excerpt']??'').' '.(string)($node['description']??$categories[$oid]->description).' '.implode(' ',self::vocab_labels((array)($object_vocabulary['product_cat:'.$oid]??array())));
                }
            }
            if (!$owner_ok) self::finding('faq_orphan_owner','critical','faq',$id,$q ?: "FAQ #{$id}",'FAQ con owner inexistente o incompatible',array('object_type'=>$ot,'object_id'=>$oid),'Reasignar por object_type + object_id desde la fuente correcta o retirar la FAQ. Nunca inferir owner por similitud de titulo.');
            if ($q === '') self::finding('faq_empty_question','high','faq',$id,"FAQ #{$id}",'FAQ sin pregunta','', 'Completar o retirar la FAQ.');
            if ($answer_length < 30) self::finding('faq_thin_answer','medium','faq',$id,$q ?: "FAQ #{$id}",'Respuesta FAQ vacia o demasiado corta',array('owner'=>$owner_title,'characters'=>$answer_length),'Revisar si responde de forma suficiente, concreta y verificable.');

            if ($owner_ok && $q !== '' && !self::generic_faq_question($q)) {
                $q_tokens = self::identity_tokens($q);
                if (count($q_tokens) >= 2 && self::text_alignment_ratio($q,$owner_text) < 0.08) {
                    self::finding('faq_owner_semantic_drift','medium','faq',$id,$q,'FAQ relacionada por ID pero semantica dudosa para su owner',array('owner_type'=>$ot,'owner_id'=>$oid,'owner_title'=>$owner_title,'question'=>$q),'La relacion tecnica por ID es valida; revisar si el contenido de la FAQ corresponde realmente a ese producto/categoria. No reasignar automaticamente.');
                }
            }

            $nq = self::norm($q);
            if ($nq) {
                $key = $ot.':'.$oid.':'.$nq;
                if (isset($seen[$key])) self::finding('duplicate_faq_same_owner','medium','faq',$id,$q,'FAQ duplicada para el mismo owner',array('owner_id'=>$oid,'duplicate_of'=>$seen[$key]),'Consolidar preguntas equivalentes para evitar respuestas redundantes.');
                else $seen[$key] = $id;
            }
        }
    }

    private static function audit_relations($relations, $categories, $category_products) {
        $architecture = array('category_to_secondary'=>array(),'secondary_to_primary'=>array(),'primary_to_cluster'=>array());
        $seen = array();
        foreach ($relations as $r) {
            $st = sanitize_key((string)($r['source_type'] ?? ''));
            $sid = absint($r['source_id'] ?? 0);
            $tt = sanitize_key((string)($r['target_type'] ?? ''));
            $tid = absint($r['target_id'] ?? 0);
            $rt = sanitize_key((string)($r['relation_type'] ?? ''));
            $key = $st.':'.$sid.'>'.$tt.':'.$tid.'#'.$rt;
            if (isset($seen[$key])) self::finding('duplicate_relation','low','relation',$key,'Relacion semantica duplicada','La misma relacion aparece mas de una vez.',array('relation'=>$key),'Eliminar duplicados solo tras verificar que no tengan distinta semantica interna.');
            $seen[$key] = true;

            if (!self::relation_object_exists($st,$sid)) self::finding('relation_missing_source','high','relation',$key,'Relacion con origen inexistente',"No se puede resolver {$st} #{$sid}.",array('relation_type'=>$rt),'Revisar o retirar la relacion rota.');
            if (!self::relation_object_exists($tt,$tid)) self::finding('relation_missing_target','high','relation',$key,'Relacion con destino inexistente',"No se puede resolver {$tt} #{$tid}.",array('relation_type'=>$rt),'Revisar o retirar la relacion rota.');

            if ('hub_secondary'===$st && 'product_cat'===$tt && $sid && $tid) $architecture['category_to_secondary'][$tid][$sid]=true;
            elseif ('hub_primary'===$st && 'hub_secondary'===$tt && $sid && $tid) $architecture['secondary_to_primary'][$tid][$sid]=true;
            elseif ('cluster'===$st && 'hub_primary'===$tt && $sid && $tid) $architecture['primary_to_cluster'][$tid][$sid]=true;
        }

        foreach ($category_products as $cid=>$pids) {
            if (!$pids || !isset($categories[$cid])) continue;
            $secondary_ids=array_keys((array)($architecture['category_to_secondary'][$cid]??array()));
            if (!$secondary_ids) {
                self::finding('category_without_secondary_hub','medium','category',$cid,(string)$categories[$cid]->name,'Categoria con productos sin Hub secundario',array('products'=>count($pids)),'Revisar si falta su relacion arquitectonica o si la categoria debe permanecer fuera de hubs por diseno.');
            } elseif(count($secondary_ids)>1){
                self::finding('category_multiple_secondary_hubs','low','category',$cid,(string)$categories[$cid]->name,'Categoria enlazada a varios Hubs secundarios',array('hub_secondary_ids'=>$secondary_ids),'Confirmar si la arquitectura permite multiples padres; si no, conservar solo el hub semanticamente correcto.');
            }
        }
        $secondary_used=array();foreach($architecture['category_to_secondary'] as $cid=>$ids)foreach(array_keys((array)$ids) as $sid)$secondary_used[$sid]=true;
        foreach(array_keys($secondary_used) as $sid){$parents=array_keys((array)($architecture['secondary_to_primary'][$sid]??array()));if(!$parents)self::finding('secondary_without_primary_hub','medium','hub_secondary',$sid,(string)(get_the_title($sid)?:"Hub secundario #{$sid}"),'Hub secundario sin Hub primario',array(),'Completar la cadena arquitectonica o revisar si el hub esta obsoleto.');elseif(count($parents)>1)self::finding('secondary_multiple_primary_hubs','low','hub_secondary',$sid,(string)(get_the_title($sid)?:"Hub secundario #{$sid}"),'Hub secundario enlazado a varios Hubs primarios',array('hub_primary_ids'=>$parents),'Confirmar si la arquitectura admite multiples padres; si no, revisar la relacion.');}
        $primary_used=array();foreach($architecture['secondary_to_primary'] as $sid=>$ids)foreach(array_keys((array)$ids) as $pid)$primary_used[$pid]=true;
        foreach(array_keys($primary_used) as $pid){$clusters=array_keys((array)($architecture['primary_to_cluster'][$pid]??array()));if(!$clusters)self::finding('primary_without_cluster','medium','hub_primary',$pid,(string)(get_the_title($pid)?:"Hub primario #{$pid}"),'Hub primario sin Cluster',array(),'Completar la cadena arquitectonica o revisar si el hub esta obsoleto.');elseif(count($clusters)>1)self::finding('primary_multiple_clusters','low','hub_primary',$pid,(string)(get_the_title($pid)?:"Hub primario #{$pid}"),'Hub primario enlazado a varios Clusters',array('cluster_ids'=>$clusters),'Confirmar si la arquitectura admite multiples padres; si no, revisar la relacion.');}
        return $architecture;
    }

    private static function audit_category_homogeneity($products, $categories, $category_products, $object_vocabulary=array()) {
        $profiles = array();
        $sizes = array(); foreach($category_products as $cid=>$m){ if(isset($categories[$cid])) $sizes[] = count((array)$m); }
        $median_size = self::median($sizes);
        $oversize_threshold = max(40,(int)ceil(max(1,$median_size)*3));

        foreach ($category_products as $cid=>$pid_map) {
            $pids = array_keys((array)$pid_map);
            $n = count($pids);
            if ($n < 1 || !isset($categories[$cid])) continue;
            $freq = array(); $attr_freq = array(); $tag_freq = array(); $per_product = array();
            foreach ($pids as $pid) {
                if (empty($products[$pid])) continue;
                $concepts = self::product_concepts($products[$pid],(array)($object_vocabulary['product:'.$pid]??array()));
                $per_product[$pid] = $concepts;
                foreach ($concepts as $c) $freq[$c] = ($freq[$c] ?? 0) + 1;
                foreach (self::attribute_keys($products[$pid]) as $ak) $attr_freq[$ak] = ($attr_freq[$ak] ?? 0) + 1;
                foreach (self::tag_keys($products[$pid]) as $tk) $tag_freq[$tk] = ($tag_freq[$tk] ?? 0) + 1;
            }
            arsort($freq,SORT_NUMERIC); arsort($attr_freq,SORT_NUMERIC); arsort($tag_freq,SORT_NUMERIC);
            $dominant = array(); foreach ($freq as $concept=>$count) if ($count/max(1,$n) >= 0.60) $dominant[$concept]=$count;
            $top = array_slice($freq,0,8,true);
            $coherence = $dominant ? min(1.0,array_sum(array_map(static function($c) use($n){return $c/max(1,$n);},$dominant))/max(1,count($dominant))) : 0.0;
            $profile = array('category_id'=>$cid,'category'=>(string)$categories[$cid]->name,'products'=>$n,'dominant_concepts'=>self::pretty_frequency($dominant,$n),'top_concepts'=>self::pretty_frequency($top,$n),'coherence'=>round($coherence,3),'flags'=>array());

            if ($n >= 8 && !$dominant) {
                $profile['flags'][]='heterogeneous';
                self::finding('category_heterogeneous','medium','category',$cid,(string)$categories[$cid]->name,'Categoria semanticamente heterogenea',array('products'=>$n,'top_concepts'=>self::pretty_frequency($top,$n)),'Revisar si mezcla familias distintas.');
            }
            if ($dominant && $n >= 5) {
                $outliers = array();
                foreach ($per_product as $pid=>$concepts) if (!array_intersect(array_keys($dominant),$concepts)) $outliers[]=$pid;
                if ($outliers && count($outliers) <= max(12,(int)floor($n*0.35))) {
                    $profile['flags'][]='product_outliers';
                    foreach (array_slice($outliers,0,8) as $pid) self::finding('product_category_outlier','medium','product',$pid,(string)($products[$pid]['title']??"Producto #{$pid}"),'Producto atipico respecto a su categoria',array('category_id'=>$cid,'category'=>(string)$categories[$cid]->name,'dominant_concepts'=>array_keys($dominant)),'Revisar categoria, Vocabulary y contenido del producto. No mover automaticamente.');
                }
            }

            $candidate = self::split_candidate($per_product,$n);
            if ($candidate) {
                $profile['flags'][]='split_candidate';
                self::finding('category_split_candidate','medium','category',$cid,(string)$categories[$cid]->name,'Posible candidata a division',array('products'=>$n,'cohorts'=>$candidate),'Valorar subcategorias si los grupos representan intenciones o tipos de producto distintos.');
            }
            if ($n >= $oversize_threshold && ($candidate || $coherence < 0.55)) {
                $profile['flags'][]='oversized';
                self::finding('category_oversized','medium','category',$cid,(string)$categories[$cid]->name,'Categoria sobredimensionada y con senales de mezcla interna',array('products'=>$n,'median_category_size'=>$median_size,'threshold'=>$oversize_threshold,'coherence'=>round($coherence,3),'split_candidate'=>$candidate),'Revisar division en categorias mas pequenas solo si cada cohorte tiene identidad/intencion propia.');
            }

            $cat_specific = self::specific_vocab_concepts((array)($object_vocabulary['product_cat:'.$cid]??array()));
            if ($cat_specific && $top) {
                $product_specific = array_values(array_filter(array_keys($top),static function($c){return 0===strpos($c,'tipo:')||0===strpos($c,'subtipo:');}));
                if ($product_specific && !array_intersect($cat_specific,$product_specific)) {
                    $profile['flags'][]='vocabulary_drift';
                    self::finding('category_product_vocabulary_drift','medium','category',$cid,(string)$categories[$cid]->name,'Vocabulary de categoria no coincide con los tipos dominantes de sus productos',array('category_concepts'=>$cat_specific,'product_concepts'=>$product_specific),'Revisar si la categoria, sus productos o el Vocabulary estan mal asignados.');
                }
            }

            if ($n <= 2) {
                $merge = self::category_merge_candidate($cid,$categories,$category_products,$object_vocabulary);
                if ($merge) {
                    $profile['flags'][]='merge_candidate';
                    self::finding('category_merge_candidate','low','category',$cid,(string)$categories[$cid]->name,'Categoria pequena con posible hermana para fusion',array('products'=>$n,'candidate'=>$merge),'Revisar manualmente si ambas categorias cubren la misma familia/intencion. No fusionar solo por tamano.');
                }
            }

            $common_attrs = array(); foreach($attr_freq as $ak=>$count) if($n>=5&&$count/$n>=0.75)$common_attrs[$ak]=$count;
            if ($common_attrs) { $emitted=0; foreach($pids as $pid){$keys=self::attribute_keys($products[$pid]??array());$missing=array_diff(array_keys($common_attrs),$keys);if($missing&&$emitted<3){self::finding('product_missing_common_attribute','low','product',$pid,(string)($products[$pid]['title']??"Producto #{$pid}"),'Faltan atributos comunes de su categoria',array('category'=>(string)$categories[$cid]->name,'missing'=>array_slice(array_values($missing),0,8)),'Comprobar si falta el dato, no aplica o esta registrado con otro nombre.');$emitted++;}} }
            $common_tags = array(); foreach($tag_freq as $tk=>$count) if($n>=6&&$count/$n>=0.80)$common_tags[$tk]=$count;
            if ($common_tags) { $emitted=0; foreach($pids as $pid){$keys=self::tag_keys($products[$pid]??array());$missing=array_diff(array_keys($common_tags),$keys);if($missing&&$emitted<2){self::finding('product_missing_common_tag','low','product',$pid,(string)($products[$pid]['title']??"Producto #{$pid}"),'Etiqueta comun ausente respecto a sus pares',array('category'=>(string)$categories[$cid]->name,'missing_tags'=>array_slice(array_values($missing),0,6)),'Comprobar si la etiqueta falta o el producto es una excepcion legitima.');$emitted++;}} }

            $profiles[]=$profile;
        }
        usort($profiles,static function($a,$b){$fa=count($a['flags']??array());$fb=count($b['flags']??array());if($fa!==$fb)return $fb<=>$fa;return ($b['products']??0)<=>($a['products']??0);});
        return $profiles;
    }

    private static function audit_architecture_sizing($architecture, $category_products) {
        $secondary = array(); $primary = array(); $clusters = array();
        foreach ($architecture['category_to_secondary'] as $cid=>$secs) {
            foreach (array_keys($secs) as $sid) {
                $secondary[$sid]['categories'][$cid]=true;
                foreach (array_keys((array)($category_products[$cid] ?? array())) as $pid) $secondary[$sid]['products'][$pid]=true;
            }
        }
        foreach ($architecture['secondary_to_primary'] as $sid=>$primaries) {
            foreach (array_keys($primaries) as $pid) {
                $primary[$pid]['secondary'][$sid]=true;
                foreach (array_keys((array)($secondary[$sid]['categories'] ?? array())) as $cid) $primary[$pid]['categories'][$cid]=true;
                foreach (array_keys((array)($secondary[$sid]['products'] ?? array())) as $product_id) $primary[$pid]['products'][$product_id]=true;
            }
        }
        foreach ($architecture['primary_to_cluster'] as $pid=>$cluster_ids) {
            foreach (array_keys($cluster_ids) as $cid) {
                $clusters[$cid]['primary'][$pid]=true;
                foreach (array_keys((array)($primary[$pid]['secondary'] ?? array())) as $sid) $clusters[$cid]['secondary'][$sid]=true;
                foreach (array_keys((array)($primary[$pid]['categories'] ?? array())) as $catid) $clusters[$cid]['categories'][$catid]=true;
                foreach (array_keys((array)($primary[$pid]['products'] ?? array())) as $product_id) $clusters[$cid]['products'][$product_id]=true;
            }
        }
        self::flag_size_outliers('hub_secondary',$secondary,'products');
        self::flag_size_outliers('hub_primary',$primary,'products');
        self::flag_size_outliers('cluster',$clusters,'products');
        return array(
            'hub_secondary'=>self::architecture_rows($secondary,'categories','products'),
            'hub_primary'=>self::architecture_rows($primary,'secondary','products'),
            'cluster'=>self::architecture_rows($clusters,'primary','products'),
        );
    }

    private static function audit_architecture_content($architecture,$categories) {
        $levels = array(
            'hub_secondary'=>array('map'=>(array)($architecture['category_to_secondary']??array()),'child'=>'category'),
            'hub_primary'=>array('map'=>(array)($architecture['secondary_to_primary']??array()),'child'=>'post'),
            'cluster'=>array('map'=>(array)($architecture['primary_to_cluster']??array()),'child'=>'post'),
        );
        foreach($levels as $type=>$cfg){
            $parents=array();
            foreach($cfg['map'] as $child_id=>$parent_ids)foreach(array_keys((array)$parent_ids) as $parent_id)$parents[$parent_id][$child_id]=true;
            foreach($parents as $id=>$child_map){
                $post=get_post(absint($id)); if(!($post instanceof WP_Post))continue;
                $title=trim((string)$post->post_title);$excerpt=trim(wp_strip_all_tags((string)$post->post_excerpt));$content=trim(wp_strip_all_tags(strip_shortcodes((string)$post->post_content)));
                if(self::strlen($excerpt.' '.$content)<100)self::finding($type.'_thin_description','medium',$type,$id,$title?:ucfirst(str_replace('_',' ',$type)).' #'.$id,'Hub con descripcion insuficiente',array('children'=>count($child_map),'characters'=>self::strlen($excerpt.' '.$content)),'Completar una descripcion que explique la familia y sus hijos antes de usar el hub como fuente de arquitectura.');
                $parent_text=$title.' '.$excerpt.' '.$content;$checked=0;$aligned=0;$children=array();
                foreach(array_keys($child_map) as $child_id){
                    $child_title='';
                    if('category'===$cfg['child']&&isset($categories[$child_id]))$child_title=(string)$categories[$child_id]->name;
                    elseif('post'===$cfg['child'])$child_title=(string)get_the_title(absint($child_id));
                    if(!$child_title)continue;$checked++;$score=self::text_alignment_ratio($child_title,$parent_text);if($score>=0.20)$aligned++;if(count($children)<12)$children[]=array('id'=>absint($child_id),'title'=>$child_title,'alignment'=>round($score,3));
                }
                if($checked>=3&&$aligned/$checked<0.20)self::finding($type.'_children_semantic_drift','low',$type,$id,$title?:ucfirst(str_replace('_',' ',$type)).' #'.$id,'El texto del hub refleja poco a sus hijos',array('checked'=>$checked,'aligned'=>$aligned,'children'=>$children),'Revisar si el hub esta mal enlazado o si su descripcion es demasiado generica/desactualizada.');
            }
        }
    }

    private static function split_attribute_values($value) {
        $values=is_array($value)?$value:(preg_split('/\s*[|;]\s*/u',trim((string)$value))?:array());$out=array();foreach((array)$values as $item){$item=trim(wp_strip_all_tags((string)$item));if($item!==''&&!in_array($item,$out,true))$out[]=$item;}return $out;
    }

    private static function append_product_attribute(&$product,$key,$label,$value,$source) {
        $key=sanitize_title((string)$key);$label=trim((string)$label);$value=trim((string)$value);if(!$key||$value==='')return;
        if(!isset($product['_attr_map']))$product['_attr_map']=array();
        $map_key=$key.'|'.self::norm($value);if(isset($product['_attr_map'][$map_key]))return;$product['_attr_map'][$map_key]=true;
        $product['attributes'][]=array('key'=>$key,'label'=>$label?:ucwords(str_replace(array('-','_'),' ',$key)),'values'=>array($value),'source'=>array($source));
    }

    private static function build_product_identity_groups($products) {
        $groups=array();foreach((array)$products as $pid=>$p){$n=self::norm((string)($p['title']??''));if($n)$groups[$n][]=absint($pid);}return $groups;
    }

    private static function analyze_identity_group($ids,$products) {
        $rows=array();$fingerprints=array();$skus=array();$stable_ids=array();$attr_matrix=array();$unique_by_owner=array();$underdetermined=array();
        foreach((array)$ids as $pid){$p=(array)($products[$pid]??array());$attrs=array();foreach((array)($p['attributes']??array()) as $a){$label=self::norm((string)($a['label']??$a['key']??''));$vals=array_values(array_filter(array_map('trim',(array)($a['values']??array()))));if($label&&$vals){$attrs[$label]=implode(' / ',$vals);$attr_matrix[$label][$pid]=$attrs[$label];}}ksort($attrs);$cats=array_map('absint',wp_list_pluck((array)($p['categories']??array()),'id'));sort($cats,SORT_NUMERIC);$sku=trim((string)($p['sku']??''));if($sku)$skus[$pid]=$sku;$ids_map=(array)($p['identifiers']??array());foreach($ids_map as $ik=>$iv){$iv=trim((string)$iv);if($iv)$stable_ids[$pid][$ik]=$iv;}$fingerprints[$pid]=hash('sha256',self::norm((string)($p['excerpt']??'').' '.(string)($p['description']??'')).'|'.wp_json_encode($cats).'|'.wp_json_encode($attrs));$rows[]=array('id'=>absint($pid),'sku'=>$sku,'identifiers'=>$ids_map,'categories'=>$cats,'attributes'=>$attrs);}
        $diff=array();$distinct_skus=array_values(array_unique(array_filter(array_values($skus))));if(count($distinct_skus)>1)$diff[]='sku';$distinct_stable=array();foreach($stable_ids as $pid=>$m)foreach($m as $k=>$v){$distinct_stable[$k][$v]=true;}foreach($distinct_stable as $k=>$vals)if(count($vals)>1)$diff[]='identifier:'.$k;foreach($attr_matrix as $label=>$vals){if(count(array_unique(array_values($vals)))>1)$diff[]=$label;}
        foreach((array)$ids as $pid){$u=self::identity_group_differentiators(absint($pid),(array)$ids,$products);$unique_by_owner[absint($pid)]=$u;if(!$u)$underdetermined[]=absint($pid);}
        $has_distinct_stable=false;foreach($distinct_stable as $vals)if(count($vals)>1){$has_distinct_stable=true;break;}$probable=count(array_unique(array_values($fingerprints)))===1&&count($distinct_skus)<=1&&!$has_distinct_stable;
        return array('probable_duplicate'=>$probable,'products'=>$rows,'differentiators'=>array_slice(array_values(array_unique($diff)),0,12),'unique_differentiators'=>$unique_by_owner,'underdetermined_ids'=>$underdetermined);
    }

    private static function identity_group_differentiators($owner_id,$ids,$products) {
        $owner=(array)($products[$owner_id]??array());$out=array();$sku=trim((string)($owner['sku']??''));if($sku){$unique=true;foreach($ids as $id){if($id==$owner_id)continue;if(trim((string)($products[$id]['sku']??''))===$sku){$unique=false;break;}}if($unique)$out[]=$sku;}foreach((array)($owner['identifiers']??array()) as $ik=>$iv){$iv=trim((string)$iv);if($iv==='')continue;$unique=true;foreach($ids as $id){if($id==$owner_id)continue;foreach((array)($products[$id]['identifiers']??array()) as $ov){if(trim((string)$ov)===$iv){$unique=false;break 2;}}}if($unique)$out[]=$iv;}
        $blocked_labels=array('marca','brand','rol','tipo','aplicacion','categoria','categorias');$by_owner=array();$coverage=array();
        foreach((array)$ids as $id){foreach((array)(($products[$id]['attributes']??array())) as $a){$label=self::norm((string)($a['label']??$a['key']??''));if(!$label||in_array($label,$blocked_labels,true))continue;$vals=array();foreach((array)($a['values']??array()) as $v){$v=trim((string)$v);$vn=self::norm($v);if(!$vn||self::strlen($vn)<2||self::generic_identity_value($vn))continue;$vals[$vn]=$v;}if($vals){$by_owner[$id][$label]=$vals;$coverage[$label][$id]=true;}}}
        foreach((array)($by_owner[$owner_id]??array()) as $label=>$values){if(count((array)($coverage[$label]??array()))<max(2,(int)ceil(count((array)$ids)*0.75)))continue;foreach($values as $vn=>$raw){$unique=true;foreach($ids as $id){if($id==$owner_id)continue;foreach((array)(($by_owner[$id][$label]??array())) as $other_vn=>$other_raw){if($other_vn===$vn){$unique=false;break 2;}}}if($unique)$out[]=$raw;}}
        return array_slice(array_values(array_unique($out)),0,12);
    }

    private static function question_disambiguates_owner($question,$owner_id,$ids,$products) {
        $q=self::norm($question);if(!$q)return array('resolved'=>false,'matched'=>'');foreach(self::identity_group_differentiators($owner_id,$ids,$products) as $d){$dn=self::norm($d);if($dn!==''&&false!==strpos($q,$dn))return array('resolved'=>true,'matched'=>$d);}return array('resolved'=>false,'matched'=>'');
    }

    private static function generic_identity_value($value) {
        $n=self::norm($value);return in_array($n,array('vevor','vevor es','satkit','herramienta','herramientas','accesorio','accesorios','equipamiento','producto','productos','manual'),true);
    }

    private static function build_category_identity_groups($categories) {
        $groups=array();foreach((array)$categories as $cid=>$term){$n=self::norm((string)($term->name??''));if($n)$groups[$n][]=absint($cid);}return $groups;
    }

    private static function classify_l6_faq_failure($expected,$raw_diag,$question,$products,$categories,$identity_groups,$category_identity_groups,$response_meta) {
        $ot=absint($expected['owner_type']??0);$oid=absint($expected['owner_id']??0);$fid=absint($expected['faq_id']??0);$raw_diag=sanitize_key((string)$raw_diag);$evidence=array('raw_diagnostic'=>$raw_diag,'owner_type'=>$ot,'owner_id'=>$oid,'faq_id'=>$fid);
        if(3===$ot){
            if(!$oid||!isset($products[$oid]))return array('diagnostic'=>'source_owner_missing','evidence'=>$evidence+array('reason'=>'product_owner_not_in_canonical_inventory'));
            $norm=self::norm((string)($products[$oid]['title']??$expected['owner_title']??''));$ids=(array)($identity_groups[$norm]??array());
            if(count($ids)>1){$dis=self::question_disambiguates_owner($question,$oid,$ids,$products);$evidence['same_title_owner_ids']=array_values(array_map('absint',$ids));$evidence['question_differentiator']=$dis;if(empty($dis['resolved']))return array('diagnostic'=>'academy_test_underdetermined','evidence'=>$evidence);}
        } elseif(2===$ot){
            if(!$oid||!isset($categories[$oid]))return array('diagnostic'=>'source_owner_missing','evidence'=>$evidence+array('reason'=>'category_owner_not_in_canonical_inventory'));
            $norm=self::norm((string)($categories[$oid]->name??$expected['owner_title']??''));$ids=(array)($category_identity_groups[$norm]??array());
            if(count($ids)>1){$evidence['same_name_category_ids']=array_values(array_map('absint',$ids));if(!self::question_disambiguates_category($question,$oid,$ids,$categories))return array('diagnostic'=>'academy_test_underdetermined','evidence'=>$evidence);}
        }

        $meta=is_array($response_meta)?$response_meta:array();$search=(array)($meta['search_diagnostic']??array());$related=(array)($meta['related_faq']??array());$owner_type_key=3===$ot?'product':(2===$ot?'product_cat':'');$owner_present=false;$expected_pos=0;
        foreach(array_values($related) as $idx=>$item){if(!is_array($item)||('faq'!==sanitize_key((string)($item['type']??'faq'))))continue;$item_owner=absint($item['owner_id']??0);$item_type=sanitize_key((string)($item['owner_type']??''));if($oid&&$item_owner===$oid&&(!$owner_type_key||$item_type===$owner_type_key))$owner_present=true;if($fid&&absint($item['id']??0)===$fid&&$oid===$item_owner&&(!$owner_type_key||$item_type===$owner_type_key))$expected_pos=$idx+1;}
        $diagnostic_owner_id=absint($search['faq_owner_id']??0);$diagnostic_owner_type=absint($search['faq_owner_type']??0);$owner_resolved=($oid&&$diagnostic_owner_id===$oid&&(!$ot||$diagnostic_owner_type===$ot));
        $clar=(array)($meta['clarification']??array());$evidence['owner_resolved_by_runtime']=$owner_resolved;$evidence['owner_present_in_related_faq']=$owner_present;$evidence['expected_faq_position']=$expected_pos;$evidence['runtime_owner_id']=$diagnostic_owner_id;$evidence['runtime_owner_type']=$diagnostic_owner_type;$evidence['clarification_reason']=sanitize_key((string)($clar['reason']??''));

        if($expected_pos>8||'faq_owner_ranking_gap'===$raw_diag)return array('diagnostic'=>'motor_faq_ranking_gap','evidence'=>$evidence);
        if($expected_pos>0&&$expected_pos<=8)return array('diagnostic'=>'academy_evaluation_mismatch','evidence'=>$evidence);
        if($owner_resolved||$owner_present)return array('diagnostic'=>'motor_faq_within_owner_retrieval_gap','evidence'=>$evidence);
        return array('diagnostic'=>'motor_explicit_owner_not_resolved','evidence'=>$evidence);
    }

    private static function question_disambiguates_category($question,$owner_id,$ids,$categories) {
        $q=self::norm($question);if(!$q)return false;$owner=$categories[$owner_id]??null;if(!$owner)return false;$slug=self::norm((string)($owner->slug??''));if($slug&&false!==strpos($q,$slug))return true;$parent=absint($owner->parent??0);if($parent&&isset($categories[$parent])){$pn=self::norm((string)$categories[$parent]->name);if($pn&&false!==strpos($q,$pn)){foreach($ids as $id){if($id==$owner_id)continue;$op=absint($categories[$id]->parent??0);if($op===$parent)return false;}return true;}}return false;
    }

    private static function generic_product_tag($tag) {
        $n=self::norm($tag);return in_array($n,array('vevor','vevor es','satkit','herramienta','herramientas','accesorio','accesorios','equipamiento','producto','productos'),true)||self::strlen($n)<3;
    }

    private static function tag_supported_by_identity($tag,$identity_norm) {
        $tag_norm=self::norm($tag);if($tag_norm===''||$identity_norm==='')return false;if(false!==strpos(' '.$identity_norm.' ',' '.$tag_norm.' '))return true;foreach(self::identity_tokens($tag_norm) as $t){if(false!==strpos(' '.$identity_norm.' ',' '.$t.' '))return true;}return false;
    }

    private static function attribute_value_suspicious($value) {
        $v=trim((string)$value);if($v==='')return false;if(self::strlen($v)>240)return true;if(false!==stripos($v,'<html')||false!==stripos($v,'<div')||false!==stripos($v,'http://')||false!==stripos($v,'https://'))return true;if(preg_match('/(^|[^a-z])(array|object|serialized)\s*[:(]/i',$v))return true;if(substr_count($v,';')>=6||substr_count($v,'|')>=8)return true;return false;
    }

    private static function build_title_token_index($products) {
        $idx=array();foreach((array)$products as $pid=>$p){foreach(self::identity_tokens((string)($p['title']??'')) as $t){if(count($idx[$t]??array())<80)$idx[$t][absint($pid)]=true;}}return $idx;
    }

    private static function best_crossed_product_candidate($self_id,$description,$products,$token_index) {
        $candidates=array();foreach(array_slice(self::identity_tokens($description),0,80) as $t){foreach(array_keys((array)($token_index[$t]??array())) as $pid){if($pid!=$self_id)$candidates[$pid]=true;if(count($candidates)>160)break 2;}}
        $best=array();$best_score=0.0;foreach(array_keys($candidates) as $pid){$title=(string)($products[$pid]['title']??'');$s=self::text_alignment_ratio($title,$description);if($s>$best_score){$best_score=$s;$best=array('product_id'=>absint($pid),'title'=>$title,'alignment'=>round($s,3));}}
        return $best_score>=0.55?$best:array();
    }

    private static function identity_tokens($text) {
        $tokens=array_values(array_unique(array_filter(explode(' ',self::norm($text)),array(__CLASS__,'identity_token'))));return $tokens;
    }

    private static function identity_token($t) {
        $t=(string)$t;if($t==='')return false;if(is_numeric($t))return strlen($t)>=2;if(strlen($t)<3)return false;$stop=array('para','como','este','esta','estos','estas','sobre','guia','todo','todos','todas','mejor','mejores','modelo','producto','productos','vevor','satkit','incl','con','sin','del','las','los','una','uno','unos','unas','que','por','mas','muy','nuevo','nueva','compatible','compatibles','elegir','debo','debe','sirve','usar','uso');return !in_array($t,$stop,true);
    }

    private static function text_alignment_ratio($anchor,$text) {
        $a=self::identity_tokens($anchor);$b=self::identity_tokens($text);if(!$a||!$b)return 0.0;$hits=count(array_intersect($a,$b));return $hits/max(1,count($a));
    }

    private static function snippet($text,$max=220) {
        $text=trim(preg_replace('/\s+/u',' ',wp_strip_all_tags((string)$text)));if(self::strlen($text)<=$max)return $text;return function_exists('mb_substr')?mb_substr($text,0,$max,'UTF-8').'…':substr($text,0,$max).'...';
    }

    private static function vocab_labels($vocab) {
        $out=array();foreach((array)$vocab as $v){$label=trim((string)($v['label']??$v['slug']??''));if($label)$out[]=$label;}return array_values(array_unique($out));
    }

    private static function vocab_concepts($vocab) {
        $out=array();foreach((array)$vocab as $v){$g=sanitize_key((string)($v['group']??''));$slug=sanitize_title((string)($v['slug']??$v['label']??''));if($g&&$slug)$out[]=$g.':'.$slug;}return array_values(array_unique($out));
    }

    private static function specific_vocab_concepts($vocab) {
        return array_values(array_filter(self::vocab_concepts($vocab),static function($c){return 0===strpos($c,'tipo:')||0===strpos($c,'subtipo:');}));
    }

    private static function attribute_values_text($attributes) {
        $out=array();foreach((array)$attributes as $a){foreach((array)($a['values']??array()) as $v)$out[]=(string)$v;}return implode(' ',$out);
    }

    private static function extract_specs($text) {
        $out=array();$text=strtolower(str_replace(',','.',remove_accents(wp_strip_all_tags((string)$text))));if(!preg_match_all('/\b([0-9]+(?:\.[0-9]+)?)\s*(kw|w|v|ah|mah|bar|psi|mpa|rpm|nm|kg|t)\b/i',$text,$m,PREG_SET_ORDER))return $out;foreach($m as $x){$unit=strtolower($x[2]);$value=(float)$x[1];if('kw'===$unit){$unit='w';$value*=1000;}if('mah'===$unit){$unit='ah';$value/=1000;}$out[$unit][]=round($value,4);}foreach($out as $u=>$vals)$out[$u]=array_values(array_unique($vals,SORT_REGULAR));return $out;
    }

    private static function attribute_spec_conflicts($title,$attributes) {
        $title_specs=self::extract_specs($title);if(!$title_specs)return array();$attr_text='';foreach((array)$attributes as $a){$attr_text.=' '.(string)($a['label']??'').' '.implode(' ',(array)($a['values']??array()));}$attr_specs=self::extract_specs($attr_text);$out=array();foreach($title_specs as $unit=>$tv){if(empty($attr_specs[$unit]))continue;$av=$attr_specs[$unit];$match=false;foreach($tv as $a)foreach($av as $b)if(abs($a-$b)<=max(0.01,abs($a)*0.01)){$match=true;break 2;}if(!$match)$out[]=array('unit'=>$unit,'title_values'=>$tv,'attribute_values'=>$av);}return array_slice($out,0,6);
    }

    private static function generic_faq_question($q) {
        $n=self::norm($q);foreach(array('cuando conviene elegir otra variante','que debo comparar antes de elegir este producto','que debo comprobar antes de elegir','que deberia valorar antes de decidirme','que debo preparar antes de utilizar') as $p)if(0===strpos($n,$p))return true;return count(self::identity_tokens($q))<2;
    }

    private static function category_merge_candidate($cid,$categories,$category_products,$object_vocabulary) {
        if(!isset($categories[$cid]))return array();$term=$categories[$cid];$parent=absint($term->parent??0);$base=self::specific_vocab_concepts((array)($object_vocabulary['product_cat:'.$cid]??array()));$best=array();$best_score=0.0;foreach($categories as $oid=>$other){if($oid==$cid||absint($other->parent??0)!==$parent)continue;$score=self::token_jaccard((string)$term->name,(string)$other->name);$other_v=self::specific_vocab_concepts((array)($object_vocabulary['product_cat:'.$oid]??array()));if($base&&$other_v){$inter=count(array_intersect($base,$other_v));$score=max($score,$inter/max(1,count(array_unique(array_merge($base,$other_v)))));}$count=count((array)($category_products[$oid]??array()));if($count>0&&$score>$best_score){$best_score=$score;$best=array('category_id'=>absint($oid),'category'=>(string)$other->name,'products'=>$count,'similarity'=>round($score,3));}}return $best_score>=0.30?$best:array();
    }

    /**
     * Preflight conductual independiente de Academia.
     * Ejecuta una muestra estable contra el mismo API que usa el cliente, pero:
     * - no escribe search log;
     * - no incluye academy_stage;
     * - no muta aprendizaje;
     * - no fuerza ensure_ready si la semantica no esta ya preparada.
     */
    private static function audit_behavior($inventory,$object_vocabulary,$data_state) {
        $out=array(
            'available'=>false,'status'=>'unavailable','reason'=>'','planned'=>0,'executed'=>0,
            'passed'=>0,'failed'=>0,'technical_errors'=>0,'fail_ratio'=>0,
            'by_kind'=>array(),'diagnostics'=>array(),'samples'=>array(),
            'customer_search_log_written'=>false,'academy_stage_used'=>false,
            'sample_mode'=>'deterministic_spread','max_probes'=>self::MAX_BEHAVIOR_PROBES,
        );
        $pre=self::behavior_engine_preflight($inventory,$data_state);
        if(empty($pre['ready'])){
            $out['reason']=(string)($pre['reason']??'Motor no preparado para pruebas de solo lectura.');
            self::finding('motor_behavior_probe_unavailable','medium','motor_probe','preflight','Pruebas del motor no disponibles',$out['reason'],$pre,'Preparar/verificar el motor e indice y repetir Auditor antes de iniciar Academia.');
            return $out;
        }
        $out['available']=true;
        $out['engine_preflight']=$pre;
        $candidates=self::behavior_probe_candidates($inventory,$object_vocabulary);
        $plan=array_merge(
            self::spread_sample((array)($candidates['cross']??array()),self::MAX_BEHAVIOR_CROSS_PROBES),
            self::spread_sample((array)($candidates['faq']??array()),self::MAX_BEHAVIOR_FAQ_PROBES),
            self::spread_sample((array)($candidates['category']??array()),self::MAX_BEHAVIOR_CATEGORY_PROBES),
            self::spread_sample((array)($candidates['editorial']??array()),self::MAX_BEHAVIOR_EDITORIAL_PROBES)
        );
        $plan=array_slice($plan,0,self::MAX_BEHAVIOR_PROBES);
        $out['planned']=count($plan);
        foreach($plan as $probe){
            $kind=sanitize_key((string)($probe['kind']??'other'))?:'other';
            if(!isset($out['by_kind'][$kind]))$out['by_kind'][$kind]=array('executed'=>0,'passed'=>0,'failed'=>0,'technical_errors'=>0);
            $result=self::execute_behavior_probe($probe);
            $out['executed']++;$out['by_kind'][$kind]['executed']++;
            if(!empty($result['technical_error'])){
                $out['technical_errors']++;$out['failed']++;$out['by_kind'][$kind]['technical_errors']++;$out['by_kind'][$kind]['failed']++;
            }elseif(!empty($result['passed'])){
                $out['passed']++;$out['by_kind'][$kind]['passed']++;
            }else{
                $out['failed']++;$out['by_kind'][$kind]['failed']++;
            }
            foreach((array)($result['diagnostics']??array()) as $diag){$diag=sanitize_key((string)$diag);if($diag)$out['diagnostics'][$diag]=1+absint($out['diagnostics'][$diag]??0);}
            if((empty($result['passed'])||!empty($result['diagnostics']))&&count($out['samples'])<60){
                $out['samples'][]=array('kind'=>$kind,'query'=>(string)($probe['query']??''),'status'=>!empty($result['technical_error'])?'technical_error':(!empty($result['passed'])?'pass':'fail'),'diagnostics'=>array_values((array)($result['diagnostics']??array())),'evidence'=>(array)($result['evidence']??array()));
            }
            self::behavior_findings($probe,$result);
        }
        $out['fail_ratio']=$out['executed']?round($out['failed']/$out['executed'],4):0;
        if($out['technical_errors']>0)$out['status']='technical_error';
        elseif($out['failed']>0)$out['status']='review_before_academy';
        else $out['status']='ready';
        if($out['failed']>0){
            $sev=$out['technical_errors']>0||$out['fail_ratio']>=0.15?'high':'medium';
            self::finding('motor_preflight_behavior_debt',$sev,'motor_probe','summary','Deuda conductual antes de Academia','La muestra del motor detecta fallos reproducibles antes de iniciar formacion.',array('executed'=>$out['executed'],'failed'=>$out['failed'],'technical_errors'=>$out['technical_errors'],'fail_ratio'=>$out['fail_ratio'],'diagnostics'=>$out['diagnostics']),'Revisar los patrones del motor y repetir Auditor. No usar Academia para compensar un defecto reproducible del motor.');
        }
        return $out;
    }

    private static function behavior_engine_preflight($inventory,$data_state) {
        global $wpdb;
        if(!class_exists('SEO_Dependiente_API'))return array('ready'=>false,'reason'=>'SEO_Dependiente_API no esta cargado.');
        if(!class_exists('SEO_Dependiente_Index')||empty($inventory['index_table_present'])||empty($inventory['index_verified']))return array('ready'=>false,'reason'=>'El indice de Dependiente no esta verificado.');
        if(!class_exists('SEO_Dependiente_Semantics'))return array('ready'=>false,'reason'=>'La capa semantica de Dependiente no esta cargada.');
        if(!method_exists('SEO_Dependiente_Semantics','table'))return array('ready'=>false,'reason'=>'No se puede verificar la tabla semantica sin mutarla.');
        $table=SEO_Dependiente_Semantics::table();
        if(!self::table_exists($table))return array('ready'=>false,'reason'=>'La tabla semantica no existe; Auditor no la crea en modo solo lectura.');
        $seed_count=absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE source='seed'"));
        $seed_option=(string)get_option('seo_dependiente_semantic_seed_version','');
        $seed_expected=defined('SEO_Dependiente_Semantics::SEED_VERSION')?(string)SEO_Dependiente_Semantics::SEED_VERSION:'';
        if($seed_count<1)return array('ready'=>false,'reason'=>'No hay seed semantico preparado; Auditor no lo genera en modo solo lectura.','seed_count'=>$seed_count);
        if($seed_expected!==''&&$seed_option!==$seed_expected)return array('ready'=>false,'reason'=>'El seed semantico requiere sincronizacion; Auditor evita ejecutar ensure_ready para no escribir.','seed_version'=>$seed_option,'expected_seed_version'=>$seed_expected);
        return array('ready'=>true,'index_verified'=>true,'semantic_seed_count'=>$seed_count,'semantic_seed_version'=>$seed_option,'can_source_audit'=>!empty($data_state['can_source_audit']));
    }

    private static function behavior_probe_candidates($inventory,$object_vocabulary) {
        $products=(array)($inventory['products']??array());$categories=(array)($inventory['categories']??array());$category_products=(array)($inventory['category_products']??array());$faqs=(array)($inventory['faqs']??array());$editorial=(array)($inventory['editorial']??array());
        $product_title_counts=array();foreach($products as $p){$n=self::norm((string)($p['title']??''));if($n)$product_title_counts[$n]=1+absint($product_title_counts[$n]??0);}
        $category_name_counts=array();foreach($categories as $term){$n=self::norm((string)($term->name??''));if($n)$category_name_counts[$n]=1+absint($category_name_counts[$n]??0);}
        $published_editorial=array();foreach($editorial as $row){$id=absint($row['ID']??0);$type=sanitize_key((string)($row['post_type']??''));if($id&&in_array($type,array('post','page'),true))$published_editorial[$type.':'.$id]=true;}
        $concepts=array();
        foreach((array)$object_vocabulary as $key=>$rows){
            if(!preg_match('/^(product|post|page):([0-9]+)$/',(string)$key,$m))continue;$type=$m[1];$id=absint($m[2]);
            if('product'===$type&&!isset($products[$id]))continue;if(in_array($type,array('post','page'),true)&&empty($published_editorial[$type.':'.$id]))continue;
            foreach((array)$rows as $v){$group=sanitize_key((string)($v['group']??''));$slug=sanitize_title((string)($v['slug']??''));if(!$slug||!in_array($group,array('tipo','subtipo','rol','aplicacion','plataforma'),true))continue;$ck=$group.':'.$slug;if(!isset($concepts[$ck]))$concepts[$ck]=array('group'=>$group,'slug'=>$slug,'label'=>(string)($v['label']??$slug),'products'=>array(),'editorial'=>array());if('product'===$type)$concepts[$ck]['products'][$id]=true;else{$response_type='page'===$type?'landing':$type;$concepts[$ck]['editorial'][$response_type.':'.$id]=true;}}
        }
        $cross=array();foreach($concepts as $ck=>$c){if(empty($c['products'])||empty($c['editorial']))continue;$cross[]=array('kind'=>'cross','key'=>$ck,'query'=>'Sobre "'.(string)$c['label'].'", que productos y contenidos relacionados tienes?','group'=>$c['group'],'slug'=>$c['slug'],'label'=>$c['label'],'expected_product_ids'=>array_map('absint',array_keys($c['products'])),'expected_editorial_refs'=>array_values(array_keys($c['editorial'])));}
        usort($cross,static function($a,$b){return strcmp((string)$a['key'],(string)$b['key']);});

        $faq=array();$seen_owner=array();foreach($faqs as $f){$ot=absint($f['object_type']??0);$oid=absint($f['object_id']??0);$fid=absint($f['id']??0);$q=trim((string)($f['question']??''));$answer_length=absint($f['answer_length']??0);if(!$fid||!$oid||!$q||$answer_length<1||!in_array($ot,array(2,3),true))continue;$owner=$ot.':'.$oid;if(isset($seen_owner[$owner]))continue;$label='';$owner_type='';if(3===$ot&&isset($products[$oid])){$label=(string)($products[$oid]['title']??'');$owner_type='product';if(absint($product_title_counts[self::norm($label)]??0)>1)continue;}elseif(2===$ot&&isset($categories[$oid])){$label=(string)($categories[$oid]->name??'');$owner_type='product_cat';if(absint($category_name_counts[self::norm($label)]??0)>1)continue;}if($label==='')continue;$seen_owner[$owner]=true;$prefix=3===$ot?'Sobre el producto "'.$label.'": ':'Sobre la categoria "'.$label.'": ';$faq[]=array('kind'=>'faq','key'=>'faq:'.$fid,'query'=>$prefix.$q,'faq_id'=>$fid,'owner_type'=>$ot,'owner_id'=>$oid,'owner_label'=>$label,'owner_key'=>$owner_type.':'.$oid);}
        usort($faq,static function($a,$b){return strcmp((string)$a['key'],(string)$b['key']);});

        $category=array();foreach($categories as $cid=>$term){$ids=array_map('absint',array_keys((array)($category_products[$cid]??array())));if(!$ids)continue;$name=(string)($term->name??'');if($name===''||absint($category_name_counts[self::norm($name)]??0)>1)continue;$category[]=array('kind'=>'category','key'=>'category:'.absint($cid),'query'=>'Busco productos de la categoria "'.$name.'"','category_id'=>absint($cid),'category_name'=>$name,'expected_product_ids'=>$ids);}
        usort($category,static function($a,$b){return strcmp((string)$a['key'],(string)$b['key']);});

        $editorial_probes=array();foreach($editorial as $row){$id=absint($row['ID']??0);$type=sanitize_key((string)($row['post_type']??''));$title=trim((string)($row['post_title']??''));if(!$id||$title===''||!in_array($type,array('post','page'),true))continue;$response_type='page'===$type?'landing':$type;$editorial_probes[]=array('kind'=>'editorial','key'=>$response_type.':'.$id,'query'=>'Que contenido relacionado tienes sobre '.$title.'?','expected_ref'=>$response_type.':'.$id,'title'=>$title);}
        usort($editorial_probes,static function($a,$b){return strcmp((string)$a['key'],(string)$b['key']);});
        return array('cross'=>$cross,'faq'=>$faq,'category'=>$category,'editorial'=>$editorial_probes);
    }

    private static function spread_sample($rows,$limit) {
        $rows=array_values((array)$rows);$n=count($rows);$limit=min(absint($limit),$n);if($limit<1)return array();if($limit===$n)return $rows;$out=array();$used=array();for($i=0;$i<$limit;$i++){$idx=(int)floor(($i+0.5)*$n/$limit);$idx=min($n-1,max(0,$idx));while(isset($used[$idx])&&$idx+1<$n)$idx++;while(isset($used[$idx])&&$idx>0)$idx--;if(isset($used[$idx]))continue;$used[$idx]=true;$out[]=$rows[$idx];}return $out;
    }

    public static function behavior_skip_search_log($should_log){return false;}
    public static function behavior_expose_search_diagnostic($expose=false){return true;}
    public static function behavior_disable_faq_text_fallback($allow=true){return false;}

    private static function execute_behavior_probe($probe) {
        $kind=sanitize_key((string)($probe['kind']??''));$request=new WP_REST_Request('POST','/seo-taxonomy/v1/search');$request->set_body_params(array('q'=>(string)($probe['query']??''),'mode'=>'need','page'=>1,'orderby'=>'relevance','session_id'=>'auditor:'.sanitize_key((string)($probe['key']??wp_generate_uuid4()))));
        add_filter('seo_dependiente_should_log_search',array(__CLASS__,'behavior_skip_search_log'),999,4);add_filter('seo_dependiente_expose_search_diagnostic',array(__CLASS__,'behavior_expose_search_diagnostic'),999,3);if('faq'===$kind)add_filter('seo_dependiente_faq_allow_text_fallback',array(__CLASS__,'behavior_disable_faq_text_fallback'),999,1);
        try{$response=SEO_Dependiente_API::search($request);}catch(Throwable $e){$response=new WP_Error('seo_auditor_behavior_exception',$e->getMessage());}finally{remove_filter('seo_dependiente_should_log_search',array(__CLASS__,'behavior_skip_search_log'),999);remove_filter('seo_dependiente_expose_search_diagnostic',array(__CLASS__,'behavior_expose_search_diagnostic'),999);if('faq'===$kind)remove_filter('seo_dependiente_faq_allow_text_fallback',array(__CLASS__,'behavior_disable_faq_text_fallback'),999);}
        if(is_wp_error($response))return array('passed'=>false,'technical_error'=>true,'diagnostics'=>array('technical_error'),'evidence'=>array('message'=>$response->get_error_message()));
        $data=$response instanceof WP_REST_Response?(array)$response->get_data():(is_array($response)?$response:array());if(!$data)return array('passed'=>false,'technical_error'=>true,'diagnostics'=>array('technical_error'),'evidence'=>array('message'=>'Respuesta vacia/no reconocida.'));
        if('faq'===$kind)return self::evaluate_behavior_faq($probe,$data);if('cross'===$kind)return self::evaluate_behavior_cross($probe,$data);if('category'===$kind)return self::evaluate_behavior_category($probe,$data);if('editorial'===$kind)return self::evaluate_behavior_editorial($probe,$data);return array('passed'=>true,'technical_error'=>false,'diagnostics'=>array(),'evidence'=>array());
    }

    private static function evaluate_behavior_faq($probe,$data) {
        $diag=array();$sd=(array)($data['search_diagnostic']??array());$resolved_id=absint($sd['faq_owner_id']??0);$resolved_type=absint($sd['faq_owner_type']??0);$expected_id=absint($probe['faq_id']??0);$owner_id=absint($probe['owner_id']??0);$owner_type=absint($probe['owner_type']??0);$rows=array_values((array)($data['related_faq']??array()));$exact_pos=0;$same_owner=0;foreach($rows as $i=>$r){if(absint($r['id']??0)===$expected_id&&0===$exact_pos)$exact_pos=$i+1;if(absint($r['owner_id']??0)===$owner_id&&self::behavior_owner_type_number($r['owner_type']??0)===$owner_type)$same_owner++;}
        if($resolved_id!==$owner_id||$resolved_type!==$owner_type)$diag[]='explicit_owner_resolution_gap';
        if($exact_pos<1)$diag[]='faq_owner_retrieval_gap';elseif($exact_pos>self::BEHAVIOR_TOP_LIMIT)$diag[]='faq_owner_ranking_gap';
        $clar=(array)($data['clarification']??array());if(!empty($clar['should_ask'])&&in_array(sanitize_key((string)($clar['reason']??'')),array('missing_object','missing_intent'),true))$diag[]='clarification_gap';
        $hard=array_intersect($diag,array('explicit_owner_resolution_gap','faq_owner_retrieval_gap','faq_owner_ranking_gap'));
        $e=array('expected_faq_id'=>$expected_id,'expected_owner_type'=>$owner_type,'expected_owner_id'=>$owner_id,'resolved_owner_type'=>$resolved_type,'resolved_owner_id'=>$resolved_id,'faq_position'=>$exact_pos,'same_owner_faqs'=>$same_owner,'returned_faqs'=>count($rows),'search_strategy'=>(string)($data['search_strategy']??''));
        return array('passed'=>empty($hard),'technical_error'=>false,'diagnostics'=>array_values(array_unique($diag)),'evidence'=>$e);
    }

    private static function evaluate_behavior_cross($probe,$data) {
        $diag=array();$expected_products=array_fill_keys(array_map('absint',(array)($probe['expected_product_ids']??array())),true);$expected_editorial=array_fill_keys((array)($probe['expected_editorial_refs']??array()),true);$product_pos=0;foreach(array_slice(array_values((array)($data['results']??array())),0,self::BEHAVIOR_TOP_LIMIT) as $i=>$r){if(isset($expected_products[absint($r['id']??0)])){$product_pos=$i+1;break;}}
        $editorial_pos=0;$editorial_any_pos=0;foreach(array_values((array)($data['related_editorial']??array())) as $i=>$r){$ref=sanitize_key((string)($r['type']??'')).':'.absint($r['id']??0);if(isset($expected_editorial[$ref])){if(!$editorial_any_pos)$editorial_any_pos=$i+1;if($i<self::BEHAVIOR_TOP_LIMIT&&!$editorial_pos)$editorial_pos=$i+1;}}
        $semantic=(array)($data['semantic']??array());$route_ok=false;foreach((array)($semantic['routes']??array()) as $r){if(sanitize_key((string)($r['target_group']??''))===sanitize_key((string)($probe['group']??''))&&sanitize_title((string)($r['target_slug']??''))===sanitize_title((string)($probe['slug']??''))){$route_ok=true;break;}}
        // La ausencia de una ruta explicita solo es fallo si tambien falta recuperacion efectiva.
        // Si producto y editorial correctos aparecen, la ruta es evidencia de diagnostico, no un bloqueo.
        if(!$route_ok){
            if(!$product_pos||!$editorial_pos)$diag[]='semantic_route_unresolved';
            else $diag[]='semantic_route_observation';
        }
        if(!$product_pos)$diag[]='retrieval_gap';if($product_pos&&!$editorial_pos){$diag[]=$editorial_any_pos>self::BEHAVIOR_TOP_LIMIT?'cross_ranking_gap':'cross_retrieval_gap';}
        $clar=(array)($data['clarification']??array());if(!empty($clar['should_ask'])&&($product_pos||$editorial_pos))$diag[]='clarification_gap';
        $hard=array_intersect($diag,array('semantic_route_unresolved','retrieval_gap','cross_retrieval_gap','cross_ranking_gap'));
        return array('passed'=>empty($hard),'technical_error'=>false,'diagnostics'=>array_values(array_unique($diag)),'evidence'=>array('concept'=>(string)($probe['key']??''),'product_position'=>$product_pos,'editorial_position'=>$editorial_pos,'editorial_any_position'=>$editorial_any_pos,'route_ok'=>$route_ok,'search_strategy'=>(string)($data['search_strategy']??''),'result_count'=>absint($data['total']??0),'related_editorial_count'=>count((array)($data['related_editorial']??array()))));
    }

    private static function evaluate_behavior_category($probe,$data) {
        $diag=array();$expected=array_fill_keys(array_map('absint',(array)($probe['expected_product_ids']??array())),true);$pos=0;foreach(array_slice(array_values((array)($data['results']??array())),0,self::BEHAVIOR_TOP_LIMIT) as $i=>$r){if(isset($expected[absint($r['id']??0)])){$pos=$i+1;break;}}if(!$pos)$diag[]='category_retrieval_gap';$clar=(array)($data['clarification']??array());if(!empty($clar['should_ask'])&&$pos)$diag[]='clarification_gap';return array('passed'=>$pos>0,'technical_error'=>false,'diagnostics'=>array_values(array_unique($diag)),'evidence'=>array('category_id'=>absint($probe['category_id']??0),'matched_product_position'=>$pos,'result_count'=>absint($data['total']??0),'search_strategy'=>(string)($data['search_strategy']??'')));
    }

    private static function evaluate_behavior_editorial($probe,$data) {
        $expected=(string)($probe['expected_ref']??'');$pos=0;foreach(array_values((array)($data['related_editorial']??array())) as $i=>$r){$ref=sanitize_key((string)($r['type']??'')).':'.absint($r['id']??0);if($ref===$expected){$pos=$i+1;break;}}$diag=array();if(!$pos)$diag[]='editorial_retrieval_gap';elseif($pos>self::BEHAVIOR_TOP_LIMIT)$diag[]='editorial_ranking_gap';$hard=array_intersect($diag,array('editorial_retrieval_gap','editorial_ranking_gap'));return array('passed'=>empty($hard),'technical_error'=>false,'diagnostics'=>$diag,'evidence'=>array('expected_ref'=>$expected,'editorial_position'=>$pos,'related_editorial_count'=>count((array)($data['related_editorial']??array())),'search_strategy'=>(string)($data['search_strategy']??'')));
    }

    private static function behavior_owner_type_number($value) {
        if(is_numeric($value))return absint($value);$v=sanitize_key((string)$value);if(in_array($v,array('product','producto'),true))return 3;if(in_array($v,array('product_cat','category','categoria'),true))return 2;return 0;
    }

    private static function behavior_findings($probe,$result) {
        $key=(string)($probe['key']??'');$title=(string)($probe['label']??$probe['owner_label']??$probe['category_name']??$key);$e=(array)($result['evidence']??array());
        if(!empty($result['technical_error'])){self::finding('motor_probe_technical_error','high','motor_probe',$key,$title,'La prueba del motor termino con error tecnico.',$e,'Corregir el error tecnico antes de iniciar Academia.');return;}
        foreach((array)($result['diagnostics']??array()) as $d){$d=sanitize_key((string)$d);$sev='medium';$headline='Fallo conductual reproducible del motor';$rec='Revisar el motor y repetir Auditor antes de iniciar Academia.';if('clarification_gap'===$d){$sev='low';$headline='Aclaracion potencialmente innecesaria';$rec='Revisar la politica de aclaracion; no usar Academia para forzar una respuesta memorizada.';}elseif('faq_owner_ranking_gap'===$d||'cross_ranking_gap'===$d){$sev='medium';$headline='Resultado canonico recuperado fuera de Top8';}elseif('explicit_owner_resolution_gap'===$d){$sev='high';$headline='Owner explicito no resuelto por el motor';}elseif('semantic_route_unresolved'===$d){$sev='medium';$headline='Concepto canonico sin ruta semantica efectiva';}elseif('semantic_route_observation'===$d){$sev='low';$headline='La recuperacion funciona aunque no se exponga una ruta semantica explicita';$rec='Observar la metadata de ruta; no bloquear Academia mientras la recuperacion canonica sea correcta.';}self::finding('motor_probe_'.$d,$sev,'motor_probe',$key,$title,$headline,$e,$rec);}
    }

    private static function build_pre_academy_gate($source_quality,$behavior) {
        $source=(string)($source_quality['status']??'ready');$motor=(string)($behavior['status']??'unavailable');$reasons=array();$status='ready';
        if(in_array($source,array('blocked','review_before_academy'),true)){$status='review_before_academy';$reasons[]='Hay deuda de fuente canonica.';}
        if('unavailable'===$motor){$status='review_before_academy';$reasons[]='No se pudo comprobar el comportamiento del motor.';}elseif('technical_error'===$motor){$status='review_before_academy';$reasons[]='Las pruebas del motor tienen errores tecnicos.';}elseif('review_before_academy'===$motor){$status='review_before_academy';$reasons[]='El motor reproduce fallos antes de Academia.';}
        if('ready'===$status)$reasons[]='Fuente y muestra conductual sin bloqueos reproducibles.';
        return array('status'=>$status,'source_status'=>$source,'behavior_status'=>$motor,'message'=>implode(' ',$reasons),'recommendation'=>'ready'===$status?'Se puede iniciar Academia.':'Revisar primero los patrones marcados por Auditor y repetir el preflight.');
    }

    private static function build_source_quality($inventory,$findings) {
        $out=array('status'=>'ready','blocking_entities'=>array(),'review_entities'=>array(),'identity_groups'=>0,'source_findings'=>0,'blocking_source_findings'=>0,'index_findings'=>0);
        foreach((array)$findings as $f){$root=(string)($f['root_cause']??'');$sev=(string)($f['severity']??'');$type=(string)($f['entity_type']??'');$id=$f['entity_id']??'';$code=(string)($f['code']??'');if('MOTOR/INDICE'===$root){$out['index_findings']++;continue;}if(false===strpos($root,'FUENTE'))continue;$out['source_findings']++;if(in_array($sev,array('critical','high'),true))$out['blocking_source_findings']++;if('source_owner_identity_ambiguous'===$code)$out['identity_groups']++;if(in_array($type,array('product','product_group','category','category_group','faq'),true)&&$id!==''){ $row=array('type'=>$type,'id'=>$id,'code'=>$code,'severity'=>$sev,'title'=>(string)($f['title']??''));if(in_array($sev,array('critical','high'),true))$out['blocking_entities'][]=$row;elseif('medium'===$sev)$out['review_entities'][]=$row;}}
        $out['blocking_entities']=array_slice($out['blocking_entities'],0,300);$out['review_entities']=array_slice($out['review_entities'],0,300);if($out['blocking_source_findings']>0)$out['status']='review_before_academy';if(!empty($inventory['published_products'])&&empty($inventory['canonical_products_loaded']))$out['status']='blocked';return $out;
    }

    private static function audit_academia($inventory=array(), $cross_source_identity=true) {
        if (!class_exists('SEO_Dependiente_Entrenador')) return array('available'=>false,'current'=>false);
        global $wpdb;
        $lt=SEO_Dependiente_Entrenador::lessons_table();$qt=SEO_Dependiente_Entrenador::questions_table();$rt=SEO_Dependiente_Entrenador::runs_table();
        if(!self::table_exists($lt)||!self::table_exists($qt)||!self::table_exists($rt))return array('available'=>false,'current'=>false);

        $products=$cross_source_identity?(array)($inventory['products']??array()):array();$categories=$cross_source_identity?(array)($inventory['categories']??array()):array();$identity_groups=$cross_source_identity?self::build_product_identity_groups($products):array();$category_identity_groups=$cross_source_identity?self::build_category_identity_groups($categories):array();
        $legacy_count=absint($wpdb->get_var("SELECT COUNT(*) FROM {$lt} WHERE lesson_key<>'' AND lesson_key NOT LIKE 'v2\\_%'"));
        $lesson_rows=(array)$wpdb->get_results("SELECT lesson_key,lesson_order,title,status,module_count,item_count,completed_items,snapshot_before,snapshot_after,source_signature,metadata,started_at,completed_at FROM {$lt} WHERE lesson_key LIKE 'v2\\_%' ORDER BY lesson_order ASC,id ASC",ARRAY_A);
        if(!$lesson_rows){if($legacy_count>0)self::finding('academy_legacy_state','high','lesson','legacy','Academia local desactualizada','Solo se han encontrado lecciones legacy.',array('legacy_lessons'=>$legacy_count),'Sincronizar Academia v2.');return array('available'=>true,'current'=>false,'source_cross_checked'=>(bool)$cross_source_identity,'legacy_lessons_ignored'=>$legacy_count,'lessons'=>array(),'systemic_signals'=>array(),'recurring_sources'=>array(),'source_diagnostics'=>array(),'final_exam'=>array());}

        $lessons=array();foreach($lesson_rows as $row){$key=sanitize_key((string)($row['lesson_key']??''));if(!$key)continue;$meta=self::decode_array($row['metadata']??'');$gate=is_array($meta['quality_gate']??null)?$meta['quality_gate']:array();$stored=is_array($meta['summary']??null)?$meta['summary']:array();$lessons[$key]=array('lesson_key'=>$key,'order'=>absint($row['lesson_order']??0),'title'=>(string)($row['title']??$key),'status'=>(string)($row['status']??''),'module_count'=>absint($row['module_count']??0),'item_count'=>absint($row['item_count']??0),'snapshot_before'=>absint($row['snapshot_before']??0),'snapshot_after'=>absint($row['snapshot_after']??0),'source_signature'=>(string)($row['source_signature']??''),'activated_rules_expected'=>absint($meta['activated_rules']??0),'quality_gate'=>array('passed'=>!empty($gate['passed']),'pass_any_ratio'=>isset($gate['pass_any_ratio'])?(float)$gate['pass_any_ratio']:null,'min_pass_any'=>isset($gate['min_pass_any'])?(float)$gate['min_pass_any']:null,'technical_errors'=>absint($gate['technical_errors']??0)),'stored_summary'=>array('total'=>absint($stored['total']??0),'answered'=>absint($stored['answered']??0),'pass_top1'=>absint($stored['pass_top1']??0),'pass_top3'=>absint($stored['pass_top3']??0),'pass_any'=>absint($stored['pass_any']??0),'failed'=>absint($stored['failed']??0),'errors'=>absint($stored['errors']??0)),'answered'=>0,'pass_any'=>0,'failed'=>0,'errors'=>0,'diag'=>array(),'reclassified_diag'=>array(),'question_types'=>array(),'expected_kinds'=>array(),'evidence_source'=>'none','started_at'=>(string)($row['started_at']??''),'completed_at'=>(string)($row['completed_at']??''));}

        $rows=(array)$wpdb->get_results("SELECT q.lesson_key,q.lesson_order,q.source_type,q.source_id,q.source_key,q.question_type,q.question,q.expected_json,r.status run_status,r.result_count,r.returned_count,r.evaluation_status,r.evaluation_json,r.top_results,r.response_meta FROM {$qt} q INNER JOIN (SELECT question_id,MAX(id) run_id FROM {$rt} WHERE question_id IS NOT NULL GROUP BY question_id) lr ON lr.question_id=q.id INNER JOIN {$rt} r ON r.id=lr.run_id WHERE q.enabled=1 AND q.lesson_key LIKE 'v2\\_%' ORDER BY q.lesson_order,q.id",ARRAY_A);
        $sources=array();$global_reclass=array();$global_reclass_samples=array();
        foreach($rows as $r){
            $key=sanitize_key((string)($r['lesson_key']??''));if(!$key||!isset($lessons[$key]))continue;$lessons[$key]['answered']++;$lessons[$key]['evidence_source']='runs';$qt_key=sanitize_key((string)($r['question_type']??'other'))?:'other';$lessons[$key]['question_types'][$qt_key]=absint($lessons[$key]['question_types'][$qt_key]??0)+1;
            $expected=self::decode_array($r['expected_json']??'');$kind=sanitize_key((string)($expected['kind']??'unknown'))?:'unknown';$lessons[$key]['expected_kinds'][$kind]=absint($lessons[$key]['expected_kinds'][$kind]??0)+1;
            $eval=sanitize_key((string)($r['evaluation_status']??''));$run=sanitize_key((string)($r['run_status']??''));$is_pass=0===strpos($eval,'pass_');$is_error=('error'===$run||'error'===$eval||'technical_error'===$eval);$is_fail=(!$is_pass&&!$is_error&&('fail'===$eval||'failed'===$eval));if($is_pass)$lessons[$key]['pass_any']++;if($is_fail)$lessons[$key]['failed']++;if($is_error)$lessons[$key]['errors']++;
            $ej=self::decode_array($r['evaluation_json']??'');$diag=sanitize_key((string)($ej['diagnostic_type']??''));if($diag)$lessons[$key]['diag'][$diag]=absint($lessons[$key]['diag'][$diag]??0)+1;

            if($cross_source_identity&&$is_fail&&'v2_l6_faq'===$key&&'faq'===$kind){
                $meta=self::decode_array($r['response_meta']??'');
                $classification=self::classify_l6_faq_failure($expected,$diag,(string)($r['question']??''),$products,$categories,$identity_groups,$category_identity_groups,$meta);
                $reclass=sanitize_key((string)($classification['diagnostic']??''));
                if($reclass){
                    $lessons[$key]['reclassified_diag'][$reclass]=absint($lessons[$key]['reclassified_diag'][$reclass]??0)+1;$global_reclass[$reclass]=absint($global_reclass[$reclass]??0)+1;
                    if(count($global_reclass_samples[$reclass]??array())<8)$global_reclass_samples[$reclass][]=array('owner_type'=>absint($expected['owner_type']??0),'owner_id'=>absint($expected['owner_id']??0),'faq_id'=>absint($expected['faq_id']??0),'owner_title'=>(string)($expected['owner_title']??''),'question'=>self::snippet((string)($r['question']??''),220),'evidence'=>(array)($classification['evidence']??array()));
                }
            }

            $sid=absint($r['source_id']??0);$skey=sanitize_key((string)($r['source_type']??'')).':'.($sid?:trim((string)($r['source_key']??'')));if($skey!==':'){if(!isset($sources[$skey]))$sources[$skey]=array('key'=>$skey,'label'=>$skey,'total'=>0,'failed'=>0,'lessons'=>array());$sources[$skey]['total']++;if($is_fail)$sources[$skey]['failed']++;$sources[$skey]['lessons'][$key]=true;}
        }

        foreach($lessons as $key=>&$lesson){if($lesson['answered']<1&&!empty($lesson['stored_summary']['answered'])){$lesson['answered']=absint($lesson['stored_summary']['answered']);$lesson['pass_any']=absint($lesson['stored_summary']['pass_any']);$lesson['failed']=absint($lesson['stored_summary']['failed']);$lesson['errors']=absint($lesson['stored_summary']['errors']);$lesson['evidence_source']='lesson_metadata';}}unset($lesson);
        $out=array('available'=>true,'current'=>true,'source_cross_checked'=>(bool)$cross_source_identity,'legacy_lessons_ignored'=>$legacy_count,'lessons'=>array(),'systemic_signals'=>array(),'recurring_sources'=>array(),'source_diagnostics'=>array(),'final_exam'=>array());
        foreach($global_reclass as $diag=>$count)$out['source_diagnostics'][]=array('key'=>$diag,'label'=>self::diagnostic_label($diag),'count'=>$count,'destination'=>self::diagnostic_destination($diag),'samples'=>array_values((array)($global_reclass_samples[$diag]??array())));
        usort($out['source_diagnostics'],static function($a,$b){return $b['count']<=>$a['count'];});
        foreach($out['source_diagnostics'] as $d){
            $diag=(string)($d['key']??'');$count=absint($d['count']??0);if(!$diag||!$count)continue;$sev=self::diagnostic_severity($diag);$recommendation='Revisar la evidencia antes de modificar fuente, Academia o motor.';
            if('academy_test_underdetermined'===$diag)$recommendation='No penalizar al motor: enriquecer la pregunta con un diferenciador natural (modelo, medida, SKU, capacidad o referencia) o corregir la identidad de la fuente si no existe diferenciador.';
            elseif('source_owner_missing'===$diag)$recommendation='Revisar el owner esperado: no debe entrenarse una FAQ contra un producto/categoria que ya no existe en la fuente canonica.';
            elseif('motor_explicit_owner_not_resolved'===$diag)$recommendation='Corregir el resolver de owner explicito. La consulta ya identifica un owner suficientemente y el motor no debe caer a otro objeto ni pedir una aclaracion generica.';
            elseif('motor_faq_within_owner_retrieval_gap'===$diag)$recommendation='El owner se resolvio; revisar recuperacion de FAQs exclusivamente dentro de object_type + object_id.';
            elseif('motor_faq_ranking_gap'===$diag)$recommendation='El owner y la FAQ existen; ajustar ranking dentro del mismo owner sin ampliar a otros owners.';
            elseif('academy_evaluation_mismatch'===$diag)$recommendation='Revisar el evaluador: la FAQ esperada aparece en Top8 guardado pero el run figura como fallo.';
            self::finding($diag,$sev,'lesson','v2_l6_faq','L6 - FAQs contextualizadas',self::diagnostic_label($diag),array('count'=>$count,'samples'=>(array)($d['samples']??array())),$recommendation);
        }

        foreach($lessons as $key=>$l){$diagnostics=array();foreach($l['diag'] as $diag=>$count)$diagnostics[]=array('key'=>$diag,'label'=>self::diagnostic_label($diag),'count'=>$count,'observed_layer'=>self::diagnostic_destination($diag));usort($diagnostics,static function($a,$b){return $b['count']<=>$a['count'];});$reclassified=array();foreach($l['reclassified_diag'] as $diag=>$count)$reclassified[]=array('key'=>$diag,'label'=>self::diagnostic_label($diag),'count'=>$count,'destination'=>self::diagnostic_destination($diag));usort($reclassified,static function($a,$b){return $b['count']<=>$a['count'];});unset($l['diag'],$l['reclassified_diag'],$l['stored_summary']);$l['diagnostics']=$diagnostics;$l['diagnostics_reclassified']=$reclassified;$l['pass_any_ratio']=$l['answered']?round($l['pass_any']/max(1,$l['answered']),4):(isset($l['quality_gate']['pass_any_ratio'])?(float)$l['quality_gate']['pass_any_ratio']:0);$l['debt']=$l['failed']>0;$l['promotion']='completed'===$l['status']?'promoted':('needs_training'===$l['status']?'blocked':'pending');$out['lessons'][]=$l;if('v2_l8_exam'===$key)$out['final_exam']=$l;
            $basis=$reclassified?:$diagnostics;$failed=absint($l['failed']);if($failed>=10&&$basis){foreach($basis as $d){$count=absint($d['count']);$ratio=$count/max(1,$failed);if($ratio>=0.35){$signal=array('lesson_key'=>$key,'title'=>(string)$l['title'],'diagnostic'=>(string)$d['key'],'label'=>(string)$d['label'],'count'=>$count,'failed'=>$failed,'ratio'=>round($ratio,3),'observed_layer'=>(string)($d['destination']??$d['observed_layer']??''));$out['systemic_signals'][]=$signal;self::finding('academy_systemic_pattern','medium','lesson',$key,(string)$l['title'],'Academia muestra un patron sistemico ya cruzado con la fuente',$signal,'Investigar la capa reclasificada antes de corregir datos o motor.');}}}
        }
        usort($out['lessons'],static function($a,$b){return $a['order']<=>$b['order'];});foreach($sources as $s){$lesson_count=count($s['lessons']);if($s['total']>=3&&$s['failed']>=2&&$lesson_count>=2){$s['lessons']=array_keys($s['lessons']);$s['failure_ratio']=round($s['failed']/max(1,$s['total']),3);$out['recurring_sources'][]=$s;}}usort($out['recurring_sources'],static function($a,$b){if($a['failed']!==$b['failed'])return $b['failed']<=>$a['failed'];return $b['total']<=>$a['total'];});$out['recurring_sources']=array_slice($out['recurring_sources'],0,100);
        return $out;
    }

    private static function audit_learning_state($academy) {
        global $wpdb;
        $snapshot=absint(get_option('seo_dependiente_knowledge_snapshot',0));
        $out=array('available'=>false,'snapshot'=>$snapshot,'active_academy_rules'=>0,'active_learned_rules'=>0,'staged_rules'=>0,'rules_by_lesson'=>array(),'raw_lesson_aliases'=>array(),'lessons'=>array());
        if(!class_exists('SEO_Dependiente_Semantics')||!SEO_Dependiente_Semantics::table_exists())return $out;
        $out['available']=true;
        $table=SEO_Dependiente_Semantics::table();
        $out['active_academy_rules']=absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE source='academy' AND active=1"));
        $out['active_learned_rules']=absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE source='learned' AND active=1"));
        $out['staged_rules']=absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE source='academy_stage'"));
        $rules=(array)$wpdb->get_results("SELECT rule_key,source,active,metadata FROM {$table} WHERE source IN ('academy','academy_stage') ORDER BY id ASC",ARRAY_A);
        foreach($rules as $r){
            $meta=self::decode_array($r['metadata']??'');
            $raw_lesson=sanitize_key((string)($meta['academy']['lesson']??''));
            if(!$raw_lesson&&preg_match('/^academy-(v2_l[0-9]+_[a-z0-9_]+)-/i',(string)($r['rule_key']??''),$m))$raw_lesson=sanitize_key($m[1]);
            if(!$raw_lesson&&preg_match('/^academy-(l[0-9]+_[a-z0-9_]+)-/i',(string)($r['rule_key']??''),$m))$raw_lesson=sanitize_key($m[1]);
            if(!$raw_lesson)continue;
            $lesson=self::canonical_lesson_key($raw_lesson);
            if(!$lesson)continue;
            $out['raw_lesson_aliases'][$lesson][$raw_lesson]=true;
            if('academy'===(string)($r['source']??'')&&!empty($r['active']))$out['rules_by_lesson'][$lesson]['active']=absint($out['rules_by_lesson'][$lesson]['active']??0)+1;
            if('academy_stage'===(string)($r['source']??''))$out['rules_by_lesson'][$lesson]['staged']=absint($out['rules_by_lesson'][$lesson]['staged']??0)+1;
        }
        foreach($out['raw_lesson_aliases'] as $key=>$aliases)$out['raw_lesson_aliases'][$key]=array_keys($aliases);

        $max_snapshot=0;
        foreach((array)($academy['lessons']??array()) as $lesson){
            $key=self::canonical_lesson_key((string)($lesson['lesson_key']??''));if(!$key)continue;
            $active=absint($out['rules_by_lesson'][$key]['active']??0);$staged=absint($out['rules_by_lesson'][$key]['staged']??0);$after=absint($lesson['snapshot_after']??0);$expected=absint($lesson['activated_rules_expected']??0);
            if('completed'===(string)($lesson['status']??''))$max_snapshot=max($max_snapshot,$after);
            $state=array(
                'lesson_key'=>$key,
                'status'=>(string)($lesson['status']??''),
                'snapshot_after'=>$after,
                'promoted_rules'=>$active,
                'staged_rules'=>$staged,
                'activated_rules_expected'=>$expected,
                'provenance_aliases'=>(array)($out['raw_lesson_aliases'][$key]??array()),
                'retained_ratio'=>$expected>0?round($active/$expected,4):null,
            );
            if('completed'===$state['status']&&$after<1){
                $state['integrity']='review';
                self::finding('learning_snapshot_missing','high','lesson',$key,(string)($lesson['title']??$key),'Leccion completada sin snapshot promocionado',$state,'Revisar la promocion de conocimiento antes de continuar.');
            } elseif('completed'===$state['status']&&$staged>0){
                $state['integrity']='review';
                self::finding('learning_stage_leftover','high','lesson',$key,(string)($lesson['title']??$key),'Quedan reglas academy_stage tras completar la leccion',$state,'Las reglas del aula no deben quedar pendientes despues de promocionar una leccion.');
            } elseif($expected>0&&$expected!==$active){
                $state['integrity']='observe';
                self::finding('learning_provenance_drift','low','lesson',$key,(string)($lesson['title']??$key),'La atribucion actual de reglas no coincide con el evento historico de promocion',$state,'No asumir perdida. El contador historico activated_rules mide la promocion de aquel momento; las reglas pueden haberse deduplicado, sustituido o conservar una clave legacy. Revisar solo si el comportamiento actual falla.');
            } else {
                $state['integrity']='ok';
            }
            $out['lessons'][]=$state;
        }
        $out['max_completed_snapshot']=$max_snapshot;
        if($max_snapshot>0&&$snapshot<$max_snapshot){self::finding('learning_snapshot_regressed','critical','system',0,'Snapshot de conocimiento regresado','El snapshot global es anterior al ultimo snapshot completado de Academia.',array('global_snapshot'=>$snapshot,'max_completed_snapshot'=>$max_snapshot),'No continuar la formacion hasta reconciliar el estado de conocimiento.');}
        return $out;
    }


    private static function build_academy_learning_chain($academy,$learning) {
        $lessons=(array)($academy['lessons']??array());$completed=0;$blocked=0;$questions=0;$passes=0;$fails=0;$errors=0;
        foreach($lessons as $l){if('completed'===(string)($l['status']??''))$completed++;if('needs_training'===(string)($l['status']??''))$blocked++;$questions+=absint($l['answered']??0);$passes+=absint($l['pass_any']??0);$fails+=absint($l['failed']??0);$errors+=absint($l['errors']??0);}
        $exam=(array)($academy['final_exam']??array());
        if(empty($academy['available'])||empty($academy['current']))$status='academy_v2_not_available';
        elseif($blocked>0)$status='training_blocked';
        elseif(!empty($exam)&&'completed'===(string)($exam['status']??''))$status='course_completed';
        else$status='training_in_progress';
        return array(
            'status'=>$status,
            'academy'=>array('current'=>!empty($academy['current']),'lessons'=>count($lessons),'completed'=>$completed,'blocked'=>$blocked,'legacy_ignored'=>absint($academy['legacy_lessons_ignored']??0),'source_cross_checked'=>!empty($academy['source_cross_checked'])),
            'trainer'=>array('evaluated_questions'=>$questions,'pass_any'=>$passes,'failed'=>$fails,'errors'=>$errors,'pass_any_ratio'=>$questions?round($passes/$questions,4):0),
            'student'=>array('snapshot'=>absint($learning['snapshot']??0),'active_academy_rules'=>absint($learning['active_academy_rules']??0),'active_learned_rules'=>absint($learning['active_learned_rules']??0),'staged_rules'=>absint($learning['staged_rules']??0)),
            'final'=>array('available'=>!empty($exam),'lesson_key'=>(string)($exam['lesson_key']??'v2_l8_exam'),'status'=>(string)($exam['status']??'pending'),'pass_any_ratio'=>isset($exam['pass_any_ratio'])?(float)$exam['pass_any_ratio']:null,'failed'=>absint($exam['failed']??0),'errors'=>absint($exam['errors']??0),'human_counter_test'=>'pending'),
        );
    }

    private static function build_learning_chain($data_state,$academy,$learning) {
        $lessons=(array)($academy['lessons']??array());$completed=0;$blocked=0;$questions=0;$passes=0;$fails=0;$errors=0;
        foreach($lessons as $l){if('completed'===(string)($l['status']??''))$completed++;if('needs_training'===(string)($l['status']??''))$blocked++;$questions+=absint($l['answered']??0);$passes+=absint($l['pass_any']??0);$fails+=absint($l['failed']??0);$errors+=absint($l['errors']??0);}
        $exam=(array)($academy['final_exam']??array());
        if(empty($data_state['can_source_audit']))$status='source_data_incomplete';
        elseif(empty($academy['current']))$status='academy_v2_not_available';
        elseif($blocked>0)$status='training_blocked';
        elseif(!empty($exam)&&'completed'===(string)($exam['status']??''))$status='course_completed';
        else$status='training_in_progress';
        return array(
            'status'=>$status,
            'source'=>array('state'=>(string)($data_state['source_status']??$data_state['status']??'unknown'),'message'=>(string)($data_state['message']??''),'can_audit'=>!empty($data_state['can_source_audit'])),
            'index'=>array('state'=>(string)($data_state['index_status']??'unknown'),'issues'=>(array)($data_state['index_issues']??array()),'verified'=>!empty($data_state['index_verified'])),
            'academy'=>array('current'=>!empty($academy['current']),'lessons'=>count($lessons),'completed'=>$completed,'blocked'=>$blocked,'legacy_ignored'=>absint($academy['legacy_lessons_ignored']??0),'source_diagnostics'=>(array)($academy['source_diagnostics']??array())),
            'trainer'=>array('evaluated_questions'=>$questions,'pass_any'=>$passes,'failed'=>$fails,'errors'=>$errors,'pass_any_ratio'=>$questions?round($passes/$questions,4):0),
            'student'=>array('snapshot'=>absint($learning['snapshot']??0),'active_academy_rules'=>absint($learning['active_academy_rules']??0),'active_learned_rules'=>absint($learning['active_learned_rules']??0),'staged_rules'=>absint($learning['staged_rules']??0)),
            'final'=>array('available'=>!empty($exam),'lesson_key'=>(string)($exam['lesson_key']??'v2_l8_exam'),'status'=>(string)($exam['status']??'pending'),'pass_any_ratio'=>isset($exam['pass_any_ratio'])?(float)$exam['pass_any_ratio']:null,'failed'=>absint($exam['failed']??0),'errors'=>absint($exam['errors']??0),'human_counter_test'=>'pending'),
        );
    }

    private static function diagnostic_label($key) {
        $map=array(
            'mastered'=>'Conocimiento resuelto','parser_gap'=>'Fallo de interpretacion','retrieval_gap'=>'Fallo de recuperacion','editorial_retrieval_gap'=>'Fallo de recuperacion editorial','faq_owner_retrieval_gap'=>'Fallo FAQ por owner','faq_owner_ranking_gap'=>'FAQ correcta fuera de Top8','cross_retrieval_gap'=>'Fallo de relacion cruzada','semantic_expansion_skipped'=>'Expansion semantica omitida','semantic_candidates_filtered'=>'Candidatos semanticos filtrados','semantic_route_unresolved'=>'Ruta semantica sin recuperacion efectiva','semantic_route_observation'=>'Ruta no explicita con recuperacion correcta','ranking_gap'=>'Fallo de ranking/filtro','clarification_gap'=>'Aclaracion innecesaria','curriculum_invalid'=>'Pregunta/evaluacion a revisar','technical_error'=>'Error tecnico','observed'=>'Observacion','unknown'=>'Sin diagnostico',
            'source_owner_identity_ambiguous'=>'Identidad de owner ambigua en la fuente','source_owner_missing'=>'Owner esperado no existe en la fuente canonica','academy_test_underdetermined'=>'Pregunta de Academia no determina un owner unico','motor_explicit_owner_not_resolved'=>'Motor no ancla un owner explicito','motor_faq_ranking_gap'=>'FAQ correcta fuera del Top8 dentro del owner','motor_faq_within_owner_retrieval_gap'=>'Owner resuelto pero FAQ esperada no recuperada','academy_evaluation_mismatch'=>'Evaluacion no coincide con los resultados guardados'
        );
        return $map[sanitize_key((string)$key)]??(string)$key;
    }

    private static function diagnostic_severity($key) {
        $key=sanitize_key((string)$key);$map=array('source_owner_missing'=>'high','academy_evaluation_mismatch'=>'high','academy_test_underdetermined'=>'medium','motor_explicit_owner_not_resolved'=>'medium','motor_faq_within_owner_retrieval_gap'=>'medium','motor_faq_ranking_gap'=>'low');return $map[$key]??'medium';
    }

    private static function diagnostic_destination($key) {
        $key=sanitize_key((string)$key);
        if (0===strpos($key,'source_')) return 'Fuente / Auditor';
        if (0===strpos($key,'motor_')) return 'Motor';
        if (0===strpos($key,'academy_test_')) return 'Academia / fuente';
        if(in_array($key,array('retrieval_gap','editorial_retrieval_gap','faq_owner_retrieval_gap','faq_owner_ranking_gap','cross_retrieval_gap','semantic_expansion_skipped','semantic_candidates_filtered','semantic_route_unresolved','ranking_gap','parser_gap'),true))return 'Motor';
        if(in_array($key,array('curriculum_invalid','clarification_gap'),true))return 'Evaluacion / Academia';
        if('technical_error'===$key)return 'Tecnico';
        return 'Aprendizaje / revisar evidencia';
    }

    private static function render_empty() {
        echo '<div class="seo-auditor__empty">';
        echo '<h3>Auditorias separadas</h3>';
        echo '<div class="seo-auditor__grid">';
        echo '<div><h4>Catalogo</h4><ul><li>Productos, identidad, categorias, atributos, Vocabulary y FAQs.</li><li>Categoria → Hub secundario → Hub primario → Cluster.</li><li>Estado del indice derivado y muestra del motor.</li><li>No carga lecciones, runs ni reglas de Academia.</li></ul></div>';
        echo '<div><h4>Academia / Estudiante</h4><ul><li>Lecciones v2 y calidad de los runs.</li><li>Entrenador, diagnosticos y patrones de fallo.</li><li>Reglas promocionadas, academy_stage y snapshots.</li><li>No recorre el inventario completo ni exporta perfiles del catalogo.</li></ul></div>';
        echo '</div><p><strong>Ninguna se ejecuta al cargar esta pagina.</strong> Pulsa solo la auditoria que necesites y descarga su JSON independiente.</p>';
        echo '</div>';
    }

    private static function render_subnav($view) {
        $base=add_query_arg(array('page'=>'seo-dependiente','tab'=>'auditor'),admin_url('admin.php'));
        echo '<nav class="seo-auditor__subnav">';
        foreach(array('summary'=>'Resumen','findings'=>'Hallazgos catalogo','categories'=>'Categorias y productos','architecture'=>'Hubs y relaciones','behavior'=>'Pruebas del motor','academia'=>'Academia / Entrenador','chain'=>'Estudiante / cadena') as $slug=>$label){
            $url=add_query_arg('audit_view',$slug,$base);echo '<a class="'.($view===$slug?'is-active':'').'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
        }
        echo '</nav>';
    }

    private static function render_summary($report,$history,$academy_report=array()) {
        echo '<div class="seo-auditor__scope-summary">';
        echo '<h3>Auditoria de catalogo</h3>';
        if(!$report){self::render_missing_scope('catalogo');}else{
            $s=(array)($report['summary']??array());$i=(array)($report['inventory']??array());$d=(array)($report['data_state']??array());$q=(array)($report['source_quality']??array());
            echo '<div class="seo-auditor__metrics">';
            self::metric('Hallazgos',$s['findings']??0);self::metric('Criticos',$s['critical']??0,'critical');self::metric('Prioridad alta',$s['high']??0,'high');self::metric('Revisar',$s['medium']??0,'medium');self::metric('Observar',$s['low']??0,'low');self::metric('Entidades afectadas',$s['entities_to_review']??0);
            echo '</div>';
            echo '<div class="notice '.(!empty($d['can_source_audit'])?'notice-success':'notice-error').' inline"><p><strong>Fuente canonica:</strong> '.esc_html((string)($d['source_status']??'desconocido')).' · <strong>Indice:</strong> '.esc_html((string)($d['index_status']??'desconocido')).' · '.esc_html((string)($d['message']??'')).'</p></div>';
            echo '<div class="notice '.('blocked'===($q['status']??'')||'review_before_academy'===($q['status']??'')?'notice-warning':'notice-info').' inline"><p><strong>Calidad de fuente:</strong> '.esc_html((string)($q['status']??'desconocido')).' · hallazgos fuente: '.esc_html(absint($q['source_findings']??0)).' · bloqueantes/alta: '.esc_html(absint($q['blocking_source_findings']??0)).' · grupos de identidad ambigua: '.esc_html(absint($q['identity_groups']??0)).'.</p></div>';
            $b=(array)($report['behavior_audit']??array());
            echo '<div class="seo-auditor__grid">';
            echo '<div class="postbox"><h3>Fuente canonica e indice derivado</h3><table class="widefat striped"><tbody>';
            foreach(array('published_products'=>'Productos publicados','canonical_products_loaded'=>'Productos canonicos cargados','indexable_products'=>'Productos indexables','hidden_products'=>'Excluidos hidden','indexed_products'=>'Productos indexados','index_missing_products'=>'Faltan en indice','index_extra_products'=>'Sobran en indice','index_stale_rows'=>'Filas desactualizadas','categories_total'=>'Categorias','invalid_index_category_refs'=>'Refs. categoria invalidas') as $k=>$label){echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html(number_format_i18n(absint($i[$k]??0))).'</td></tr>';}
            echo '<tr><th>Verificacion fuerte</th><td><strong>'.(!empty($i['index_verified'])?'OK':'NO').'</strong></td></tr></tbody></table></div>';
            echo '<div class="postbox"><h3>Motor de busqueda</h3><table class="widefat striped"><tbody><tr><th>Estado</th><td>'.esc_html((string)($b['status']??'no ejecutado')).'</td></tr><tr><th>Pruebas</th><td>'.esc_html(absint($b['executed']??0)).'</td></tr><tr><th>Fallos</th><td>'.esc_html(absint($b['failed']??0)).'</td></tr><tr><th>Errores tecnicos</th><td>'.esc_html(absint($b['technical_errors']??0)).'</td></tr></tbody></table></div>';
            echo '</div>';
            $patterns=(array)($report['systemic_patterns']??array());
            echo '<h3>Patrones sistemicos prioritarios</h3>';
            if(!$patterns){echo '<p>No hay patrones agregados.</p>';}else{echo '<table class="widefat striped"><thead><tr><th>Nivel</th><th>Patron</th><th>Capa probable</th><th>Casos</th><th>Accion</th></tr></thead><tbody>';foreach(array_slice($patterns,0,20) as $p){echo '<tr><td><span class="seo-auditor__badge is-'.esc_attr((string)$p['severity']).'">'.esc_html(self::severity_label((string)$p['severity'])).'</span></td><td><code>'.esc_html((string)$p['code']).'</code><div>'.esc_html((string)$p['headline']).'</div></td><td>'.esc_html((string)$p['root_cause']).'</td><td>'.esc_html(number_format_i18n(absint($p['count']))).'</td><td>'.esc_html((string)$p['recommendation']).'</td></tr>';}echo '</tbody></table>';}
            echo '<h3>Prioridad de revision</h3>';self::render_finding_table(array_slice((array)($report['findings']??array()),0,30));
            if($history){echo '<h3>Ultimas auditorias de catalogo</h3><table class="widefat striped"><thead><tr><th>Fecha</th><th>Hallazgos</th><th>Criticos</th><th>Alta</th><th>Revisar</th><th>Dependiente</th></tr></thead><tbody>';foreach(array_slice(array_reverse($history),0,12) as $h){echo '<tr><td>'.esc_html((string)($h['generated_at']??'')).'</td><td>'.esc_html(absint($h['findings']??0)).'</td><td>'.esc_html(absint($h['critical']??0)).'</td><td>'.esc_html(absint($h['high']??0)).'</td><td>'.esc_html(absint($h['medium']??0)).'</td><td>'.esc_html((string)($h['dependiente_version']??'')).'</td></tr>';}echo '</tbody></table>';}
            echo '<p class="description">Catalogo generado: '.esc_html((string)($report['generated_at']??'')).' · '.esc_html((string)($report['execution_seconds']??0)).' s · Auditor '.esc_html((string)($report['auditor_version']??'')).'.</p>';
        }

        echo '<hr><h3>Auditoria de Academia / Estudiante</h3>';
        if(!$academy_report){self::render_missing_scope('Academia / Estudiante');}else{
            $a=(array)($academy_report['academy']??array());$c=(array)($academy_report['learning_chain']??array());$st=(array)($academy_report['student']??array());$ss=(array)($academy_report['summary']??array());
            echo '<div class="seo-auditor__metrics">';
            self::metric('Lecciones',count((array)($a['lessons']??array())));self::metric('Completadas',$c['academy']['completed']??0);self::metric('Preguntas evaluadas',$c['trainer']['evaluated_questions']??0);self::metric('Fallos',$c['trainer']['failed']??0,'medium');self::metric('Snapshot estudiante',$st['snapshot']??0);self::metric('Hallazgos aprendizaje',$ss['findings']??0);
            echo '</div>';
            echo '<div class="seo-auditor__grid"><div class="postbox"><h3>Estado de aprendizaje</h3><table class="widefat striped"><tbody><tr><th>Estado</th><td><strong>'.esc_html((string)($c['status']??'—')).'</strong></td></tr><tr><th>Reglas Academia activas</th><td>'.esc_html(number_format_i18n(absint($st['active_academy_rules']??0))).'</td></tr><tr><th>Reglas aprendidas activas</th><td>'.esc_html(number_format_i18n(absint($st['active_learned_rules']??0))).'</td></tr><tr><th>academy_stage pendientes</th><td>'.esc_html(number_format_i18n(absint($st['staged_rules']??0))).'</td></tr><tr><th>Examen L8</th><td>'.esc_html((string)($c['final']['status']??'pendiente')).'</td></tr></tbody></table><p><a href="'.esc_url(add_query_arg(array('page'=>'seo-dependiente','tab'=>'auditor','audit_view'=>'academia'),admin_url('admin.php'))).'">Abrir Academia</a> · <a href="'.esc_url(add_query_arg(array('page'=>'seo-dependiente','tab'=>'auditor','audit_view'=>'chain'),admin_url('admin.php'))).'">Abrir Estudiante/cadena</a></p></div></div>';
            echo '<p class="description">Academia generada: '.esc_html((string)($academy_report['generated_at']??'')).' · '.esc_html((string)($academy_report['execution_seconds']??0)).' s. Esta auditoria no carga el inventario completo.</p>';
        }
        echo '</div>';
    }

    private static function render_findings($report) {
        $severity=sanitize_key((string)($_GET['severity']??''));$scope=sanitize_key((string)($_GET['scope']??''));$rows=array();
        foreach((array)($report['findings']??array()) as $f){if($severity&&($f['severity']??'')!==$severity)continue;if($scope&&($f['scope']??'')!==$scope)continue;$rows[]=$f;}
        echo '<h3>Hallazgos</h3><p class="description">Cada fila contiene evidencia y una recomendacion de revision; no es una orden de cambio.</p>';
        self::render_finding_table($rows);
    }

    private static function render_categories($report) {
        echo '<h3>Homogeneidad de categorias</h3><p class="description">La homogeneidad se estima con Vocabulary canonico de los productos. Los candidatos de division y los productos atipicos son hipotesis para revision manual.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Categoria</th><th>Productos</th><th>Coherencia</th><th>Conceptos dominantes</th><th>Senales</th></tr></thead><tbody>';
        foreach((array)($report['category_profiles']??array()) as $r){echo '<tr><td><strong>'.esc_html((string)$r['category']).'</strong><div class="description">#'.esc_html(absint($r['category_id'])).'</div></td><td>'.esc_html(absint($r['products'])).'</td><td>'.esc_html(number_format_i18n(100*(float)($r['coherence']??0),1)).'%</td><td>'.esc_html(self::frequency_text($r['dominant_concepts']??array())).'</td><td>'.esc_html(implode(', ',(array)($r['flags']??array())) ?: '—').'</td></tr>';}
        echo '</tbody></table>';
    }

    private static function render_architecture($report) {
        $p=(array)($report['architecture_profiles']??array());
        foreach(array('hub_secondary'=>'Hubs secundarios','hub_primary'=>'Hubs primarios','cluster'=>'Clusters') as $key=>$label){echo '<h3>'.esc_html($label).'</h3><table class="widefat striped"><thead><tr><th>Entidad</th><th>Hijos</th><th>Productos</th></tr></thead><tbody>';foreach((array)($p[$key]??array()) as $r){echo '<tr><td><strong>'.esc_html((string)$r['title']).'</strong><div class="description">#'.esc_html(absint($r['id'])).'</div></td><td>'.esc_html(absint($r['children'])).'</td><td>'.esc_html(absint($r['products'])).'</td></tr>';}echo '</tbody></table>';}
    }

    private static function render_behavior($report) {
        $b=(array)($report['behavior_audit']??array());$gate=(array)($report['pre_academy_gate']??array());
        echo '<h3>Pruebas del motor antes de Academia</h3>';
        echo '<p class="description">Muestra determinista contra el motor real de Dependiente. No usa academy_stage, no escribe log de clientes y no entrena.</p>';
        echo '<div class="notice '.('ready'===($gate['status']??'')?'notice-success':'notice-warning').' inline"><p><strong>Estado:</strong> '.esc_html((string)($gate['status']??'desconocido')).' - '.esc_html((string)($gate['message']??'')).'</p></div>';
        echo '<div class="seo-auditor__metrics">';
        self::metric('Pruebas',absint($b['executed']??0));self::metric('Correctas',absint($b['passed']??0));self::metric('Fallos',absint($b['failed']??0),'medium');self::metric('Errores tecnicos',absint($b['technical_errors']??0),'critical');
        echo '</div>';
        if(empty($b['available'])){echo '<p>No se pudieron ejecutar pruebas del motor: '.esc_html((string)($b['reason']??'motor no disponible')).'</p>';return;}
        echo '<table class="widefat striped"><thead><tr><th>Tipo</th><th>Ejecutadas</th><th>Correctas</th><th>Fallos</th></tr></thead><tbody>';
        foreach((array)($b['by_kind']??array()) as $kind=>$r){echo '<tr><td>'.esc_html((string)$kind).'</td><td>'.esc_html(absint($r['executed']??0)).'</td><td>'.esc_html(absint($r['passed']??0)).'</td><td>'.esc_html(absint($r['failed']??0)).'</td></tr>';}
        echo '</tbody></table>';
        $diag=(array)($b['diagnostics']??array());if($diag){arsort($diag,SORT_NUMERIC);echo '<h3>Diagnosticos del motor</h3><table class="widefat striped"><thead><tr><th>Diagnostico</th><th>Casos</th></tr></thead><tbody>';foreach($diag as $k=>$n){echo '<tr><td><code>'.esc_html((string)$k).'</code></td><td>'.esc_html(absint($n)).'</td></tr>';}echo '</tbody></table>';}
        $samples=(array)($b['samples']??array());if($samples){echo '<h3>Muestras</h3><table class="widefat striped"><thead><tr><th>Tipo</th><th>Consulta</th><th>Estado</th><th>Diagnosticos</th></tr></thead><tbody>';foreach(array_slice($samples,0,40) as $r){echo '<tr><td>'.esc_html((string)($r['kind']??'')).'</td><td>'.esc_html((string)($r['query']??'')).'</td><td>'.esc_html((string)($r['status']??'')).'</td><td>'.esc_html(implode(', ',(array)($r['diagnostics']??array())) ?: '-').'</td></tr>';}echo '</tbody></table>';}
    }

    private static function render_academia($report) {
        $a=(array)($report['academy']??array());if(empty($a['available'])){echo '<p>No hay datos de Academia disponibles.</p>';return;}
        if(empty($a['current'])){echo '<div class="notice notice-warning inline"><p><strong>No hay Academia v2 valida en este snapshot.</strong> Las lecciones legacy se ignoran para no mezclar cursos distintos.</p></div>';return;}
        echo '<h3>Lecciones Academia v2</h3><table class="widefat striped"><thead><tr><th>Leccion</th><th>Estado</th><th>Snapshot</th><th>Evaluadas</th><th>Pass any</th><th>Fallos</th><th>Gate</th><th>Evidencia</th></tr></thead><tbody>';
        foreach((array)($a['lessons']??array()) as $l){$gate=(array)($l['quality_gate']??array());echo '<tr><td>L'.esc_html(absint($l['order'])).' · '.esc_html((string)$l['title']).'<div class="description"><code>'.esc_html((string)$l['lesson_key']).'</code></div></td><td>'.esc_html((string)$l['status']).'</td><td>'.esc_html(absint($l['snapshot_before'])).' → '.esc_html(absint($l['snapshot_after'])).'</td><td>'.esc_html(absint($l['answered'])).'</td><td>'.esc_html(number_format_i18n(100*(float)($l['pass_any_ratio']??0),1)).'%</td><td>'.esc_html(absint($l['failed'])).'</td><td>'.(!empty($gate['passed'])?'OK':'—').'</td><td>'.esc_html((string)($l['evidence_source']??'none')).'</td></tr>';}
        echo '</tbody></table>';
        if(!empty($a['source_cross_checked'])){echo '<h3>Fallos L6 reclasificados contra la fuente</h3>';if(empty($a['source_diagnostics']))echo '<p>No hay fallos L6 reclasificados con evidencia disponible.</p>';else{echo '<table class="widefat striped"><thead><tr><th>Diagnostico</th><th>Casos</th><th>Destino</th><th>Muestra</th></tr></thead><tbody>';foreach((array)$a['source_diagnostics'] as $d){$samples=(array)($d['samples']??array());$sample=$samples?((string)($samples[0]['owner_title']??'').' #'.absint($samples[0]['owner_id']??0)):'—';echo '<tr><td><code>'.esc_html((string)$d['key']).'</code><div>'.esc_html((string)$d['label']).'</div></td><td>'.esc_html(absint($d['count'])).'</td><td>'.esc_html((string)$d['destination']).'</td><td>'.esc_html($sample).'</td></tr>';}echo '</tbody></table>';}}else{echo '<div class="notice notice-info inline"><p>Esta auditoria de Academia esta aislada del catalogo por diseno. Los defectos de productos, categorias, Vocabulary y owners se revisan con el boton <strong>Auditar catalogo</strong>.</p></div>';}
        echo '<h3>Patrones sistemicos</h3>';if(empty($a['systemic_signals']))echo '<p>No se han detectado concentraciones sistemicas con evidencia diagnostica disponible.</p>';else{echo '<table class="widefat striped"><thead><tr><th>Leccion</th><th>Diagnostico</th><th>Concentracion</th><th>Capa observada</th></tr></thead><tbody>';foreach($a['systemic_signals'] as $sig){echo '<tr><td>'.esc_html((string)$sig['title']).'</td><td>'.esc_html((string)$sig['label']).'</td><td>'.esc_html(number_format_i18n(100*(float)$sig['ratio'],1)).'% ('.esc_html(absint($sig['count'])).'/'.esc_html(absint($sig['failed'])).')</td><td>'.esc_html((string)$sig['observed_layer']).'</td></tr>';}echo '</tbody></table>';}
        echo '<h3>Fuentes que reaparecen en varias lecciones</h3>';if(empty($a['recurring_sources']))echo '<p>No hay fuentes con recurrencia suficiente en los runs actualmente conservados.</p>';else{echo '<table class="widefat striped"><thead><tr><th>Fuente</th><th>Evaluaciones</th><th>Fallos</th><th>Lecciones</th></tr></thead><tbody>';foreach($a['recurring_sources'] as $src){echo '<tr><td>'.esc_html((string)$src['label']).'<div class="description"><code>'.esc_html((string)$src['key']).'</code></div></td><td>'.esc_html(absint($src['total'])).'</td><td>'.esc_html(absint($src['failed'])).'</td><td>'.esc_html(implode(', ',(array)$src['lessons'])).'</td></tr>';}echo '</tbody></table>';}
    }

    private static function render_chain($report) {
        $c=(array)($report['learning_chain']??array());$student=(array)($report['student']??array());$academy=(array)($report['academy']??array());
        echo '<h3>Academia → Entrenador → Estudiante</h3><p class="description">Esta vista solo audita aprendizaje. La fuente canonica, categorias, Vocabulary e indice se revisan en la auditoria de catalogo independiente.</p>';
        echo '<div class="seo-auditor__chain">';
        self::render_chain_step('1','Academia',!empty($c['academy']['current'])?'v2 activa':'no disponible','Lecciones: '.absint($c['academy']['lessons']??0).' · completadas: '.absint($c['academy']['completed']??0).' · bloqueadas: '.absint($c['academy']['blocked']??0));
        self::render_chain_step('2','Entrenador','observado','Evaluadas: '.number_format_i18n(absint($c['trainer']['evaluated_questions']??0)).' · pass_any: '.number_format_i18n(100*(float)($c['trainer']['pass_any_ratio']??0),1).'% · fallos: '.number_format_i18n(absint($c['trainer']['failed']??0)));
        self::render_chain_step('3','Estudiante / conocimiento promocionado','snapshot '.absint($c['student']['snapshot']??0),'Reglas Academia activas: '.number_format_i18n(absint($c['student']['active_academy_rules']??0)).' · aprendidas supervisadas: '.number_format_i18n(absint($c['student']['active_learned_rules']??0)).' · academy_stage pendientes: '.number_format_i18n(absint($c['student']['staged_rules']??0)));
        $final=(array)($c['final']??array());$final_text=!empty($final['available'])?'L8 '.(string)($final['status']??'pendiente'):'L8 pendiente';
        self::render_chain_step('4','Resultado final',$final_text,isset($final['pass_any_ratio'])&&null!==$final['pass_any_ratio']?'Pass_any L8: '.number_format_i18n(100*(float)$final['pass_any_ratio'],1).'% · fallos: '.absint($final['failed']??0).' · despues: chequeo humano aleatorio':'Todavia no existe un examen cerrado L8 util; el chequeo humano queda pendiente.');
        echo '</div>';
        if(!empty($student['lessons'])){echo '<h3>Promocion por leccion</h3><table class="widefat striped"><thead><tr><th>Leccion</th><th>Estado</th><th>Snapshot</th><th>Reglas activas</th><th>academy_stage</th><th>Integridad</th></tr></thead><tbody>';foreach($student['lessons'] as $l){echo '<tr><td><code>'.esc_html((string)$l['lesson_key']).'</code></td><td>'.esc_html((string)$l['status']).'</td><td>'.esc_html(absint($l['snapshot_after'])).'</td><td>'.esc_html(absint($l['promoted_rules'])).'</td><td>'.esc_html(absint($l['staged_rules'])).'</td><td>'.esc_html((string)$l['integrity']).'</td></tr>';}echo '</tbody></table>';}
        if(!empty($academy['legacy_lessons_ignored']))echo '<p class="description">Se han ignorado '.esc_html(absint($academy['legacy_lessons_ignored'])).' lecciones legacy para no mezclarlas con Academia v2.</p>';
    }

    private static function render_chain_step($n,$title,$state,$detail) {
        echo '<div class="seo-auditor__chain-step"><span class="seo-auditor__chain-no">'.esc_html($n).'</span><div><strong>'.esc_html($title).'</strong><div class="seo-auditor__chain-state">'.esc_html($state).'</div><p>'.esc_html($detail).'</p></div></div>';
    }

    private static function render_finding_table($rows) {
        if (!$rows) { echo '<p>No hay hallazgos con este filtro.</p>'; return; }
        echo '<table class="widefat striped seo-auditor__findings"><thead><tr><th>Nivel</th><th>Entidad</th><th>Hallazgo</th><th>Capa probable</th><th>Evidencia / recomendacion</th></tr></thead><tbody>';
        foreach($rows as $f){$url=(string)($f['edit_url']??'');echo '<tr><td><span class="seo-auditor__badge is-'.esc_attr((string)$f['severity']).'">'.esc_html(self::severity_label((string)$f['severity'])).'</span></td><td><strong>'.esc_html((string)$f['title']).'</strong><div class="description">'.esc_html((string)$f['entity_type']).($f['entity_id']!==''?' · #'.esc_html((string)$f['entity_id']):'').'</div>'.($url?'<a href="'.esc_url($url).'">Abrir fuente</a>':'').'</td><td><strong>'.esc_html((string)$f['headline']).'</strong><div class="description"><code>'.esc_html((string)$f['code']).'</code></div><p>'.esc_html((string)$f['message']).'</p></td><td><strong>'.esc_html((string)($f['root_cause']??self::root_cause_for((string)$f['code'],(string)$f['entity_type']))).'</strong></td><td>'.(!empty($f['evidence'])?'<details><summary>Ver evidencia</summary><pre>'.esc_html(wp_json_encode($f['evidence'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)).'</pre></details>':'').'<p>'.esc_html((string)$f['recommendation']).'</p></td></tr>';}
        echo '</tbody></table>';
    }

    private static function metric($label,$value,$class='') {echo '<div class="seo-auditor__metric '.($class?'is-'.esc_attr($class):'').'"><strong>'.esc_html(number_format_i18n(absint($value))).'</strong><span>'.esc_html($label).'</span></div>';}

    private static function finding($code,$severity,$entity_type,$entity_id,$title,$headline,$evidence=array(),$recommendation='') {
        $code=sanitize_key((string)$code);$severity=sanitize_key((string)$severity);$entity_type=sanitize_key((string)$entity_type);
        if (count(self::$findings) >= self::MAX_FINDINGS) return;
        self::$rule_counts[$code]=absint(self::$rule_counts[$code]??0)+1;
        if(self::$rule_counts[$code] > self::MAX_ENTITY_FINDINGS_PER_RULE) return;
        $id=is_numeric($entity_id)?absint($entity_id):(string)$entity_id;
        self::$findings[]=array('code'=>$code,'severity'=>$severity,'scope'=>self::scope_for($code,$entity_type),'root_cause'=>self::root_cause_for($code,$entity_type),'entity_type'=>$entity_type,'entity_id'=>$id,'title'=>(string)$title,'headline'=>(string)$headline,'message'=>is_string($evidence)?$evidence:'','evidence'=>is_array($evidence)?$evidence:array(),'recommendation'=>(string)$recommendation,'edit_url'=>self::edit_url($entity_type,$id));
    }

    private static function audit_source_integrity($inventory) {
        $identity_groups = self::build_product_identity_groups((array)($inventory['products']??array()));
        $duplicate_title_groups = 0; $products_in_duplicate_titles = 0;
        foreach($identity_groups as $ids){ if(count($ids)>1){$duplicate_title_groups++;$products_in_duplicate_titles+=count($ids);} }
        $out = array(
            'canonical'=>array('published_products'=>absint($inventory['published_products']??0),'loaded_products'=>absint($inventory['canonical_products_loaded']??0),'categories'=>absint($inventory['categories_total']??0)),
            'product_identity'=>array('duplicate_title_groups'=>$duplicate_title_groups,'products_in_duplicate_title_groups'=>$products_in_duplicate_titles),
            'faq'=>array('total'=>absint($inventory['faqs_total']??0),'active_supported'=>absint($inventory['faqs_active']??0),'active_legacy_hub'=>absint($inventory['faqs_active_legacy_hub']??0),'active_unknown_owner'=>absint($inventory['faqs_active_unknown_owner']??0),'orphan_categories'=>absint($inventory['faq_orphan_categories_active']??0),'orphan_products'=>absint($inventory['faq_orphan_products_active']??0),'nonpublished_products'=>absint($inventory['faq_nonpublished_products_active']??0)),
            'attributes'=>array('table_present'=>!empty($inventory['seo_attributes_table_present']),'total'=>absint($inventory['seo_attributes_total']??0),'published_rows'=>absint($inventory['seo_attributes_published_rows']??0),'products_with_values'=>absint($inventory['seo_attributes_products_with_values']??0),'empty_rows'=>absint($inventory['seo_attributes_empty_rows']??0),'orphan_products'=>absint($inventory['seo_attributes_orphan_products']??0),'nonpublished_products'=>absint($inventory['seo_attributes_nonpublished_products']??0),'duplicate_excess_rows'=>absint($inventory['seo_attributes_duplicate_excess_rows']??0),'reference_unverified'=>!empty($inventory['seo_attributes_reference_unverified'])),
            'object_vocabulary'=>array('total'=>absint($inventory['object_vocabulary_total']??0),'managed_total'=>absint($inventory['object_vocabulary_managed_total']??0),'active_managed'=>absint($inventory['object_vocabulary_relations']??0),'inactive_managed'=>absint($inventory['object_vocabulary_inactive']??0),'orphan_objects'=>absint($inventory['object_vocabulary_orphan_objects']??0),'orphan_vocabulary'=>absint($inventory['object_vocabulary_orphan_vocabulary']??0)),
        );
        if($out['faq']['active_legacy_hub']>0)self::finding('faq_legacy_owner_active','high','system',0,'FAQs activas fuera del modelo actual','Persisten FAQs activas con object_type=1.',array('active_type1'=>$out['faq']['active_legacy_hub']),'Revisar/migrar estas FAQs; no inferir relaciones FAQ ↔ contenido editorial.');
        if($out['faq']['active_unknown_owner']>0)self::finding('faq_unknown_owner_type','critical','system',0,'FAQs con owner_type desconocido','Hay FAQs activas cuyo object_type no pertenece al modelo reconocido.',array('count'=>$out['faq']['active_unknown_owner']),'Corregir el owner_type desde la fuente canonica.');
        if($out['faq']['orphan_categories']>0||$out['faq']['orphan_products']>0)self::finding('faq_orphan_owner_systemic','high','system',0,'FAQs activas con propietario inexistente','Hay FAQs cuyo owner ya no resuelve a categoria/producto.',array('categories'=>$out['faq']['orphan_categories'],'products'=>$out['faq']['orphan_products']),'Revisar propietarios huerfanos. No reasignar automaticamente por similitud textual.');
        if(!empty($out['attributes']['reference_unverified']))self::finding('attribute_reference_model_unverified','medium','system',0,'Referencia de seo_attributes pendiente de verificar','La tabla contiene datos, pero product_id no enlaza directamente con wp_posts en esta instalacion. Auditor no los marca como huerfanos hasta conocer el contrato real.',array('rows'=>$out['attributes']['total']),'Verificar como resuelve seo_attributes_get_rows_for_products() o la capa canonica antes de auditar integridad referencial.');
        elseif($out['attributes']['orphan_products']>0)self::finding('attribute_orphan_product_systemic','high','system',0,'Atributos SEO apuntan a productos inexistentes','Hay filas de seo_attributes cuyo product_id no resuelve a un producto canonico.',array('rows'=>$out['attributes']['orphan_products']),'Revisar/eliminar relaciones huerfanas despues de confirmar la fuente.');
        if($out['attributes']['empty_rows']>0)self::finding('attribute_empty_rows_systemic','medium','system',0,'Atributos SEO vacios o mal formados','Hay filas sin tipo o sin valor.',array('rows'=>$out['attributes']['empty_rows']),'Corregir la importacion/origen y limpiar solo las filas verificadas.');
        if($out['attributes']['duplicate_excess_rows']>0)self::finding('attribute_duplicate_rows_systemic','medium','system',0,'Atributos SEO duplicados','Existen filas exactamente repetidas para el mismo producto, ambito, tipo y valor.',array('excess_rows'=>$out['attributes']['duplicate_excess_rows']),'Consolidar duplicados tras comprobar que no son eventos historicos necesarios.');
        if($out['object_vocabulary']['orphan_objects']>0||$out['object_vocabulary']['orphan_vocabulary']>0)self::finding('vocabulary_orphan_relation_systemic','high','system',0,'Relaciones Object/Vocabulary huerfanas','Existen asignaciones activas que no resuelven a objeto o Vocabulary canonico.',array('orphan_objects'=>$out['object_vocabulary']['orphan_objects'],'orphan_vocabulary'=>$out['object_vocabulary']['orphan_vocabulary']),'Revisar integridad referencial antes de corregir semantica.');
        return $out;
    }

    private static function build_systemic_patterns($findings) {
        $groups=array();
        foreach((array)$findings as $f){
            $code=(string)($f['code']??'');if(!$code)continue;
            if(!isset($groups[$code]))$groups[$code]=array('code'=>$code,'severity'=>(string)($f['severity']??'info'),'scope'=>(string)($f['scope']??''),'root_cause'=>(string)($f['root_cause']??self::root_cause_for($code,(string)($f['entity_type']??''))),'count'=>0,'headline'=>(string)($f['headline']??''),'recommendation'=>(string)($f['recommendation']??''),'sample_entities'=>array());
            $groups[$code]['count']++;
            if(count($groups[$code]['sample_entities'])<8)$groups[$code]['sample_entities'][]=array('type'=>(string)($f['entity_type']??''),'id'=>$f['entity_id']??'','title'=>(string)($f['title']??''));
        }
        foreach($groups as $code=>&$g){$g['count']=max(absint($g['count']??0),absint(self::$rule_counts[$code]??0));}unset($g);
        $rows=array_values($groups);$weights=array('critical'=>4,'high'=>3,'medium'=>2,'low'=>1,'info'=>0);
        usort($rows,static function($a,$b)use($weights){$wa=$weights[$a['severity']]??0;$wb=$weights[$b['severity']]??0;if($wa!==$wb)return $wb<=>$wa;if($a['count']!==$b['count'])return $b['count']<=>$a['count'];return strcmp($a['code'],$b['code']);});
        return array_slice($rows,0,self::MAX_SYSTEMIC_PATTERNS);
    }

    private static function build_action_plan($patterns) {
        $out=array();
        foreach(array_slice((array)$patterns,0,12) as $p){$out[]=array('priority'=>(string)($p['severity']??'info'),'root_cause'=>(string)($p['root_cause']??''),'code'=>(string)($p['code']??''),'cases'=>absint($p['count']??0),'action'=>(string)($p['recommendation']??''));}
        return $out;
    }

    private static function canonical_lesson_key($key) {
        $key=sanitize_key((string)$key);if(!$key)return '';
        if(0===strpos($key,'v2_l'))return $key;
        if(preg_match('/^l([0-9]+)_(.+)$/',$key,$m))return sanitize_key('v2_l'.$m[1].'_'.$m[2]);
        return $key;
    }

    private static function root_cause_for($code,$type) {
        $code=sanitize_key((string)$code);$type=sanitize_key((string)$type);
        if(0===strpos($code,'motor_'))return 'MOTOR';
        if(0===strpos($code,'data_index_')||0===strpos($code,'index_'))return 'MOTOR/INDICE';
        if(0===strpos($code,'data_canonical_')||0===strpos($code,'data_categories_'))return 'FUENTE';
        if(0===strpos($code,'source_'))return 'FUENTE';
        if(0===strpos($code,'academy_test_'))return 'ACADEMIA/FUENTE';
        if(strpos($code,'learning_')===0)return 'APRENDIZAJE';
        if(strpos($code,'academy_')===0||'lesson'===$type)return 'ACADEMIA';
        if(strpos($code,'faq_')===0)return 'FUENTE';
        if(strpos($code,'relation_')===0&&false!==strpos($code,'missing'))return 'FUENTE';
        if(in_array($type,array('relation','hub_secondary','hub_primary','cluster'),true))return 'FUENTE';
        if(strpos($code,'duplicate_')===0)return 'FUENTE';
        if(strpos($code,'product_')===0||strpos($code,'category_')===0||strpos($code,'editorial_')===0||strpos($code,'attribute_')===0||strpos($code,'vocabulary_')===0)return 'FUENTE';
        return 'EVIDENCIA/REVISION';
    }

    private static function scope_for($code,$type) {
        if(strpos($code,'data_')===0||'system'===$type)return 'data_state';
        if(0===strpos($code,'source_'))return 'catalog';
        if(0===strpos($code,'motor_'))return 'behavior';
        if(0===strpos($code,'academy_test_'))return 'academia';
        if(strpos($code,'learning_')===0)return 'learning';
        if(strpos($code,'academy_')===0||'lesson'===$type)return 'academia';
        if(strpos($code,'faq_')===0||'faq'===$type)return 'faq';
        if(strpos($code,'relation_')===0||in_array($type,array('relation','hub_secondary','hub_primary','cluster'),true))return 'architecture';
        if(strpos($code,'category_')===0||strpos($code,'product_category_')===0||strpos($code,'attribute_')===0||strpos($code,'product_')===0)return 'catalog';
        if(strpos($code,'editorial_')===0||in_array($type,array('post','page'),true))return 'editorial';
        return 'catalog';
    }

    private static function edit_url($type,$id) {
        $id=absint($id);if(!$id)return '';
        if(in_array($type,array('product','post','page','hub_secondary','hub_primary','cluster'),true))return get_edit_post_link($id,'');
        if('category'===$type){$u=get_edit_term_link($id,'product_cat','product');return is_wp_error($u)?'':(string)$u;}
        return '';
    }

    private static function decode_array($value) {
        if (is_array($value)) return $value;
        $decoded=json_decode((string)$value,true);
        return is_array($decoded)?$decoded:array();
    }

    private static function relation_object_exists($type,$id) {
        $id=absint($id);if(!$id)return false;$type=sanitize_key((string)$type);
        if('product_cat'===$type||'category'===$type){$t=get_term($id,'product_cat');return $t&&!is_wp_error($t);}
        if(in_array($type,array('product','post','page','landing','hub_secondary','hub_primary','cluster'),true))return get_post($id) instanceof WP_Post;
        return true; // Tipos externos/desconocidos no se declaran rotos sin evidencia.
    }

    private static function product_concepts($p,$object_vocab=array()) {
        $out=array();
        foreach((array)($p['vocabulary']??array()) as $group=>$items){$g=sanitize_key((string)$group);if(!in_array($g,array('tipo','subtipo','rol','aplicacion','plataforma'),true))continue;foreach((array)$items as $i){$slug=is_array($i)?(string)($i['slug']??$i['label']??''):(string)$i;$slug=sanitize_title($slug);if($slug)$out[]=$g.':'.$slug;}}
        foreach((array)$object_vocab as $v){$g=sanitize_key((string)($v['group']??''));if(!in_array($g,array('tipo','subtipo','rol','aplicacion','plataforma'),true))continue;$slug=sanitize_title((string)($v['slug']??$v['label']??''));if($slug)$out[]=$g.':'.$slug;}
        return array_values(array_unique($out));
    }

    private static function attribute_keys($p) {
        $out=array();foreach((array)($p['attributes']??array()) as $a){if(!is_array($a))continue;$label=trim((string)($a['label']??$a['name']??''));if($label)$out[]=self::norm($label);}return array_values(array_unique(array_filter($out)));
    }

    private static function tag_keys($p) {
        $out=array();foreach((array)($p['tags']??array()) as $t){$slug=is_array($t)?(string)($t['slug']??$t['name']??''):(string)$t;$slug=sanitize_title($slug);if($slug)$out[]=$slug;}return array_values(array_unique($out));
    }

    private static function split_candidate($per_product,$n) {
        if($n<12)return array();$freq=array();$sets=array();foreach($per_product as $pid=>$concepts){$filtered=array_values(array_filter($concepts,static function($c){return 0===strpos($c,'tipo:')||0===strpos($c,'subtipo:');}));$sets[$pid]=$filtered;foreach($filtered as $c)$freq[$c]=($freq[$c]??0)+1;}arsort($freq,SORT_NUMERIC);$candidates=array();foreach($freq as $c=>$count){$r=$count/$n;if($r>=0.25&&$r<=0.75)$candidates[$c]=$count;}if(count($candidates)<2)return array();$keys=array_keys($candidates);for($i=0;$i<count($keys);$i++){for($j=$i+1;$j<count($keys);$j++){$a=$keys[$i];$b=$keys[$j];$both=0;foreach($sets as $s)if(in_array($a,$s,true)&&in_array($b,$s,true))$both++;$min=min($candidates[$a],$candidates[$b]);if($min>0&&$both/$min<=0.15)return array(array('concept'=>$a,'products'=>$candidates[$a],'ratio'=>round($candidates[$a]/$n,3)),array('concept'=>$b,'products'=>$candidates[$b],'ratio'=>round($candidates[$b]/$n,3)));}}return array();
    }

    private static function flag_size_outliers($type,$entities,$metric) {
        if(count($entities)<4)return;$values=array();foreach($entities as $id=>$data){$values[]=count((array)($data[$metric]??array()));}$median=self::median($values);if($median<4)return;$low=max(1,(int)floor($median*0.25));$high=max(20,(int)ceil($median*2.5));foreach($entities as $id=>$data){$count=count((array)($data[$metric]??array()));$title=get_the_title(absint($id))?:ucfirst(str_replace('_',' ',$type)).' #'.absint($id);if($count<=$low)self::finding('architecture_under_sized','low',$type,$id,$title,'Estructura infradimensionada respecto a sus pares',array('products'=>$count,'median'=>$median,'threshold'=>$low),'Revisar si debe integrarse con otra rama o si su pequeno tamano esta justificado.');elseif($count>=$high)self::finding('architecture_over_sized','medium',$type,$id,$title,'Estructura sobredimensionada respecto a sus pares',array('products'=>$count,'median'=>$median,'threshold'=>$high),'Revisar si contiene subfamilias suficientemente distintas para dividir o redistribuir.');}}

    private static function architecture_rows($entities,$child_key,$product_key) {$rows=array();foreach($entities as $id=>$d)$rows[]=array('id'=>absint($id),'title'=>get_the_title(absint($id))?:('#'.absint($id)),'children'=>count((array)($d[$child_key]??array())),'products'=>count((array)($d[$product_key]??array())));usort($rows,static function($a,$b){return $b['products']<=>$a['products'];});return $rows;}
    private static function pretty_frequency($freq,$n) {$out=array();foreach($freq as $k=>$c)$out[]=array('concept'=>$k,'count'=>$c,'ratio'=>round($c/max(1,$n),3));return $out;}
    private static function frequency_text($items) {$out=array();foreach((array)$items as $i)$out[]=(string)($i['concept']??'').' '.number_format_i18n(100*(float)($i['ratio']??0),0).'%';return implode(' · ',array_slice($out,0,6));}
    private static function median($values) {$values=array_values(array_map('intval',(array)$values));if(!$values)return 0;sort($values,SORT_NUMERIC);$n=count($values);$m=(int)floor($n/2);return $n%2?$values[$m]:(($values[$m-1]+$values[$m])/2);}
    private static function token_jaccard($a,$b) {$aa=array_unique(array_filter(explode(' ',self::norm($a))));$bb=array_unique(array_filter(explode(' ',self::norm($b))));if(!$aa||!$bb)return 0;$inter=count(array_intersect($aa,$bb));$union=count(array_unique(array_merge($aa,$bb)));return $union?$inter/$union:0;}
    private static function meaningful_overlap($a,$b) {$aa=array_values(array_filter(explode(' ',self::norm($a)),array(__CLASS__,'meaningful_token')));$bb=array_values(array_filter(explode(' ',self::norm($b)),array(__CLASS__,'meaningful_token')));if(!$aa||!$bb)return false;return count(array_intersect($aa,$bb))>0;}
    private static function meaningful_token($t) {return strlen((string)$t)>=4&&!in_array((string)$t,array('para','como','este','esta','estos','estas','sobre','guia','todo','todos','todas','mejor','mejores'),true);}
    private static function norm($text) {if(class_exists('SEO_Dependiente_Index'))return SEO_Dependiente_Index::normalize((string)$text);$text=remove_accents(wp_strip_all_tags((string)$text));$text=strtolower($text);return trim(preg_replace('/[^a-z0-9]+/',' ',$text));}
    private static function strlen($text) {return function_exists('mb_strlen')?mb_strlen((string)$text,'UTF-8'):strlen((string)$text);}
    private static function table_exists($table) {global $wpdb;return (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like((string)$table)))===(string)$table;}
    private static function severity_label($s) {$m=array('critical'=>'CRITICO','high'=>'ALTA','medium'=>'REVISAR','low'=>'OBSERVAR','info'=>'INFO');return $m[$s]??strtoupper((string)$s);}
    private static function capability() {return class_exists('WooCommerce')?'manage_woocommerce':'manage_options';}
    private static function guard_action($nonce_action) {if(!current_user_can(self::capability()))wp_die(esc_html__('No tienes permisos para ejecutar esta accion.','seo-taxonomy'));check_admin_referer($nonce_action);}
    private static function append_history($report) {$h=(array)get_option(self::HISTORY_OPTION,array());$s=(array)($report['summary']??array());$h[]=array('generated_at'=>(string)($report['generated_at']??''),'dependiente_version'=>(string)($report['dependiente_version']??''),'findings'=>absint($s['findings']??0),'critical'=>absint($s['critical']??0),'high'=>absint($s['high']??0),'medium'=>absint($s['medium']??0),'low'=>absint($s['low']??0));$h=array_slice($h,-20);update_option(self::HISTORY_OPTION,$h,false);}
}
