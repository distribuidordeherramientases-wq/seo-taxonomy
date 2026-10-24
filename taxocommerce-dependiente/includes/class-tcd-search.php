<?php
defined( 'ABSPATH' ) || exit;

/**
 * Public Dependiente runtime.
 *
 * Uses semantic rules, Vocabulary, L9 memory and validated customer feedback.
 * Customer feedback never becomes active from a single click/answer.
 */
final class TCD_Search {
    public static function boot() {
        add_action( 'rest_api_init', static function () {
            register_rest_route( 'taxocommerce-dependiente/v1', '/search', array(
                'methods'=>'GET',
                'callback'=>array( __CLASS__, 'search' ),
                'permission_callback'=>'__return_true',
                'args'=>array(
                    'q'=>array( 'required'=>true, 'sanitize_callback'=>'sanitize_text_field' ),
                    'session'=>array( 'required'=>false, 'sanitize_callback'=>'sanitize_text_field' ),
                    'depth'=>array( 'required'=>false, 'sanitize_callback'=>'absint' ),
                    'hint_group'=>array( 'required'=>false, 'sanitize_callback'=>'sanitize_key' ),
                    'hint_slug'=>array( 'required'=>false, 'sanitize_callback'=>'sanitize_title' ),
                    'hint_label'=>array( 'required'=>false, 'sanitize_callback'=>'sanitize_text_field' ),
                    'other'=>array( 'required'=>false, 'sanitize_callback'=>'sanitize_text_field' ),
                ),
            ) );

            register_rest_route( 'taxocommerce-dependiente/v1', '/feedback', array(
                'methods'=>'POST',
                'callback'=>array( __CLASS__, 'feedback' ),
                'permission_callback'=>'__return_true',
                'args'=>array(
                    'q'=>array( 'required'=>true, 'sanitize_callback'=>'sanitize_text_field' ),
                    'session'=>array( 'required'=>true, 'sanitize_callback'=>'sanitize_text_field' ),
                    'event'=>array( 'required'=>true, 'sanitize_callback'=>'sanitize_key' ),
                    'product_id'=>array( 'required'=>false, 'sanitize_callback'=>'absint' ),
                    'depth'=>array( 'required'=>false, 'sanitize_callback'=>'absint' ),
                    'confidence'=>array( 'required'=>false, 'sanitize_callback'=>'floatval' ),
                ),
            ) );
        } );

        /*
         * El shortcode canónico del plugin moderno es distinto del legado para
         * permitir comparación A/B con ambos plugins instalados.
         */
        add_action( 'init', array( __CLASS__, 'register_shortcodes' ), 100 );
    }

    public static function register_shortcodes() {
        add_shortcode( 'taxocommerce_dependiente_moderno', array( __CLASS__, 'shortcode' ) );

        if ( self::modern_alias_enabled() ) {
            add_shortcode( 'taxocommerce_dependiente', array( __CLASS__, 'shortcode' ) );
        }
    }

    private static function modern_alias_enabled() {
        if ( function_exists( 'wp_get_environment_type' ) && 'staging' === wp_get_environment_type() ) {
            return true;
        }

        $host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $host = is_string( $host ) ? strtolower( $host ) : '';
        if ( 0 === strpos( $host, 'staging.' ) || false !== strpos( $host, '.staging.' ) ) {
            return true;
        }

        return ! defined( 'SEO_DEPENDIENTE_VERSION' );
    }

