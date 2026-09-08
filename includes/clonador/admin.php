<?php
/**
 * SEO System - Interfaz del Clonador PRO -> STAGING.
 *
 * @package SEOSystem
 * @subpackage Clonador
 * @since 2.5.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'seo_clonador_render' ) ) {
    function seo_clonador_render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para usar el Clonador.', 'seo-system' ) );
        }

        $nonce = wp_create_nonce( SEO_Clonador_Engine::NONCE_ACTION );
        $current = function_exists( 'seo_clonador_current_env' ) ? seo_clonador_current_env() : '';
        $pro = function_exists( 'seo_clonador_db_settings' ) ? seo_clonador_db_settings( 'pro' ) : [];
        $stg = function_exists( 'seo_clonador_db_settings' ) ? seo_clonador_db_settings( 'staging' ) : [];
        $ready = ! empty( $pro['enabled'] ) && ! empty( $stg['enabled'] ) && ! empty( $stg['destructive_clone'] );
        ?>
        <style>
            .seo-clonador-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin:18px 0}
            .seo-clonador-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:18px}
            .seo-clonador-warning{border-left:5px solid #d63638;background:#fff7f7;padding:14px 16px;margin:18px 0}
            .seo-clonador-ok{border-left:5px solid #00a32a;background:#f0f8f1;padding:14px 16px;margin:18px 0}
            .seo-clonador-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:18px 0}
            .seo-clonador-status{font-weight:600}
            .seo-clonador-plan{display:none;max-height:520px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:14px;border-radius:5px;white-space:pre-wrap;font:12px/1.45 monospace}
            .seo-clonador-summary table{border-collapse:collapse;width:100%;max-width:900px}
            .seo-clonador-summary th,.seo-clonador-summary td{border-bottom:1px solid #dcdcde;padding:7px 8px;text-align:left}
        </style>

        <div class="seo-clonador">
            <div class="seo-clonador-card">
                <h1 style="margin-top:0;font-size:30px;line-height:1.2;">Clonador para Academia</h1>
                <p style="font-size:15px;margin-top:-8px;"><strong>PRO → STAGING</strong> · Datos base para Academia · v<?php echo esc_html( SEO_CLONADOR_VERSION ); ?></p>
                <p><strong>PRO es siempre el origen. STAGING es siempre el destino.</strong> No existe ninguna operación STAGING → PRO.</p>
                <p>La clonación no intenta conservar el catálogo anterior de STAGING: elimina el perímetro gestionado y lo reconstruye desde PRO. Los IDs de PRO nunca se insertan como IDs de destino; solo se usan temporalmente para construir mapas hacia los nuevos IDs locales de STAGING.</p>
                <p>Entorno desde el que has abierto la pantalla: <strong><?php echo esc_html( $current ? strtoupper( $current ) : 'NO IDENTIFICADO' ); ?></strong>. La dirección no cambia.</p>
            </div>

            <div class="seo-clonador-warning">
                <strong>Operación destructiva en STAGING.</strong>
                Se eliminan y reconstruyen los objetos/taxonomías/tablas gestionados. No se hace <code>TRUNCATE</code> de tablas compartidas como <code>wp_posts</code> o <code>wp_terms</code>; el vaciado es por perímetro. PRO nunca se modifica.
            </div>

            <?php if ( function_exists( 'seo_clonador_db_render_connections' ) ) { seo_clonador_db_render_connections(); } ?>

            <div class="seo-clonador-actions">
                <button type="button" class="button button-primary" id="seo-clonador-preview" <?php disabled( ! $ready ); ?>>Simular clonación</button>
                <button type="button" class="button button-primary" id="seo-clonador-apply" disabled>CLONAR PRO → STAGING</button>
                <button type="button" class="button" id="seo-clonador-download" disabled>Descargar JSON del plan</button>
                <span class="seo-clonador-status" id="seo-clonador-status"><?php echo $ready ? 'Preparado para simular.' : 'Configura PRO/STAGING y autoriza clonación destructiva en STAGING.'; ?></span>
            </div>

            <div class="seo-clonador-summary" id="seo-clonador-summary"></div>
            <pre class="seo-clonador-plan" id="seo-clonador-plan"></pre>
        </div>

        <script>
        (()=>{
            const ajax=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            const nonce=<?php echo wp_json_encode( $nonce ); ?>;
            const previewBtn=document.getElementById('seo-clonador-preview');
            const applyBtn=document.getElementById('seo-clonador-apply');
            const downloadBtn=document.getElementById('seo-clonador-download');
            const status=document.getElementById('seo-clonador-status');
            const summary=document.getElementById('seo-clonador-summary');
            const plan=document.getElementById('seo-clonador-plan');
            let lastPlan=null;

            async function post(action, extra={}){
                const fd=new FormData();
                fd.append('action',action);fd.append('nonce',nonce);
                Object.entries(extra).forEach(([k,v])=>fd.append(k,v));
                const r=await fetch(ajax,{method:'POST',credentials:'same-origin',body:fd});
                const j=await r.json();
                if(!j.success)throw new Error((j.data&&j.data.message)||'Error del Clonador');
                return j.data||{};
            }
            function busy(on){
                if(previewBtn)previewBtn.disabled=on;
                if(applyBtn)applyBtn.disabled=on||!lastPlan||!lastPlan.can_apply;
            }
            function render(data){
                lastPlan=data;
                const rows=[];
                const actions=data.actions||{};
                Object.entries(actions.objects||{}).forEach(([k,v])=>rows.push([k,v.source||0,v.staging_before||0,v.delete_from_staging||0,v.create_from_pro||0]));
                Object.entries(actions.taxonomies||{}).forEach(([k,v])=>rows.push([k,v.source||0,v.staging_before||0,v.delete_from_staging||0,v.create_from_pro||0]));
                Object.entries(actions.custom_tables||{}).forEach(([k,v])=>rows.push([k,v.source||0,v.staging_before||0,v.delete_from_staging||0,v.create_from_pro||0]));
                Object.entries(actions.woocommerce||{}).forEach(([k,v])=>{if(v.available!==false)rows.push(['Woo · '+k,v.source||0,v.staging_before||0,v.delete_from_staging||0,v.create_from_pro||0]);});
                let html='<h3>Plan de clonación</h3><table><thead><tr><th>Ámbito</th><th>PRO</th><th>STAGING actual</th><th>Eliminar</th><th>Crear</th></tr></thead><tbody>';
                rows.forEach(r=>{html+='<tr>'+r.map((x,i)=>'<'+(i?'td':'th')+'>'+String(x).replace(/[&<>]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m]))+'</'+(i?'td':'th')+'>').join('')+'</tr>';});
                html+='</tbody></table>';
                if((data.warnings||[]).length)html+='<div class="notice notice-warning inline"><p><strong>Warnings:</strong> '+data.warnings.length+'. Revisa el JSON antes de APPLY.</p></div>';
                if((data.conflicts||[]).length)html+='<div class="notice notice-error inline"><p><strong>Conflictos:</strong> '+data.conflicts.length+'. APPLY bloqueado.</p></div>';
                summary.innerHTML=html;
                plan.style.display='block';plan.textContent=JSON.stringify(data,null,2);
                downloadBtn.disabled=false;
                applyBtn.disabled=!data.can_apply;
                status.textContent=data.can_apply?'Simulación correcta. Revisa el plan y, si procede, ejecuta CLONAR PRO → STAGING.':'Simulación bloqueada por conflictos.';
            }
            previewBtn&&previewBtn.addEventListener('click',async()=>{
                busy(true);status.textContent='Simulando. Cero escrituras...';summary.innerHTML='';plan.style.display='none';lastPlan=null;downloadBtn.disabled=true;
                try{const data=await post('seo_clonador_preview');render(data);}catch(e){status.textContent='Simulación fallida: '+e.message;}finally{previewBtn.disabled=false;if(lastPlan)applyBtn.disabled=!lastPlan.can_apply;}
            });
            applyBtn&&applyBtn.addEventListener('click',async()=>{
                if(!lastPlan||!lastPlan.can_apply)return;
                if(!window.confirm('CLONAR PRO → STAGING\n\nSe eliminará el perímetro gestionado actual de STAGING y se reconstruirá desde PRO. PRO no se modifica.\n\n¿Continuar?'))return;
                busy(true);status.textContent='Clonando PRO → STAGING...';
                try{const data=await post('seo_clonador_apply',{confirm:'1'});status.textContent=(data.message||'Clonación terminada.')+' '+(data.seconds||0)+' s.';applyBtn.disabled=true;lastPlan=null;}catch(e){status.textContent='Clonación fallida/revertida: '+e.message;applyBtn.disabled=false;}finally{previewBtn.disabled=false;}
            });
            downloadBtn&&downloadBtn.addEventListener('click',()=>{
                if(!lastPlan)return;
                const blob=new Blob([JSON.stringify(lastPlan,null,2)],{type:'application/json'});
                const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='seo-clonador-dry-run-'+new Date().toISOString().replace(/[:.]/g,'-')+'.json';document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(a.href),500);
            });
        })();
        </script>
        <?php
    }
}
