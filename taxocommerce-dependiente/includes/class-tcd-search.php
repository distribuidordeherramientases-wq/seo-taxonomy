<?php
defined('ABSPATH') || exit;
/**
 * Read-only customer query endpoint, strictly isolated from the legacy Dependiente.
 * Returns source-attributed results and never improvises an answer.
 */
final class TCD_Search {
    public static function boot(){
        add_action('rest_api_init',static function(){
            register_rest_route('taxocommerce-dependiente/v1','/search',array(
                'methods'=>'GET','callback'=>array(__CLASS__,'search'),
                'permission_callback'=>'__return_true',
                'args'=>array('q'=>array('required'=>true,'sanitize_callback'=>'sanitize_text_field')),
            ));
        });
        add_shortcode('taxocommerce_dependiente',array(__CLASS__,'shortcode'));
    }
    public static function search($request){
        $q=trim(sanitize_text_field((string)$request->get_param('q')));
        if(!$q || mb_strlen($q)>180)return new WP_Error('tcd_invalid_query','Introduce una consulta de hasta 180 caracteres.',array('status'=>400));
        $terms=preg_split('/\s+/u',mb_strtolower($q));
        $terms=array_values(array_filter(array_unique((array)$terms),static function($x){return mb_strlen($x)>2;}));
        if(!$terms)return rest_ensure_response(array('answer'=>'No encuentro una consulta suficientemente específica.','results'=>array()));
        $results=array();
        $taxonomy=array('product_cat');
        foreach($taxonomy as $tax){
            if(!taxonomy_exists($tax))continue;
            $rows=get_terms(array('taxonomy'=>$tax,'hide_empty'=>false,'search'=>$q,'number'=>8));
            if(is_wp_error($rows))continue;
            foreach($rows as $term){
                $results[]=array('kind'=>'category','id'=>absint($term->term_id),'title'=>(string)$term->name,
                    'url'=>get_term_link($term),'score'=>4);
            }
        }
        $queries=get_posts(array('post_type'=>array('product','post','page'),'post_status'=>'publish','s'=>$q,
            'posts_per_page'=>15,'no_found_rows'=>true,'suppress_filters'=>false));
        foreach($queries as $p){
            $score=0;$title=mb_strtolower((string)$p->post_title);
            foreach($terms as $word){if(false!==mb_strpos($title,$word))$score++;}
            $results[]=array('kind'=>$p->post_type,'id'=>absint($p->ID),'title'=>(string)$p->post_title,
                'url'=>get_permalink($p),'score'=>$score+1);
        }
        usort($results,static function($a,$b){return $b['score']<=>$a['score'];});
        $results=array_slice($results,0,8);
        return rest_ensure_response(array(
            'answer'=>$results?'Estas son las fuentes del catálogo relacionadas con tu consulta.':'No he encontrado una respuesta verificada en el catálogo.',
            'results'=>$results,
            'knowledge_ready'=>'completed'===TCD_Academy::state()['status'],
        ));
    }
    public static function shortcode(){
        $id=wp_unique_id('tcd-search-');
        $endpoint=rest_url('taxocommerce-dependiente/v1/search');
        ob_start();?>
        <div class="tcd-assistant">
            <form action="<?php echo esc_url($endpoint); ?>" method="get">
                <label for="<?php echo esc_attr($id); ?>">¿Qué herramienta o información necesitas?</label>
                <input id="<?php echo esc_attr($id); ?>" name="q" required maxlength="180" type="search">
                <button type="submit">Buscar</button>
            </form>
            <p>Resultados contrastados con el catálogo de la tienda; no sustituye el asesoramiento técnico.</p>
        </div>
        <?php return (string)ob_get_clean();
    }
}
