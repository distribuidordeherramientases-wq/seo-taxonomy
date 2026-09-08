<?php
/**
 * SEO System - Interfaz del Clonador PRO -> STAGING.
 *
 * @package SEOSystem
 * @subpackage Clonador
 * @since 2.5.5
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
            .seo-clonador-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:18px 0}
            .seo-clonador-status{font-weight:600}
            .seo-clonador-worker{margin:12px 0;padding:10px 12px;background:#f6f7f7;border-left:4px solid #2271b1;font-size:12px;color:#50575e}
            .seo-clonador-plan{display:none;max-height:520px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:14px;border-radius:5px;white-space:pre-wrap;font:12px/1.45 monospace}
            .seo-clonador-summary table,.seo-clonador-final-table{border-collapse:collapse;width:100%;max-width:1100px}
            .seo-clonador-summary th,.seo-clonador-summary td,.seo-clonador-final-table th,.seo-clonador-final-table td{border-bottom:1px solid #dcdcde;padding:7px 8px;text-align:left}
            .seo-clonador-live{display:none;margin:20px 0;border:2px solid #2271b1;border-radius:8px;background:#fff;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .seo-clonador-live.is-running{display:block;border-color:#2271b1;background:#f6f9fc}
            .seo-clonador-live.is-success{display:block;border-color:#00a32a;background:#f0f8f1}
            .seo-clonador-live.is-failed{display:block;border-color:#d63638;background:#fff5f5}
            .seo-clonador-live-title{font-size:28px;line-height:1.15;font-weight:800;margin:0 0 8px;color:#1d2327}
            .seo-clonador-live.is-success .seo-clonador-live-title{color:#006b1b}
            .seo-clonador-live.is-failed .seo-clonador-live-title{color:#a00}
            .seo-clonador-live-phase{font-size:20px;font-weight:700;margin:8px 0}
            .seo-clonador-progress{height:18px;background:#dcdcde;border-radius:999px;overflow:hidden;margin:14px 0 6px}
            .seo-clonador-progress>span{display:block;height:100%;background:#2271b1;width:0;transition:width .25s ease}
            .seo-clonador-live.is-success .seo-clonador-progress>span{background:#00a32a}
            .seo-clonador-live.is-failed .seo-clonador-progress>span{background:#d63638}
            .seo-clonador-live-meta{display:flex;gap:18px;flex-wrap:wrap;font-size:14px;margin:8px 0 14px}
            .seo-clonador-table-block{margin-top:14px}
            .seo-clonador-table-block strong{display:block;font-size:14px;margin-bottom:6px}
            .seo-clonador-table-tags{display:flex;gap:6px;flex-wrap:wrap}
            .seo-clonador-table-tag{display:inline-block;padding:4px 8px;border:1px solid #c3c4c7;border-radius:4px;background:#fff;font:12px/1.35 monospace}
            .seo-clonador-table-tag.current{border-color:#2271b1;background:#eef6ff;font-weight:700}
            .seo-clonador-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:16px 0}
            .seo-clonador-kpi{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:12px}
            .seo-clonador-kpi.ok{border-left:5px solid #00a32a}
            .seo-clonador-kpi.bad{border-left:5px solid #d63638}
            .seo-clonador-kpi-label{font-size:12px;color:#50575e;margin-bottom:4px}
            .seo-clonador-kpi-value{font-size:20px;font-weight:800}
            .seo-clonador-error{font-size:15px;font-weight:600;color:#a00;margin-top:10px;word-break:break-word}
            .seo-clonador-pending{max-height:155px;overflow:auto;padding-right:4px}
        </style>

        <div class="seo-clonador">
            <div class="seo-clonador-card">
                <h1 style="margin-top:0;font-size:30px;line-height:1.2;">Clonador para Academia</h1>
                <p style="font-size:15px;margin-top:-8px;"><strong>PRO → STAGING</strong> · Datos base para Academia · v<?php echo esc_html( SEO_CLONADOR_VERSION ); ?></p>
                <p><strong>PRO es siempre el origen. STAGING es siempre el destino.</strong> No existe ninguna operación STAGING → PRO.</p>
                <p>La clonación elimina el perímetro gestionado de STAGING y lo reconstruye desde PRO. Los IDs de PRO solo sirven para generar mapas temporales hacia los nuevos IDs locales de STAGING.</p>
                <p>Entorno desde el que has abierto la pantalla: <strong><?php echo esc_html( $current ? strtoupper( $current ) : 'NO IDENTIFICADO' ); ?></strong>.</p>
                <p><strong>APPLY se ejecuta en el Gestor de procesos.</strong> El panel inferior muestra en grande la fase actual, las tablas en curso, las pendientes y el resultado de verificación final.</p>
            </div>

            <div class="seo-clonador-warning">
                <strong>Operación destructiva en STAGING.</strong>
                Se eliminan y reconstruyen los objetos/taxonomías/tablas gestionados. No se hace <code>TRUNCATE</code> de tablas compartidas como <code>wp_posts</code> o <code>wp_terms</code>. PRO nunca se modifica.
            </div>

            <?php if ( function_exists( 'seo_clonador_db_render_connections' ) ) { seo_clonador_db_render_connections(); } ?>

            <div class="seo-clonador-actions">
                <button type="button" class="button button-primary" id="seo-clonador-preview" <?php disabled( ! $ready ); ?>>Simular clonación</button>
                <button type="button" class="button button-primary" id="seo-clonador-apply" disabled>CLONAR PRO → STAGING</button>
                <button type="button" class="button" id="seo-clonador-download" disabled>Descargar JSON del plan</button>
                <span class="seo-clonador-status" id="seo-clonador-status"><?php echo $ready ? 'Preparado para simular.' : 'Configura PRO/STAGING y autoriza clonación destructiva en STAGING.'; ?></span>
            </div>

            <div class="seo-clonador-live" id="seo-clonador-live"></div>
            <div class="seo-clonador-worker" id="seo-clonador-worker" style="display:none"></div>
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
            const workerBox=document.getElementById('seo-clonador-worker');
            const live=document.getElementById('seo-clonador-live');
            let lastPlan=null;
            let pollTimer=null;

            const esc=(v)=>String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
            const n=(v)=>Number(v||0).toLocaleString('es-ES');

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
                rows.forEach(r=>{html+='<tr>'+r.map((x,i)=>'<'+(i?'td':'th')+'>'+esc(x)+'</'+(i?'td':'th')+'>').join('')+'</tr>';});
                html+='</tbody></table>';
                if((data.warnings||[]).length)html+='<div class="notice notice-warning inline"><p><strong>Warnings:</strong> '+n(data.warnings.length)+'. Revisa el JSON antes de APPLY.</p></div>';
                if((data.conflicts||[]).length)html+='<div class="notice notice-error inline"><p><strong>Conflictos:</strong> '+n(data.conflicts.length)+'. APPLY bloqueado.</p></div>';
                summary.innerHTML=html;
                plan.style.display='block';plan.textContent=JSON.stringify(data,null,2);
                downloadBtn.disabled=false;
                applyBtn.disabled=!data.can_apply;
                status.textContent=data.can_apply?'Simulación correcta. Revisa el plan y ejecuta CLONAR cuando quieras.':'Simulación bloqueada por conflictos.';
            }
            function renderTables(title,tables,current=false){
                tables=Array.isArray(tables)?tables:[];
                if(!tables.length)return '';
                return '<div class="seo-clonador-table-block"><strong>'+esc(title)+'</strong><div class="seo-clonador-table-tags '+(!current?'seo-clonador-pending':'')+'">'+tables.map(t=>'<span class="seo-clonador-table-tag '+(current?'current':'')+'">'+esc(t)+'</span>').join('')+'</div></div>';
            }
            function verificationFrom(job){
                if(job&&job.result&&job.result.verification)return job.result.verification;
                if(job&&job.stats&&job.stats.verification)return job.stats.verification;
                return null;
            }
            function renderKpis(verification){
                verification=verification||{};
                const checks=verification.critical_kpis||{};
                const wanted=[
                    'post_type:product','taxonomy:product_cat','kpi:products_with_category','kpi:products_without_category',
                    'kpi:products_with_seo_attributes','kpi:products_without_seo_attributes','table:sql_product_atributos','term_relationships','table:seo_faq','table:seo_vocabulary','table:seo_object_vocabulary'
                ];
                const cards=[];
                wanted.forEach(key=>{
                    const c=checks[key]; if(!c)return;
                    cards.push('<div class="seo-clonador-kpi '+(c.ok?'ok':'bad')+'"><div class="seo-clonador-kpi-label">'+esc(c.label||key)+'</div><div class="seo-clonador-kpi-value">'+(c.ok?'✓ ':'✕ ')+n(c.staging)+'</div><div>PRO '+n(c.pro)+' · STAGING '+n(c.staging)+'</div></div>');
                });
                const sm=verification.summary||{};
                let html=cards.length?'<div class="seo-clonador-kpis">'+cards.join('')+'</div>':'';
                if(sm.checks_total!==undefined)html+='<p><strong>Verificaciones:</strong> '+n(sm.passed)+' correctas de '+n(sm.checks_total)+(Number(sm.failed||0)?' · <strong>'+n(sm.failed)+' fallidas</strong>':'')+'.</p>';
                return html;
            }
            function renderAllChecks(verification){
                verification=verification||{};
                const checks=verification.checks||{};
                const rows=Object.entries(checks);
                if(!rows.length)return '';
                let html='<details style="margin-top:14px" '+(verification.passed===false?'open':'')+'><summary><strong>Ver todos los controles finales ('+n(rows.length)+')</strong></summary><div style="overflow:auto;margin-top:8px"><table class="seo-clonador-final-table"><thead><tr><th>Control</th><th>PRO</th><th>STAGING</th><th>Resultado</th></tr></thead><tbody>';
                rows.forEach(([key,c])=>{html+='<tr><th>'+esc(c.label||key)+'</th><td>'+n(c.pro)+'</td><td>'+n(c.staging)+'</td><td><strong>'+(c.ok?'✓ OK':'✕ ERROR')+'</strong></td></tr>';});
                html+='</tbody></table></div></details>';
                return html;
            }
            function renderJob(job){
                job=job||{};
                const state=String(job.status||'idle');
                const active=['queued','dispatching','running'].includes(state);
                const progress=job.progress||{};
                const pct=Math.max(0,Math.min(100,Number(progress.percent||0)));
                const verification=verificationFrom(job);

                if(workerBox){
                    if(state==='idle'||!job.job_id){workerBox.style.display='none';workerBox.textContent='';}
                    else{
                        workerBox.style.display='block';
                        workerBox.textContent='Job '+(job.job_id||'')+' · '+state+' · fase '+(job.phase||'')+' · gestor '+(job.backend||'process_manager');
                    }
                }

                if(state==='idle'||!job.job_id){
                    live.className='seo-clonador-live'; live.innerHTML='';
                    return;
                }

                if(active){
                    previewBtn.disabled=true;applyBtn.disabled=true;
                    const verb=(progress.kind==='delete')?'VACIANDO STAGING':(progress.kind==='verify'?'VERIFICANDO CLONACIÓN':'CLONANDO PRO → STAGING');
                    live.className='seo-clonador-live is-running';
                    live.innerHTML='<div class="seo-clonador-live-title">'+verb+'</div>'+
                        '<div class="seo-clonador-live-phase">'+esc(progress.phase_label||job.message||job.phase||'Procesando')+'</div>'+
                        '<div class="seo-clonador-progress"><span style="width:'+pct+'%"></span></div>'+
                        '<div class="seo-clonador-live-meta"><span><strong>'+pct+'%</strong></span><span>Fase '+n(progress.phase_number||0)+' / '+n(progress.phase_total||0)+'</span><span>Copiado: <strong>'+n(progress.copied_total||0)+'</strong> filas/objetos</span><span>Warnings: '+n(progress.warnings_total||(job.warnings||[]).length)+'</span></div>'+
                        renderTables('Tabla(s) en proceso',progress.current_tables||[],true)+
                        renderTables('Tablas que quedan pendientes',progress.pending_tables||[],false)+
                        renderTables('Tablas ya completadas',progress.completed_tables||[],false)+
                        '<p style="margin-bottom:0"><strong>Estado:</strong> '+esc(job.message||'Procesando por lotes.')+'</p>';
                    status.textContent='Clonación en curso por worker. Puedes cambiar de pantalla.';
                    return;
                }

                if(state==='completed' && verification && verification.passed===true){
                    previewBtn.disabled=false;applyBtn.disabled=true;lastPlan=null;
                    const secs=(job.result&&job.result.duration_seconds)||0;
                    const copied=(job.result&&job.result.copied_total)||progress.copied_total||0;
                    live.className='seo-clonador-live is-success';
                    live.innerHTML='<div class="seo-clonador-live-title">✓ CLONACIÓN CORRECTA Y VERIFICADA</div>'+
                        '<div class="seo-clonador-live-phase">PRO y STAGING coinciden en todos los controles del perímetro clonado.</div>'+
                        '<div class="seo-clonador-progress"><span style="width:100%"></span></div>'+
                        '<div class="seo-clonador-live-meta"><span><strong>100%</strong></span><span>Copiado: <strong>'+n(copied)+'</strong> filas/objetos</span><span>Duración: '+n(secs)+' s</span><span>Warnings: '+n((job.result&&job.result.warnings_total)||(job.warnings||[]).length)+'</span></div>'+
                        renderKpis(verification)+renderAllChecks(verification);
                    status.textContent='Clonación terminada y VERIFICADA'+(secs?(' en '+secs+' s.') : '.');
                    if(pollTimer){clearInterval(pollTimer);pollTimer=null;}
                    return;
                }

                if(state==='completed'){
                    previewBtn.disabled=false;applyBtn.disabled=true;lastPlan=null;
                    live.className='seo-clonador-live is-failed';
                    live.innerHTML='<div class="seo-clonador-live-title">✕ FINALIZÓ SIN VERIFICACIÓN VÁLIDA</div><div class="seo-clonador-live-phase">No se marca como copia correcta porque falta un resultado final passed=true.</div>'+renderKpis(verification)+renderAllChecks(verification);
                    status.textContent='El job terminó, pero no existe una verificación final válida. No des por bueno STAGING.';
                    if(pollTimer){clearInterval(pollTimer);pollTimer=null;}
                    return;
                }

                if(state==='failed'){
                    previewBtn.disabled=false;applyBtn.disabled=true;lastPlan=null;
                    live.className='seo-clonador-live is-failed';
                    live.innerHTML='<div class="seo-clonador-live-title">✕ CLONACIÓN FALLIDA · STAGING INCOMPLETO</div>'+
                        '<div class="seo-clonador-live-phase">No se considera una copia válida.</div>'+
                        '<div class="seo-clonador-progress"><span style="width:'+pct+'%"></span></div>'+
                        '<div class="seo-clonador-live-meta"><span>Última fase: <strong>'+esc(progress.phase_label||job.phase||'desconocida')+'</strong></span><span>Copiado antes del fallo: <strong>'+n(progress.copied_total||0)+'</strong></span></div>'+
                        renderKpis(verification)+renderAllChecks(verification)+
                        '<div class="seo-clonador-error">'+esc(job.last_error||job.message||'Error desconocido')+'</div>';
                    status.textContent='Clonación FALLIDA. STAGING está incompleto; revisa el panel rojo antes de reiniciar.';
                    if(pollTimer){clearInterval(pollTimer);pollTimer=null;}
                }
            }
            async function refreshJob(){
                try{const data=await post('seo_clonador_status');renderJob(data.job||{});return data.job||{};}catch(e){return null;}
            }
            function startPolling(){
                if(pollTimer)return;
                refreshJob();
                pollTimer=setInterval(refreshJob,4000);
            }

            previewBtn&&previewBtn.addEventListener('click',async()=>{
                busy(true);status.textContent='Simulando. Cero escrituras...';summary.innerHTML='';plan.style.display='none';lastPlan=null;downloadBtn.disabled=true;
                try{const data=await post('seo_clonador_preview');render(data);}catch(e){status.textContent='Simulación fallida: '+e.message;}finally{previewBtn.disabled=false;if(lastPlan)applyBtn.disabled=!lastPlan.can_apply;}
            });
            applyBtn&&applyBtn.addEventListener('click',async()=>{
                if(!lastPlan||!lastPlan.can_apply)return;
                if(!window.confirm('CLONAR PRO → STAGING\n\nSe eliminará el perímetro gestionado actual de STAGING y se reconstruirá desde PRO. PRO no se modifica.\n\nEl worker irá mostrando tablas, pendientes y una verificación final verde/roja.\n\n¿Continuar?'))return;
                busy(true);status.textContent='Entregando clonación al Gestor de procesos...';
                try{
                    const data=await post('seo_clonador_apply',{confirm:'1'});
                    renderJob(data.job||{});
                    status.textContent=data.message||'Clonación entregada al Gestor de procesos.';
                    applyBtn.disabled=true;previewBtn.disabled=true;
                    startPolling();
                }catch(e){status.textContent='No se pudo iniciar la clonación: '+e.message;previewBtn.disabled=false;applyBtn.disabled=!lastPlan||!lastPlan.can_apply;}
            });
            downloadBtn&&downloadBtn.addEventListener('click',()=>{
                if(!lastPlan)return;
                const blob=new Blob([JSON.stringify(lastPlan,null,2)],{type:'application/json'});
                const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='seo-clonador-dry-run-'+new Date().toISOString().replace(/[:.]/g,'-')+'.json';document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(a.href),500);
            });
            refreshJob().then(job=>{if(job&&['queued','dispatching','running'].includes(String(job.status||'')))startPolling();});
        })();
        </script>
        <?php
    }
}