    public static function search( $request ) {
        $q = trim( sanitize_text_field( (string) $request->get_param( 'q' ) ) );
        if ( ! $q || mb_strlen( $q ) > 180 ) {
            return new WP_Error( 'tcd_invalid_query', 'Introduce una consulta de hasta 180 caracteres.', array( 'status'=>400 ) );
        }

        $session = trim( sanitize_text_field( (string) $request->get_param( 'session' ) ) );
        if ( ! TCD_Feedback::session_hash( $session ) ) {
            $session = wp_generate_uuid4();
        }

        $depth = min( 2, absint( $request->get_param( 'depth' ) ) );
        $hint_group = sanitize_key( (string) $request->get_param( 'hint_group' ) );
        $hint_slug = sanitize_title( (string) $request->get_param( 'hint_slug' ) );
        $hint_label = trim( sanitize_text_field( (string) $request->get_param( 'hint_label' ) ) );
        $other = trim( sanitize_text_field( (string) $request->get_param( 'other' ) ) );

        $effective_query = $q;
        $runtime_hints = array();

        if ( $hint_group && $hint_slug && $hint_label ) {
            $runtime_hints[] = array(
                'group'=>$hint_group,
                'slug'=>$hint_slug,
                'label'=>$hint_label,
                'weight'=>280,
                'source'=>'customer_clarification',
            );
            TCD_Feedback::record_clarification(
                $q,
                $session,
                array( 'group'=>$hint_group, 'slug'=>$hint_slug, 'label'=>$hint_label ),
                $depth,
                100
            );
        } elseif ( $other ) {
            $effective_query = trim( $q . ' ' . $other );
            TCD_Feedback::record_other( $q, $session, $other, $depth );
        }

        $interpretation = TCD_Intelligence::interpret( $effective_query, '', $runtime_hints );
        $ranked = TCD_Intelligence::search_products( $effective_query, 24, '', $runtime_hints );
        $decision = TCD_Intelligence::decision( $effective_query, $interpretation, $ranked );
        $suggestions = TCD_Intelligence::suggestions_from_products( $ranked, 8 );

        $clarification = array();
        if ( ! empty( $decision['needs_choice'] ) && $depth < 2 ) {
            $choice = TCD_Feedback::clarification_options( $q, $ranked, $interpretation, 5 );
            if ( ! empty( $choice['options'] ) && count( (array) $choice['options'] ) >= 2 ) {
                $clarification = array(
                    'prompt'=>(string) ( $choice['prompt'] ?? '¿Cuál encaja mejor?' ),
                    'group'=>(string) ( $choice['group'] ?? '' ),
                    'depth'=>$depth + 1,
                    'max_depth'=>2,
                    'options'=>array_values( (array) $choice['options'] ),
                    'allow_other'=>true,
                );
                $decision['message'] = $clarification['prompt'];
            }
        }

        if ( ! empty( $decision['needs_choice'] ) && $depth >= 2 && ! $clarification ) {
            $decision['message'] = 'Con estas dos aclaraciones todavía no tengo evidencia suficiente para recomendar un producto concreto. Puedes elegir una familia sugerida o reformular la necesidad.';
        }

        $results = ! empty( $decision['show_products'] ) ? array_slice( $ranked, 0, 8 ) : array();
        $interpreter = TCD_Intelligence::interpreter_state();
        $state = TCD_Academy::state();

        TCD_Feedback::record_search( $q, $session, $depth, $decision, $ranked, $clarification );

        return rest_ensure_response( array(
            'session'=>$session,
            'query'=>$q,
            'effective_query'=>$effective_query,
            'answer'=>(string) ( $decision['message'] ?? '' ),
            'results'=>$results,
            'suggestions'=>$suggestions,
            'clarification'=>$clarification,
            'decision'=>$decision,
            'interpretation'=>array(
                'normalized'=>(string) ( $interpretation['normalized'] ?? '' ),
                'concepts'=>array_values( (array) ( $interpretation['groups'] ?? array() ) ),
                'actions'=>array_values( (array) ( $interpretation['actions'] ?? array() ) ),
                'semantic_hits'=>count( (array) ( $interpretation['semantic_hits'] ?? array() ) ),
            ),
            'knowledge_ready'=>'completed' === (string) ( $state['status'] ?? '' ),
            'interpreter_status'=>(string) ( $interpreter['status'] ?? 'building' ),
        ) );
    }

