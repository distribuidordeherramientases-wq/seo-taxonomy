<?php

defined('ABSPATH') || exit;

/**
 * Auditor academico.
 *
 * Principios:
 * - PRO y sus fuentes canonicas son la verdad de origen.
 * - El auditor observa, contrasta, prioriza y propone revision humana.
 * - No reindexa, no entrena, no corrige taxonomias, no mueve productos.
 * - Una correlacion semantica nunca se presenta como causalidad demostrada.
 */
final class SEO_Auditor {
    const REPORT_OPTION = 'seo_auditor_last_report';
    const HISTORY_OPTION = 'seo_auditor_history';
    const REPORT_VERSION = 1;
    const MAX_FINDINGS = 1200;
    const MAX_CATEGORY_PROFILES = 250;
    const MAX_ENTITY_FINDINGS_PER_RULE = 180;

    private static $findings = array();
    private static $rule_counts = array();

    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('admin_post_seo_auditor_run', array(__CLASS__, 'handle_run'));
        add_action('admin_post_seo_auditor_export', array(__CLASS__, 'handle_export'));
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
        self::guard_action('seo_auditor_run');
        @set_time_limit(180);
        $report = self::run_audit();
        update_option(self::REPORT_OPTION, $report, false);
        self::append_history($report);
        wp_safe_redirect(add_query_arg(array('page'=>'seo-dependiente','tab'=>'auditor','audited'=>1), admin_url('admin.php')));
        exit;
    }

    public static function handle_export() {
        self::guard_action('seo_auditor_export');
        $report = self::last_report();
        if (!$report) {
            wp_die(esc_html__('No existe una auditoria guardada para exportar.', 'seo-taxonomy'));
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="seo-auditor-' . gmdate('Ymd-His') . '.json"');
        echo wp_json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    public static function render_tab() {
        if (!current_user_can(self::capability())) {
            wp_die(esc_html__('No tienes permisos para acceder al Auditor.', 'seo-taxonomy'));
        }
        $report = self::last_report();
        $history = (array) get_option(self::HISTORY_OPTION, array());
        $view = sanitize_key((string) ($_GET['audit_view'] ?? 'summary'));
        if (!in_array($view, array('summary','findings','categories','architecture','academia'), true)) {
            $view = 'summary';
        }

        if (isset($_GET['audited'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Auditoria completada. El informe es de solo lectura: no se ha modificado contenido ni conocimiento.</p></div>';
        }

        echo '<section class="seo-auditor">';
        echo '<div class="seo-auditor__hero">';
        echo '<div><h2>Auditor academico</h2><p>Comprueba coherencia, homogeneidad y relaciones del ecosistema que utiliza el Dependiente. Las conclusiones son senales para revision humana, no correcciones automaticas.</p></div>';
        echo '<div class="seo-auditor__actions">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_auditor_run">';
        wp_nonce_field('seo_auditor_run');
        submit_button($report ? 'Ejecutar nueva auditoria' : 'Ejecutar primera auditoria', 'primary', 'submit', false);
        echo '</form>';
        if ($report) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="seo_auditor_export">';
            wp_nonce_field('seo_auditor_export');
            submit_button('Exportar JSON', 'secondary', 'submit', false);
            echo '</form>';
        }
        echo '</div></div>';

        if (!$report) {
            self::render_empty();
            echo '</section>';
            return;
        }

        self::render_subnav($view);
        if ('findings' === $view) {
            self::render_findings($report);
        } elseif ('categories' === $view) {
            self::render_categories($report);
        } elseif ('architecture' === $view) {
            self::render_architecture($report);
        } elseif ('academia' === $view) {
            self::render_academia($report);
        } else {
            self::render_summary($report, $history);
        }
        echo '</section>';
    }

    public static function last_report() {
        $report = get_option(self::REPORT_OPTION, array());
        return is_array($report) ? $report : array();
    }

    public static function run_audit() {
        self::$findings = array();
        self::$rule_counts = array();
        $started = microtime(true);

        $inventory = self::collect_inventory();
        $products = $inventory['products'];
        $categories = $inventory['categories'];
        $category_products = $inventory['category_products'];
        $object_vocabulary = self::load_object_vocabulary();

        self::audit_index_state($inventory);
        self::audit_products($products, $object_vocabulary, (array)($inventory['product_posts'] ?? array()));
        self::audit_categories($categories, $category_products, $object_vocabulary, (array)($inventory['category_content'] ?? array()));
        self::audit_editorial($inventory['editorial'], $object_vocabulary);
        self::audit_faqs($inventory['faqs']);
        $relation_data = self::audit_relations($inventory['relations'], $categories, $category_products);
        $category_profiles = self::audit_category_homogeneity($products, $categories, $category_products);
        $architecture_profiles = self::audit_architecture_sizing($relation_data, $category_products);
        $academy = self::audit_academia();

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

        $public_inventory = $inventory;
        unset($public_inventory['products'], $public_inventory['product_posts'], $public_inventory['categories'], $public_inventory['category_products'], $public_inventory['category_content'], $public_inventory['editorial'], $public_inventory['faqs'], $public_inventory['relations']);

        return array(
            'schema'=>array('name'=>'seo_academic_auditor','version'=>self::REPORT_VERSION),
            'auditor_version'=>SEO_AUDITOR_VERSION,
            'dependiente_version'=>defined('SEO_DEPENDIENTE_VERSION') ? SEO_DEPENDIENTE_VERSION : '',
            'generated_at'=>current_time('mysql'),
            'generated_at_gmt'=>gmdate('Y-m-d H:i:s'),
            'execution_seconds'=>round(microtime(true)-$started, 3),
            'mode'=>'manual_read_only',
            'summary'=>$summary,
            'inventory'=>$public_inventory,
            'rule_counts'=>self::$rule_counts,
            'findings'=>array_slice(self::$findings, 0, self::MAX_FINDINGS),
            'category_profiles'=>array_slice($category_profiles, 0, self::MAX_CATEGORY_PROFILES),
            'architecture_profiles'=>$architecture_profiles,
            'academy'=>$academy,
            'notes'=>array(
                'read_only'=>true,
                'no_content_mutation'=>true,
                'no_learning_mutation'=>true,
                'no_reindex'=>true,
                'semantic_flags_are_review_signals'=>true,
                'faq_owner_scope'=>array('product','product_cat'),
                'faq_to_editorial_relation_inferred'=>false,
            ),
        );
    }

    private static function collect_inventory() {
        global $wpdb;
        $status = class_exists('SEO_Dependiente_Index') ? SEO_Dependiente_Index::status() : array();
        $rows = class_exists('SEO_Dependiente_Index') ? SEO_Dependiente_Index::get_rows(5000) : array();
        $products = array();
        $category_products = array();
        foreach ((array)$rows as $row) {
            $d = SEO_Dependiente_Index::decode_row($row);
            $pid = absint($d['product_id'] ?? 0);
            if (!$pid) continue;
            $products[$pid] = $d;
            foreach ((array)($d['categories'] ?? array()) as $cat) {
                $cid = absint($cat['id'] ?? 0);
                if ($cid) $category_products[$cid][$pid] = true;
            }
        }

        $terms = get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false));
        $categories = array();
        if (!is_wp_error($terms)) {
            foreach ((array)$terms as $term) $categories[absint($term->term_id)] = $term;
        }

        $product_posts = array();
        if ($products) {
            $ids = implode(',', array_map('absint', array_keys($products)));
            foreach ((array)$wpdb->get_results("SELECT ID,post_name,post_excerpt,post_content FROM {$wpdb->posts} WHERE ID IN ({$ids}) AND post_type='product'", ARRAY_A) as $pr) {
                $product_posts[absint($pr['ID'])] = $pr;
            }
        }

        $category_content = array();
        $nodes = $wpdb->prefix . 'seo_nodes';
        if (self::table_exists($nodes)) {
            foreach ((array)$wpdb->get_results("SELECT object_id,seo_role,keywords FROM {$nodes} WHERE object_type='category' AND seo_role IN ('excerpt','description') AND status=1", ARRAY_A) as $nr) {
                $category_content[absint($nr['object_id'])][sanitize_key((string)$nr['seo_role'])] = (string)$nr['keywords'];
            }
        }

        $editorial = (array)$wpdb->get_results(
            "SELECT ID,post_type,post_title,post_name,post_excerpt,post_content,post_modified FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') ORDER BY ID ASC",
            ARRAY_A
        );

        $faq_table = $wpdb->prefix . 'seo_faq';
        $faqs = array();
        if (self::table_exists($faq_table)) {
            $faqs = (array)$wpdb->get_results("SELECT id,object_type,object_id,ambito,question,answer,active,updated_at FROM {$faq_table} WHERE active=1 ORDER BY id ASC", ARRAY_A);
        }

        $rel_table = $wpdb->prefix . 'seo_relations';
        $relations = array();
        if (self::table_exists($rel_table)) {
            $relations = (array)$wpdb->get_results("SELECT source_type,source_id,target_type,target_id,relation_type FROM {$rel_table} ORDER BY source_type,source_id,target_type,target_id,relation_type", ARRAY_A);
        }

        $vocab_table = $wpdb->prefix . 'seo_vocabulary';
        $ov_table = $wpdb->prefix . 'seo_object_vocabulary';
        $vocab_count = self::table_exists($vocab_table) ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$vocab_table} WHERE active=1")) : 0;
        $ov_count = self::table_exists($ov_table) ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$ov_table} WHERE status=1")) : 0;

        return array(
            'catalog_status'=>$status,
            'indexed_products'=>absint($status['indexed'] ?? count($products)),
            'products_loaded_for_comparison'=>count($products),
            'published_products'=>absint($status['published'] ?? 0),
            'indexable_products'=>absint($status['indexable'] ?? 0),
            'hidden_products'=>absint($status['excluded_hidden'] ?? 0),
            'categories_total'=>count($categories),
            'categories_with_indexed_products'=>count(array_filter($category_products)),
            'posts_published'=>count(array_filter($editorial, static function($r){return 'post'===($r['post_type']??'');})),
            'pages_published'=>count(array_filter($editorial, static function($r){return 'page'===($r['post_type']??'');})),
            'faqs_active'=>count($faqs),
            'vocabulary_active'=>$vocab_count,
            'object_vocabulary_relations'=>$ov_count,
            'semantic_relations'=>count($relations),
            'products'=>$products,
            'product_posts'=>$product_posts,
            'categories'=>$categories,
            'category_content'=>$category_content,
            'category_products'=>$category_products,
            'editorial'=>$editorial,
            'faqs'=>$faqs,
            'relations'=>$relations,
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
        if ($indexable > 0 && $indexed !== $indexable) {
            self::finding('index_incomplete','critical','system',0,'Indice del Dependiente incompleto',
                "El auditor ve {$indexed} productos indexados frente a {$indexable} indexables. Las comparaciones de homogeneidad pueden quedar sesgadas.",
                array('indexed'=>$indexed,'indexable'=>$indexable),
                'Completar y verificar el indice antes de tomar decisiones estructurales basadas en comparaciones de productos.');
        }
    }

    private static function audit_products($products, $object_vocabulary, $product_posts) {
        $title_groups = array();
        foreach ($products as $pid=>$p) {
            $title = trim((string)($p['title'] ?? ''));
            $post_row = (array)($product_posts[$pid] ?? array());
            $excerpt = trim(wp_strip_all_tags((string)($post_row['post_excerpt'] ?? $p['excerpt'] ?? '')));
            $description = trim(wp_strip_all_tags(strip_shortcodes((string)($post_row['post_content'] ?? ''))));
            $slug = (string)($post_row['post_name'] ?? '');
            $norm_title = self::norm($title);
            if ($norm_title) $title_groups[$norm_title][$pid] = $title;

            if ($title === '') self::finding('product_missing_title','critical','product',$pid,'Producto sin titulo','El producto indexable no tiene titulo.',array(),'Completar el titulo antes de usarlo como fuente de aprendizaje.');
            if ($excerpt === '') self::finding('product_missing_excerpt','medium','product',$pid,$title ?: "Producto #{$pid}",'Descripcion corta vacia','', 'Completar una descripcion corta comercial y diferenciadora.');
            if ($description === '') self::finding('product_missing_description','medium','product',$pid,$title ?: "Producto #{$pid}",'Descripcion larga vacia','', 'Completar una descripcion tecnica/comercial coherente con el producto, su categoria y sus atributos.');
            if ($slug === '') self::finding('product_missing_slug','high','product',$pid,$title ?: "Producto #{$pid}",'Slug vacio','', 'Asignar un slug estable y descriptivo.');
            if (empty($p['categories'])) self::finding('product_without_category','high','product',$pid,$title ?: "Producto #{$pid}",'Producto sin categoria de catalogo','', 'Asignar una categoria canonica antes de entrenar relaciones.');
            if (empty($p['vocabulary']) && empty($object_vocabulary['product:'.$pid])) self::finding('product_without_vocabulary','medium','product',$pid,$title ?: "Producto #{$pid}",'Producto sin Vocabulary activo','', 'Revisar si necesita tipo, rol, aplicacion, plataforma o subtipo para integrarse semanticamente.');

            foreach ((array)($p['attributes'] ?? array()) as $attr) {
                if (!is_array($attr)) continue;
                $label = trim((string)($attr['label'] ?? $attr['name'] ?? ''));
                $values = (array)($attr['values'] ?? array());
                if ($label !== '' && !$values) {
                    self::finding('attribute_without_value','medium','product',$pid,$title ?: "Producto #{$pid}","Atributo sin valor: {$label}",array('attribute'=>$label),'Completar el valor o retirar el atributo vacio.');
                }
            }
        }

        foreach ($title_groups as $norm=>$group) {
            if (count($group) < 2) continue;
            self::finding('duplicate_product_title','medium','product_group',$norm,'Titulos de producto duplicados',
                'Varios productos publicados tienen el mismo titulo normalizado.',
                array('products'=>array_slice(array_keys($group),0,20),'title'=>reset($group),'count'=>count($group)),
                'Diferenciar variante, medida, capacidad, referencia u otra propiedad que permita distinguirlos.');
        }
    }

    private static function audit_categories($categories, $category_products, $object_vocabulary, $category_content) {
        foreach ($categories as $cid=>$term) {
            $count = count((array)($category_products[$cid] ?? array()));
            $node = (array)($category_content[$cid] ?? array());
            $excerpt = trim(wp_strip_all_tags((string)($node['excerpt'] ?? '')));
            $desc = trim(wp_strip_all_tags((string)($node['description'] ?? $term->description)));
            $name_norm = self::norm((string)$term->name);
            $slug_norm = self::norm(str_replace('-', ' ', (string)$term->slug));
            if ($count > 0 && $excerpt === '') {
                self::finding('category_missing_excerpt','low','category',$cid,(string)$term->name,'Categoria con productos y excerpt vacio',array('products'=>$count),'Completar un resumen corto si esta categoria usa excerpt en la arquitectura SEO.');
            }
            if ($count > 0 && $desc === '') {
                self::finding('category_missing_description','medium','category',$cid,(string)$term->name,'Categoria con productos y descripcion vacia',array('products'=>$count),'Completar una descripcion que delimite que pertenece y que no pertenece a la categoria.');
            }
            if ($count > 0 && empty($object_vocabulary['product_cat:'.$cid])) {
                self::finding('category_without_vocabulary','medium','category',$cid,(string)$term->name,'Categoria sin Vocabulary activo',array('products'=>$count),'Revisar Vocabulary para conectar correctamente categoria, productos y contenido editorial.');
            }
            if ($count === 1) {
                self::finding('category_single_product','low','category',$cid,(string)$term->name,'Categoria con un solo producto',array('products'=>1),'Revisar si debe mantenerse como categoria propia o integrarse en una categoria mas representativa.');
            }
            if ($name_norm && $slug_norm && self::token_jaccard($name_norm,$slug_norm) < 0.34) {
                self::finding('category_name_slug_drift','low','category',$cid,(string)$term->name,'Nombre y slug poco alineados',array('slug'=>(string)$term->slug),'Revisar si el slug sigue representando la categoria actual.');
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

    private static function audit_faqs($faqs) {
        $seen = array();
        foreach ($faqs as $faq) {
            $id = absint($faq['id'] ?? 0);
            $ot = absint($faq['object_type'] ?? 0);
            $oid = absint($faq['object_id'] ?? 0);
            $q = trim(wp_strip_all_tags((string)($faq['question'] ?? '')));
            $a = trim(wp_strip_all_tags((string)($faq['answer'] ?? '')));
            if (!in_array($ot,array(2,3),true)) {
                self::finding('faq_invalid_owner_type','critical','faq',$id,$q ?: "FAQ #{$id}",'FAQ fuera del modelo canonico de owner',array('object_type'=>$ot,'object_id'=>$oid),'Las FAQs deben pertenecer exclusivamente a producto o categoria. Revisar este registro.');
                continue;
            }
            $owner_ok = false;
            $owner_title = '';
            if (3 === $ot) {
                $p = get_post($oid); $owner_ok = $p instanceof WP_Post && 'product' === $p->post_type; $owner_title = $owner_ok ? (string)$p->post_title : '';
            } else {
                $t = get_term($oid,'product_cat'); $owner_ok = $t && !is_wp_error($t); $owner_title = $owner_ok ? (string)$t->name : '';
            }
            if (!$owner_ok) self::finding('faq_orphan_owner','critical','faq',$id,$q ?: "FAQ #{$id}",'FAQ con owner inexistente o incompatible',array('object_type'=>$ot,'object_id'=>$oid),'Reasignar la FAQ a su producto/categoria real o retirarla.');
            if ($q === '') self::finding('faq_empty_question','high','faq',$id,"FAQ #{$id}",'FAQ sin pregunta','', 'Completar o retirar la FAQ.');
            if (self::strlen($a) < 30) self::finding('faq_thin_answer','medium','faq',$id,$q ?: "FAQ #{$id}",'Respuesta FAQ vacia o demasiado corta',array('owner'=>$owner_title,'characters'=>self::strlen($a)),'Revisar si responde de forma suficiente, concreta y verificable.');
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
            if (empty($architecture['category_to_secondary'][$cid])) {
                self::finding('category_without_secondary_hub','medium','category',$cid,(string)$categories[$cid]->name,'Categoria con productos sin Hub secundario',array('products'=>count($pids)),'Revisar si falta su relacion arquitectonica o si la categoria debe permanecer fuera de hubs por diseno.');
            }
        }
        return $architecture;
    }

    private static function audit_category_homogeneity($products, $categories, $category_products) {
        $profiles = array();
        foreach ($category_products as $cid=>$pid_map) {
            $pids = array_keys((array)$pid_map);
            $n = count($pids);
            if ($n < 3 || !isset($categories[$cid])) continue;
            $freq = array(); $attr_freq = array(); $tag_freq = array(); $per_product = array();
            foreach ($pids as $pid) {
                if (empty($products[$pid])) continue;
                $concepts = self::product_concepts($products[$pid]);
                $per_product[$pid] = $concepts;
                foreach ($concepts as $c) $freq[$c] = ($freq[$c] ?? 0) + 1;
                foreach (self::attribute_keys($products[$pid]) as $ak) $attr_freq[$ak] = ($attr_freq[$ak] ?? 0) + 1;
                foreach (self::tag_keys($products[$pid]) as $tk) $tag_freq[$tk] = ($tag_freq[$tk] ?? 0) + 1;
            }
            arsort($freq,SORT_NUMERIC); arsort($attr_freq,SORT_NUMERIC); arsort($tag_freq,SORT_NUMERIC);
            $dominant = array();
            foreach ($freq as $concept=>$count) if ($count/$n >= 0.60) $dominant[$concept]=$count;
            $top = array_slice($freq,0,8,true);
            $coherence = $dominant ? min(1.0, array_sum(array_map(static function($c) use($n){return $c/$n;}, $dominant))/max(1,count($dominant))) : 0.0;

            $profile = array('category_id'=>$cid,'category'=>(string)$categories[$cid]->name,'products'=>$n,'dominant_concepts'=>self::pretty_frequency($dominant,$n),'top_concepts'=>self::pretty_frequency($top,$n),'coherence'=>round($coherence,3),'flags'=>array());

            if ($n >= 8 && !$dominant) {
                $profile['flags'][]='heterogeneous';
                self::finding('category_heterogeneous','medium','category',$cid,(string)$categories[$cid]->name,'Categoria semanticamente heterogenea',array('products'=>$n,'top_concepts'=>self::pretty_frequency($top,$n)),'Revisar si mezcla familias distintas. Puede ser candidata a division, subcategorias o reasignacion de productos.');
            }

            if ($dominant && $n >= 5) {
                $outliers = array();
                foreach ($per_product as $pid=>$concepts) {
                    if (!array_intersect(array_keys($dominant), $concepts)) $outliers[]=$pid;
                }
                if ($outliers && count($outliers) <= max(12,(int)floor($n*0.35))) {
                    $profile['flags'][]='product_outliers';
                    foreach (array_slice($outliers,0,8) as $pid) {
                        self::finding('product_category_outlier','medium','product',$pid,(string)($products[$pid]['title'] ?? "Producto #{$pid}"),'Producto atipico respecto a su categoria',array('category_id'=>$cid,'category'=>(string)$categories[$cid]->name,'dominant_concepts'=>array_keys($dominant)),'Revisar asignacion de categoria o Vocabulary. No mover automaticamente: puede ser una excepcion legitima.');
                    }
                }
            }

            // Candidato de division: dos conceptos de tipo/subtipo abundantes y casi excluyentes.
            $candidate = self::split_candidate($per_product,$n);
            if ($candidate) {
                $profile['flags'][]='split_candidate';
                self::finding('category_split_candidate','medium','category',$cid,(string)$categories[$cid]->name,'Posible candidata a division',array('products'=>$n,'cohorts'=>$candidate),'Comparar los dos grupos de productos y valorar subcategorias o separacion si responden a intenciones de compra distintas.');
            }

            // Atributos comunes: productos que carecen de un atributo presente en >=75% de sus pares.
            $common_attrs = array();
            foreach ($attr_freq as $ak=>$count) if ($n >= 5 && $count/$n >= 0.75) $common_attrs[$ak]=$count;
            if ($common_attrs) {
                $emitted = 0;
                foreach ($pids as $pid) {
                    $keys = self::attribute_keys($products[$pid] ?? array());
                    $missing = array_diff(array_keys($common_attrs),$keys);
                    if ($missing && $emitted < 3) {
                        self::finding('product_missing_common_attribute','low','product',$pid,(string)($products[$pid]['title'] ?? "Producto #{$pid}"),'Faltan atributos comunes de su categoria',array('category'=>(string)$categories[$cid]->name,'missing'=>array_slice(array_values($missing),0,8)),'Comprobar si el dato falta, no aplica o esta registrado con otro nombre.');
                        $emitted++;
                    }
                }
            }

            $common_tags = array();
            foreach ($tag_freq as $tk=>$count) if ($n >= 6 && $count/$n >= 0.80) $common_tags[$tk]=$count;
            if ($common_tags) {
                $emitted_tags = 0;
                foreach ($pids as $pid) {
                    $keys = self::tag_keys($products[$pid] ?? array());
                    $missing = array_diff(array_keys($common_tags),$keys);
                    if ($missing && $emitted_tags < 2) {
                        self::finding('product_missing_common_tag','low','product',$pid,(string)($products[$pid]['title'] ?? "Producto #{$pid}"),'Etiqueta comun ausente respecto a sus pares',array('category'=>(string)$categories[$cid]->name,'missing_tags'=>array_slice(array_values($missing),0,6)),'Comprobar si la etiqueta falta, si el producto es una excepcion legitima o si la categoria mezcla productos distintos.');
                        $emitted_tags++;
                    }
                }
            }

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

    private static function audit_academia() {
        if (!class_exists('SEO_Dependiente_Entrenador')) return array('available'=>false);
        global $wpdb;
        $lt = SEO_Dependiente_Entrenador::lessons_table();
        $qt = SEO_Dependiente_Entrenador::questions_table();
        $rt = SEO_Dependiente_Entrenador::runs_table();
        if (!self::table_exists($lt) || !self::table_exists($qt) || !self::table_exists($rt)) return array('available'=>false);

        $rows = (array)$wpdb->get_results(
            "SELECT q.lesson_key,q.lesson_order,q.source_type,q.source_id,q.source_key,
                    l.title,l.status,
                    r.status run_status,r.evaluation_status,r.evaluation_json
             FROM {$qt} q
             INNER JOIN (SELECT question_id,MAX(id) run_id FROM {$rt} WHERE question_id IS NOT NULL GROUP BY question_id) lr ON lr.question_id=q.id
             INNER JOIN {$rt} r ON r.id=lr.run_id
             LEFT JOIN {$lt} l ON l.lesson_key=q.lesson_key
             WHERE q.enabled=1 AND q.lesson_key<>'' AND q.lesson_key NOT LIKE 'lab\\_%'
             ORDER BY q.lesson_order,q.id",
            ARRAY_A
        );
        if (!$rows) return array('available'=>true,'lessons'=>array(),'systemic_signals'=>array(),'recurring_sources'=>array());

        $lessons=array();$sources=array();
        foreach($rows as $r){
            $key=sanitize_key((string)($r['lesson_key']??''));if(!$key)continue;
            if(!isset($lessons[$key]))$lessons[$key]=array('lesson_key'=>$key,'order'=>absint($r['lesson_order']??0),'title'=>(string)($r['title']??$key),'status'=>(string)($r['status']??''),'answered'=>0,'pass_any'=>0,'failed'=>0,'errors'=>0,'diag'=>array());
            $lessons[$key]['answered']++;
            $eval=sanitize_key((string)($r['evaluation_status']??''));$run=sanitize_key((string)($r['run_status']??''));
            $is_pass=0===strpos($eval,'pass_');$is_error=('error'===$run||'error'===$eval||'technical_error'===$eval);$is_fail=(!$is_pass&&!$is_error&&('fail'===$eval||'failed'===$eval));
            if($is_pass)$lessons[$key]['pass_any']++;
            if($is_fail)$lessons[$key]['failed']++;
            if($is_error)$lessons[$key]['errors']++;
            $ej=json_decode((string)($r['evaluation_json']??''),true);$diag=is_array($ej)?sanitize_key((string)($ej['diagnostic_type']??'')):'';
            if($diag)$lessons[$key]['diag'][$diag]=absint($lessons[$key]['diag'][$diag]??0)+1;

            $sid=absint($r['source_id']??0);$skey=sanitize_key((string)($r['source_type']??'')).':'.($sid?:trim((string)($r['source_key']??'')));
            if($skey!==':'){
                if(!isset($sources[$skey]))$sources[$skey]=array('key'=>$skey,'label'=>$skey,'total'=>0,'failed'=>0,'lessons'=>array());
                $sources[$skey]['total']++;if($is_fail)$sources[$skey]['failed']++;$sources[$skey]['lessons'][$key]=true;
            }
        }

        $out=array('available'=>true,'lessons'=>array(),'systemic_signals'=>array(),'recurring_sources'=>array());
        foreach($lessons as $key=>$l){
            $diagnostics=array();foreach($l['diag'] as $diag=>$count)$diagnostics[]=array('key'=>$diag,'label'=>self::diagnostic_label($diag),'count'=>$count,'destination'=>self::diagnostic_destination($diag));
            usort($diagnostics,static function($a,$b){return $b['count']<=>$a['count'];});
            unset($l['diag']);$l['diagnostics']=$diagnostics;$out['lessons'][]=$l;
            $failed=absint($l['failed']);if($failed>=10){foreach($diagnostics as $d){$count=absint($d['count']);$ratio=$count/max(1,$failed);if($ratio>=0.60){$signal=array('lesson_key'=>$key,'title'=>(string)$l['title'],'diagnostic'=>(string)$d['key'],'label'=>(string)$d['label'],'count'=>$count,'failed'=>$failed,'ratio'=>round($ratio,3),'destination'=>(string)$d['destination']);$out['systemic_signals'][]=$signal;self::finding('academy_systemic_pattern','medium','lesson',$key,(string)$l['title'],'Academia muestra un patron sistemico',$signal,'Investigar primero la capa tecnica o curricular indicada antes de corregir masivamente las fuentes asociadas.');}}}
        }
        usort($out['lessons'],static function($a,$b){return $a['order']<=>$b['order'];});
        foreach($sources as $s){$lesson_count=count($s['lessons']);if($s['total']>=3&&$s['failed']>=2&&$lesson_count>=2){$s['lessons']=array_keys($s['lessons']);$s['failure_ratio']=round($s['failed']/max(1,$s['total']),3);$out['recurring_sources'][]=$s;}}
        usort($out['recurring_sources'],static function($a,$b){if($a['failed']!==$b['failed'])return $b['failed']<=>$a['failed'];return $b['total']<=>$a['total'];});
        $out['recurring_sources']=array_slice($out['recurring_sources'],0,100);
        return $out;
    }

    private static function diagnostic_label($key) {
        $map=array('mastered'=>'Conocimiento resuelto','parser_gap'=>'Fallo de interpretacion','retrieval_gap'=>'Fallo de recuperacion','editorial_retrieval_gap'=>'Fallo de recuperacion editorial','faq_owner_retrieval_gap'=>'Fallo FAQ por owner','cross_retrieval_gap'=>'Fallo de relacion cruzada','semantic_expansion_skipped'=>'Expansion semantica omitida','semantic_candidates_filtered'=>'Candidatos semanticos filtrados','semantic_route_unresolved'=>'Ruta semantica sin candidatos','ranking_gap'=>'Fallo de ranking/filtro','clarification_gap'=>'Aclaracion innecesaria','curriculum_invalid'=>'Pregunta/evaluacion a revisar','technical_error'=>'Error tecnico','observed'=>'Observacion','unknown'=>'Sin diagnostico');return $map[sanitize_key((string)$key)]??(string)$key;
    }

    private static function diagnostic_destination($key) {
        $key=sanitize_key((string)$key);if(in_array($key,array('retrieval_gap','editorial_retrieval_gap','faq_owner_retrieval_gap','cross_retrieval_gap','semantic_expansion_skipped','semantic_candidates_filtered','semantic_route_unresolved','ranking_gap','parser_gap'),true))return 'Motor';if(in_array($key,array('curriculum_invalid','clarification_gap'),true))return 'Evaluacion / Academia';if('technical_error'===$key)return 'Tecnico';return 'Aprendizaje / revisar evidencia';
    }

    private static function render_empty() {
        echo '<div class="seo-auditor__empty">';
        echo '<h3>Que comprobara</h3>';
        echo '<ul><li>Productos: titulos, slugs, excerpts, categorias, Vocabulary y atributos.</li><li>Categorias: descripcion, Vocabulary, homogeneidad y posibles productos atipicos.</li><li>Posts y paginas: titulo, slug, espesor de contenido y coherencia orientativa con Vocabulary.</li><li>FAQs: exclusivamente owner producto/categoria, propietarios huerfanos y duplicados.</li><li>Arquitectura: relaciones rotas, categorias sin hub y hubs/clusters infradimensionados o sobredimensionados.</li><li>Academia: patrones repetidos que parecen sistemicos frente a fuentes que fallan en distintas lecciones.</li></ul>';
        echo '<p><strong>No se ejecuta nada al cargar esta pagina.</strong> Solo el boton inicia una auditoria.</p>';
        echo '</div>';
    }

    private static function render_subnav($view) {
        $base=add_query_arg(array('page'=>'seo-dependiente','tab'=>'auditor'),admin_url('admin.php'));
        echo '<nav class="seo-auditor__subnav">';
        foreach(array('summary'=>'Resumen','findings'=>'Hallazgos','categories'=>'Categorias y productos','architecture'=>'Hubs y relaciones','academia'=>'Senales de Academia') as $slug=>$label){
            $url=add_query_arg('audit_view',$slug,$base);echo '<a class="'.($view===$slug?'is-active':'').'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
        }
        echo '</nav>';
    }

    private static function render_summary($report,$history) {
        $s=(array)($report['summary']??array());$i=(array)($report['inventory']??array());
        echo '<div class="seo-auditor__metrics">';
        self::metric('Hallazgos',$s['findings']??0);self::metric('Criticos',$s['critical']??0,'critical');self::metric('Prioridad alta',$s['high']??0,'high');self::metric('Revisar',$s['medium']??0,'medium');self::metric('Observar',$s['low']??0,'low');self::metric('Entidades afectadas',$s['entities_to_review']??0);
        echo '</div>';
        echo '<div class="seo-auditor__grid">';
        echo '<div class="postbox"><h3>Universo auditado</h3><table class="widefat striped"><tbody>';
        foreach(array('indexed_products'=>'Productos indexados','indexable_products'=>'Productos indexables','hidden_products'=>'Productos ocultos excluidos','categories_total'=>'Categorias','posts_published'=>'Posts','pages_published'=>'Paginas','faqs_active'=>'FAQs activas','vocabulary_active'=>'Vocabulary activo','semantic_relations'=>'Relaciones semanticas') as $k=>$label){echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html(number_format_i18n(absint($i[$k]??0))).'</td></tr>';}
        echo '</tbody></table></div>';
        echo '<div class="postbox"><h3>Criterio de lectura</h3><p><strong>Critico/Alta:</strong> inconsistencia objetiva o estructura que puede contaminar aprendizaje/recuperacion.</p><p><strong>Revisar:</strong> patron suficientemente fuerte para inspeccion humana.</p><p><strong>Observar:</strong> senal heuristica que puede ser legitima.</p><p>El Auditor nunca decide por si solo que un producto deba moverse, que una categoria deba dividirse o que un Vocabulary sea incorrecto.</p></div>';
        echo '</div>';
        echo '<h3>Prioridad de revision</h3>';self::render_finding_table(array_slice((array)($report['findings']??array()),0,60));
        if($history){echo '<h3>Ultimas auditorias</h3><table class="widefat striped"><thead><tr><th>Fecha</th><th>Hallazgos</th><th>Criticos</th><th>Alta</th><th>Revisar</th><th>Dependiente</th></tr></thead><tbody>';foreach(array_slice(array_reverse($history),0,12) as $h){echo '<tr><td>'.esc_html((string)($h['generated_at']??'')).'</td><td>'.esc_html(absint($h['findings']??0)).'</td><td>'.esc_html(absint($h['critical']??0)).'</td><td>'.esc_html(absint($h['high']??0)).'</td><td>'.esc_html(absint($h['medium']??0)).'</td><td>'.esc_html((string)($h['dependiente_version']??'')).'</td></tr>';}echo '</tbody></table>';}
        echo '<p class="description">Generada: '.esc_html((string)($report['generated_at']??'')).' · '.esc_html((string)($report['execution_seconds']??0)).' s · modo manual de solo lectura.</p>';
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

    private static function render_academia($report) {
        $a=(array)($report['academy']??array());if(empty($a['available'])){echo '<p>No hay datos de Academia disponibles.</p>';return;}
        echo '<h3>Lecciones observadas</h3><table class="widefat striped"><thead><tr><th>Leccion</th><th>Estado</th><th>Evaluadas</th><th>Aciertos</th><th>Fallos</th></tr></thead><tbody>';foreach((array)($a['lessons']??array()) as $l){echo '<tr><td>L'.esc_html(absint($l['order'])).' · '.esc_html((string)$l['title']).'</td><td>'.esc_html((string)$l['status']).'</td><td>'.esc_html(absint($l['answered'])).'</td><td>'.esc_html(absint($l['pass_any'])).'</td><td>'.esc_html(absint($l['failed'])).'</td></tr>';}echo '</tbody></table>';
        echo '<h3>Patrones sistemicos</h3>';if(empty($a['systemic_signals']))echo '<p>No se han detectado concentraciones sistemicas con el umbral actual.</p>';else{echo '<table class="widefat striped"><thead><tr><th>Leccion</th><th>Diagnostico</th><th>Concentracion</th><th>Capa observada</th></tr></thead><tbody>';foreach($a['systemic_signals'] as $s){echo '<tr><td>'.esc_html((string)$s['title']).'</td><td>'.esc_html((string)$s['label']).'</td><td>'.esc_html(number_format_i18n(100*(float)$s['ratio'],1)).'% ('.esc_html(absint($s['count'])).'/'.esc_html(absint($s['failed'])).')</td><td>'.esc_html((string)$s['destination']).'</td></tr>';}echo '</tbody></table>';}
        echo '<h3>Fuentes que reaparecen en varias lecciones</h3>';if(empty($a['recurring_sources']))echo '<p>Aun no hay fuentes con recurrencia suficiente.</p>';else{echo '<table class="widefat striped"><thead><tr><th>Fuente</th><th>Evaluaciones</th><th>Fallos</th><th>Lecciones</th></tr></thead><tbody>';foreach($a['recurring_sources'] as $s){echo '<tr><td>'.esc_html((string)$s['label']).'<div class="description"><code>'.esc_html((string)$s['key']).'</code></div></td><td>'.esc_html(absint($s['total'])).'</td><td>'.esc_html(absint($s['failed'])).'</td><td>'.esc_html(implode(', ',(array)$s['lessons'])).'</td></tr>';}echo '</tbody></table>';}
    }

    private static function render_finding_table($rows) {
        if (!$rows) { echo '<p>No hay hallazgos con este filtro.</p>'; return; }
        echo '<table class="widefat striped seo-auditor__findings"><thead><tr><th>Nivel</th><th>Entidad</th><th>Hallazgo</th><th>Evidencia / recomendacion</th></tr></thead><tbody>';
        foreach($rows as $f){$url=(string)($f['edit_url']??'');echo '<tr><td><span class="seo-auditor__badge is-'.esc_attr((string)$f['severity']).'">'.esc_html(self::severity_label((string)$f['severity'])).'</span></td><td><strong>'.esc_html((string)$f['title']).'</strong><div class="description">'.esc_html((string)$f['entity_type']).($f['entity_id']!==''?' · #'.esc_html((string)$f['entity_id']):'').'</div>'.($url?'<a href="'.esc_url($url).'">Abrir fuente</a>':'').'</td><td><strong>'.esc_html((string)$f['headline']).'</strong><div class="description"><code>'.esc_html((string)$f['code']).'</code></div><p>'.esc_html((string)$f['message']).'</p></td><td>'.(!empty($f['evidence'])?'<details><summary>Ver evidencia</summary><pre>'.esc_html(wp_json_encode($f['evidence'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)).'</pre></details>':'').'<p>'.esc_html((string)$f['recommendation']).'</p></td></tr>';}
        echo '</tbody></table>';
    }

    private static function metric($label,$value,$class='') {echo '<div class="seo-auditor__metric '.($class?'is-'.esc_attr($class):'').'"><strong>'.esc_html(number_format_i18n(absint($value))).'</strong><span>'.esc_html($label).'</span></div>';}

    private static function finding($code,$severity,$entity_type,$entity_id,$title,$headline,$evidence=array(),$recommendation='') {
        $code=sanitize_key((string)$code);$severity=sanitize_key((string)$severity);$entity_type=sanitize_key((string)$entity_type);
        if (count(self::$findings) >= self::MAX_FINDINGS) return;
        self::$rule_counts[$code]=absint(self::$rule_counts[$code]??0)+1;
        if(self::$rule_counts[$code] > self::MAX_ENTITY_FINDINGS_PER_RULE) return;
        $id=is_numeric($entity_id)?absint($entity_id):(string)$entity_id;
        self::$findings[]=array('code'=>$code,'severity'=>$severity,'scope'=>self::scope_for($code,$entity_type),'entity_type'=>$entity_type,'entity_id'=>$id,'title'=>(string)$title,'headline'=>(string)$headline,'message'=>is_string($evidence)?$evidence:'','evidence'=>is_array($evidence)?$evidence:array(),'recommendation'=>(string)$recommendation,'edit_url'=>self::edit_url($entity_type,$id));
    }

    private static function scope_for($code,$type) {
        if(strpos($code,'academy_')===0||'lesson'===$type)return 'academia';
        if(strpos($code,'faq_')===0||'faq'===$type)return 'faq';
        if(strpos($code,'relation_')===0||in_array($type,array('relation','hub_secondary','hub_primary','cluster'),true))return 'architecture';
        if(strpos($code,'category_')===0||strpos($code,'product_category_')===0||strpos($code,'attribute_')===0||strpos($code,'product_missing_common_attribute')===0)return 'catalog';
        if(strpos($code,'editorial_')===0||in_array($type,array('post','page'),true))return 'editorial';
        return 'catalog';
    }

    private static function edit_url($type,$id) {
        $id=absint($id);if(!$id)return '';
        if('product'===$type)return get_edit_post_link($id,'');
        if('post'===$type||'page'===$type)return get_edit_post_link($id,'');
        if('category'===$type){$u=get_edit_term_link($id,'product_cat','product');return is_wp_error($u)?'':(string)$u;}
        return '';
    }

    private static function relation_object_exists($type,$id) {
        $id=absint($id);if(!$id)return false;$type=sanitize_key((string)$type);
        if('product_cat'===$type||'category'===$type){$t=get_term($id,'product_cat');return $t&&!is_wp_error($t);}
        if(in_array($type,array('product','post','page','landing','hub_secondary','hub_primary','cluster'),true))return get_post($id) instanceof WP_Post;
        return true; // Tipos externos/desconocidos no se declaran rotos sin evidencia.
    }

    private static function product_concepts($p) {
        $out=array();foreach((array)($p['vocabulary']??array()) as $group=>$items){$g=sanitize_key((string)$group);if(!in_array($g,array('tipo','subtipo','rol','aplicacion','plataforma'),true))continue;foreach((array)$items as $i){$slug=is_array($i)?(string)($i['slug']??$i['label']??''):(string)$i;$slug=sanitize_title($slug);if($slug)$out[]=$g.':'.$slug;}}return array_values(array_unique($out));
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