    public static function feedback( $request ) {
        $event = sanitize_key( (string) $request->get_param( 'event' ) );
        if ( 'click' !== $event ) {
            return new WP_Error( 'tcd_feedback_event', 'Evento de feedback no soportado.', array( 'status'=>400 ) );
        }

        $result = TCD_Feedback::record_click(
            (string) $request->get_param( 'q' ),
            (string) $request->get_param( 'session' ),
            absint( $request->get_param( 'product_id' ) ),
            min( 2, absint( $request->get_param( 'depth' ) ) ),
            (float) $request->get_param( 'confidence' )
        );

        if ( is_wp_error( $result ) ) return $result;

        return rest_ensure_response( array(
            'ok'=>true,
            'stored'=>! empty( $result['stored'] ),
            'duplicate'=>! empty( $result['duplicate'] ),
            'aggregate'=>(array) ( $result['aggregate'] ?? array() ),
        ) );
    }

    public static function shortcode() {
        $id = wp_unique_id( 'tcd-search-' );
        $input_id = $id . '-input';
        $endpoint = rest_url( 'taxocommerce-dependiente/v1/search' );
        $feedback_endpoint = rest_url( 'taxocommerce-dependiente/v1/feedback' );
        ob_start(); ?>
        <div id="<?php echo esc_attr( $id ); ?>" class="tcd-assistant" data-search="<?php echo esc_url( $endpoint ); ?>" data-feedback="<?php echo esc_url( $feedback_endpoint ); ?>">
            <form class="tcd-assistant-form" action="<?php echo esc_url( $endpoint ); ?>" method="get">
                <label for="<?php echo esc_attr( $input_id ); ?>"><strong>¿Qué herramienta o información necesitas?</strong></label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
                    <input id="<?php echo esc_attr( $input_id ); ?>" name="q" required maxlength="180" type="search" style="flex:1;min-width:240px">
                    <button type="submit">Preguntar a Dependiente</button>
                </div>
            </form>
            <div class="tcd-assistant-status" aria-live="polite" style="margin-top:12px"></div>
            <div class="tcd-assistant-clarification" style="margin-top:12px"></div>
            <div class="tcd-assistant-results" style="margin-top:12px"></div>
            <div class="tcd-assistant-suggestions" style="margin-top:12px"></div>
            <p class="tcd-assistant-note">Dependiente interpreta la necesidad con conocimiento semántico, memoria L9 y feedback validado de uso real.</p>
        </div>
        <script>
        (function(){
            var root=document.getElementById(<?php echo wp_json_encode( $id ); ?>);
            if(!root)return;
            var form=root.querySelector('.tcd-assistant-form');
            var input=form.querySelector('input[name="q"]');
            var status=root.querySelector('.tcd-assistant-status');
            var clarification=root.querySelector('.tcd-assistant-clarification');
            var results=root.querySelector('.tcd-assistant-results');
            var suggestions=root.querySelector('.tcd-assistant-suggestions');
            var searchEndpoint=root.getAttribute('data-search');
            var feedbackEndpoint=root.getAttribute('data-feedback');
            var storageKey='tcd_feedback_session';
            var session='';
            try{session=sessionStorage.getItem(storageKey)||'';}catch(e){}
            if(!session){
                session=(window.crypto&&crypto.randomUUID)?crypto.randomUUID():('tcd-'+Date.now()+'-'+Math.random().toString(36).slice(2));
                try{sessionStorage.setItem(storageKey,session);}catch(e){}
            }
            var currentQuery='';
            var currentDepth=0;
            var lastConfidence=0;

            function esc(value){
                var div=document.createElement('div');
                div.textContent=value==null?'':String(value);
                return div.innerHTML;
            }

            function clear(){
                clarification.innerHTML='';
                results.innerHTML='';
                suggestions.innerHTML='';
            }

            function request(query,depth,hint,other){
                currentQuery=query;
                currentDepth=depth||0;
                status.textContent='Buscando…';
                clear();
                var url=new URL(searchEndpoint,window.location.origin);
                url.searchParams.set('q',query);
                url.searchParams.set('session',session);
                url.searchParams.set('depth',String(currentDepth));
                if(hint){
                    url.searchParams.set('hint_group',hint.group||'');
                    url.searchParams.set('hint_slug',hint.slug||'');
                    url.searchParams.set('hint_label',hint.label||'');
                }
                if(other)url.searchParams.set('other',other);
                fetch(url.toString(),{credentials:'same-origin'})
                    .then(function(r){return r.json().then(function(j){if(!r.ok)throw j;return j;});})
                    .then(render)
                    .catch(function(err){
                        status.textContent=(err&&err.message)?err.message:'No se pudo consultar Dependiente.';
                    });
            }

            function render(data){
                if(data.session&&data.session!==session){
                    session=data.session;
                    try{sessionStorage.setItem(storageKey,session);}catch(e){}
                }
                lastConfidence=data.decision&&data.decision.confidence?Number(data.decision.confidence):0;
                status.innerHTML='<strong>'+esc(data.answer||'')+'</strong>';

                var c=data.clarification||{};
                if(c.options&&c.options.length){
                    var html='<div><strong>'+esc(c.prompt||'¿Cuál encaja mejor?')+'</strong></div><div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">';
                    c.options.forEach(function(option,index){
                        html+='<button type="button" class="button tcd-clarify" data-index="'+index+'">'+esc(option.label||option.name||'Opción')+'</button>';
                    });
                    if(c.allow_other)html+='<button type="button" class="button tcd-other">Otro</button>';
                    html+='</div><div class="tcd-other-box" style="display:none;margin-top:8px"></div>';
                    clarification.innerHTML=html;

                    clarification.querySelectorAll('.tcd-clarify').forEach(function(button){
                        button.addEventListener('click',function(){
                            var option=c.options[Number(button.getAttribute('data-index'))]||{};
                            request(currentQuery,Number(c.depth||1),{
                                group:option.group||c.group||'',
                                slug:option.slug||'',
                                label:option.label||option.name||''
                            },'');
                        });
                    });

                    var otherButton=clarification.querySelector('.tcd-other');
                    if(otherButton){
                        otherButton.addEventListener('click',function(){
                            var box=clarification.querySelector('.tcd-other-box');
                            box.style.display='block';
                            box.innerHTML='<input type="text" maxlength="120" placeholder="Descríbelo brevemente"> <button type="button" class="button button-primary">Continuar</button>';
                            var text=box.querySelector('input');
                            box.querySelector('button').addEventListener('click',function(){
                                var value=(text.value||'').trim();
                                if(value)request(currentQuery,Number(c.depth||1),null,value);
                            });
                            text.focus();
                        });
                    }
                }

                var rows=data.results||[];
                if(rows.length){
                    var rh='<div class="tcd-result-list">';
                    rows.forEach(function(item){
                        rh+='<div class="tcd-result" style="padding:10px 0;border-bottom:1px solid #ddd"><a class="tcd-product-link" data-product="'+Number(item.id||0)+'" href="'+esc(item.url||'#')+'"><strong>'+esc(item.title||'Producto')+'</strong></a></div>';
                    });
                    rh+='</div>';
                    results.innerHTML=rh;
                    results.querySelectorAll('.tcd-product-link').forEach(function(link){
                        link.addEventListener('click',function(){
                            var payload={
                                q:currentQuery,
                                session:session,
                                event:'click',
                                product_id:Number(link.getAttribute('data-product')||0),
                                depth:currentDepth,
                                confidence:lastConfidence
                            };
                            try{
                                fetch(feedbackEndpoint,{
                                    method:'POST',
                                    credentials:'same-origin',
                                    keepalive:true,
                                    headers:{'Content-Type':'application/json'},
                                    body:JSON.stringify(payload)
                                });
                            }catch(e){}
                        });
                    });
                }

                var cats=data.suggestions||[];
                if(!rows.length&&cats.length){
                    var sh='<div><strong>Familias relacionadas:</strong></div><ul>';
                    cats.forEach(function(item){sh+='<li>'+esc(item.name||item.label||'')+'</li>';});
                    sh+='</ul>';
                    suggestions.innerHTML=sh;
                }
            }

            form.addEventListener('submit',function(e){
                e.preventDefault();
                var query=(input.value||'').trim();
                if(query)request(query,0,null,'');
            });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }
}
