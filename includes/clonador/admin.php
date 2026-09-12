<?php
/**
 * SEO System - Interfaz del Clonador PRO -> STAGING.
 *
 * @package SEOSystem
 * @subpackage Clonador
 * @since 2.5.9
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'seo_clonador_render' ) ) {
    function seo_clonador_render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para usar el Clonador.', 'seo-system' ) );
        }

        $nonce   = wp_create_nonce( SEO_Clonador_Engine::NONCE_ACTION );
        $current = function_exists( 'seo_clonador_current_env' ) ? seo_clonador_current_env() : '';
        $pro     = function_exists( 'seo_clonador_db_settings' ) ? seo_clonador_db_settings( 'pro' ) : array();
        $stg     = function_exists( 'seo_clonador_db_settings' ) ? seo_clonador_db_settings( 'staging' ) : array();
        $ready   = ! empty( $pro['enabled'] ) && ! empty( $stg['enabled'] ) && ! empty( $stg['destructive_clone'] );
        ?>
        <style>
            .seo-clonador-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin:18px 0}
            .seo-clonador-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:18px}
            .seo-clonador-warning{border-left:5px solid #d63638;background:#fff7f7;padding:14px 16px;margin:18px 0}
            .seo-clonador-manual{border-left:5px solid #2271b1;background:#eef6ff;padding:14px 16px;margin:18px 0;font-size:14px}
            .seo-clonador-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:18px 0;padding:14px;background:#fff;border:1px solid #dcdcde;border-radius:8px}
            .seo-clonador-actions .button{min-height:38px;font-weight:700}
            #seo-clonador-start{font-size:15px;padding-left:18px;padding-right:18px}
            #seo-clonador-stop{border-color:#d63638;color:#b32d2e}
            #seo-clonador-stop:not(:disabled):hover{background:#fff1f1;border-color:#b32d2e;color:#8a2424}
            .seo-clonador-status{font-weight:700;flex-basis:100%;padding-top:4px}
            .seo-clonador-speed{display:flex;align-items:center;gap:7px;padding:5px 8px;border:1px solid #c3c4c7;border-radius:6px;background:#f6f7f7}
            .seo-clonador-speed strong{min-width:125px;text-align:center}
            .seo-clonador-speed .button{min-height:30px;line-height:28px;padding:0 10px}
            .seo-clonador-worker{margin:12px 0;padding:10px 12px;background:#f6f7f7;border-left:4px solid #2271b1;font-size:12px;color:#50575e}
            .seo-clonador-plan{display:none;max-height:520px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:14px;border-radius:5px;white-space:pre-wrap;font:12px/1.45 monospace}
            .seo-clonador-summary table,.seo-clonador-final-table{border-collapse:collapse;width:100%;max-width:1100px}
            .seo-clonador-summary th,.seo-clonador-summary td,.seo-clonador-final-table th,.seo-clonador-final-table td{border-bottom:1px solid #dcdcde;padding:7px 8px;text-align:left}
            .seo-clonador-live{display:none;margin:20px 0;border:2px solid #2271b1;border-radius:8px;background:#fff;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .seo-clonador-live.is-running{display:block;border-color:#2271b1;background:#f6f9fc}
            .seo-clonador-live.is-paused{display:block;border-color:#dba617;background:#fff9e8}
            .seo-clonador-live.is-success{display:block;border-color:#00a32a;background:#f0f8f1}
            .seo-clonador-live.is-failed{display:block;border-color:#d63638;background:#fff5f5}
            .seo-clonador-live-title{font-size:30px;line-height:1.15;font-weight:800;margin:0 0 8px;color:#1d2327}
            .seo-clonador-live.is-paused .seo-clonador-live-title{color:#8a6116}
            .seo-clonador-live.is-success .seo-clonador-live-title{color:#006b1b}
            .seo-clonador-live.is-failed .seo-clonador-live-title{color:#a00}
            .seo-clonador-live-phase{font-size:21px;font-weight:700;margin:8px 0}
            .seo-clonador-progress{height:18px;background:#dcdcde;border-radius:999px;overflow:hidden;margin:14px 0 6px}
            .seo-clonador-progress>span{display:block;height:100%;background:#2271b1;width:0;transition:width .25s ease}
            .seo-clonador-live.is-paused .seo-clonador-progress>span{background:#dba617}
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
                <p>La clonación elimina el perímetro gestionado de STAGING y lo reconstruye desde PRO. Los IDs anteriores de STAGING no se conservan ni se comparan.</p>
                <p>Entorno desde el que has abierto la pantalla: <strong><?php echo esc_html( $current ? strtoupper( $current ) : 'NO IDENTIFICADO' ); ?></strong>.</p>
            </div>

            <div class="seo-clonador-manual">
                <strong>CONTROL MANUAL.</strong> El Gestor de procesos <strong>NO inicia</strong> una clonación. Tú la arrancas con el botón <strong>ARRANCAR CLONACIÓN</strong>. El worker únicamente entrega lotes pequeños mientras el proceso esté iniciado por ti. <strong>PARAR</strong> impide que se lance el siguiente lote.
            </div>

            <div class="seo-clonador-warning">
                <strong>Operación destructiva en STAGING.</strong>
                Se eliminan y reconstruyen los objetos/taxonomías/tablas gestionados. No se hace <code>TRUNCATE</code> de tablas compartidas como <code>wp_posts</code> o <code>wp_terms</code>. PRO nunca se modifica.
            </div>

            <?php if ( function_exists( 'seo_clonador_db_render_connections' ) ) { seo_clonador_db_render_connections(); } ?>

            <div class="seo-clonador-actions">
                <button type="button" class="button button-primary" id="seo-clonador-preview" <?php disabled( ! $ready ); ?>>1. Simular clonación</button>
                <button type="button" class="button button-primary" id="seo-clonador-start" disabled>2. ARRANCAR CLONACIÓN</button>
                <button type="button" class="button" id="seo-clonador-stop" disabled>PARAR</button>
                <button type="button" class="button" id="seo-clonador-reverify" style="display:none" disabled>REVERIFICAR COPIA</button>
                <div class="seo-clonador-speed" aria-label="Velocidad del Clonador">
                    <button type="button" class="button" id="seo-clonador-slower" title="Reducir velocidad">− FRENO</button>
                    <strong id="seo-clonador-speed-label">Velocidad 3/5 · Normal</strong>
                    <button type="button" class="button" id="seo-clonador-faster" title="Aumentar velocidad">ACELERADOR +</button>
                </div>
                <button type="button" class="button" id="seo-clonador-download" disabled>Descargar JSON del plan</button>
                <span class="seo-clonador-status" id="seo-clonador-status"><?php echo $ready ? 'Preparado. Primero SIMULA; nada se inicia al cargar esta pantalla.' : 'Configura PRO/STAGING y autoriza clonación destructiva en STAGING.'; ?></span>
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
            const ready=<?php echo $ready ? 'true' : 'false'; ?>;
            const previewBtn=document.getElementById('seo-clonador-preview');
            const startBtn=document.getElementById('seo-clonador-start');
            const stopBtn=document.getElementById('seo-clonador-stop');
            const reverifyBtn=document.getElementById('seo-clonador-reverify');
            const slowerBtn=document.getElementById('seo-clonador-slower');
            const fasterBtn=document.getElementById('seo-clonador-faster');
            const speedLabel=document.getElementById('seo-clonador-speed-label');
            const downloadBtn=document.getElementById('seo-clonador-download');
            const status=document.getElementById('seo-clonador-status');
            const summary=document.getElementById('seo-clonador-summary');
            const plan=document.getElementById('seo-clonador-plan');
            const workerBox=document.getElementById('seo-clonador-worker');
            const live=document.getElementById('seo-clonador-live');
            let lastPlan=null;
            let currentJob={};
            let desiredSpeed=3;
            let pollTimer=null;
            let requestBusy=false;
            const speedNames={1:'Muy suave',2:'Suave',3:'Normal',4:'Rápida',5:'Máxima'};

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
            function setSpeedUi(speed){
                desiredSpeed=Math.max(1,Math.min(5,Number(speed||3)));
                speedLabel.textContent='Velocidad '+desiredSpeed+'/5 · '+(speedNames[desiredSpeed]||'Normal');
                slowerBtn.disabled=requestBusy||desiredSpeed<=1;
                fasterBtn.disabled=requestBusy||desiredSpeed>=5;
            }
            function updateControls(){
                const state=String(currentJob.status||'idle');
                const running=state==='running' && currentJob.run_requested!==false;
                const paused=state==='paused';
                const canReverify=state==='failed' && String(currentJob.phase||'')==='verify';
                if(reverifyBtn){reverifyBtn.style.display=canReverify?'inline-block':'none';reverifyBtn.disabled=requestBusy||!canReverify;}
                previewBtn.disabled=requestBusy||!ready||running;
                stopBtn.disabled=requestBusy||!running;
                if(running){
                    startBtn.disabled=true;
                    startBtn.textContent='CLONACIÓN INICIADA';
                }else if(paused && !lastPlan){
                    startBtn.disabled=requestBusy;
                    startBtn.textContent='REANUDAR CLONACIÓN';
                }else{
                    startBtn.disabled=requestBusy||!lastPlan||!lastPlan.can_apply;
                    startBtn.textContent=paused&&lastPlan?'ARRANCAR NUEVA CLONACIÓN':'2. ARRANCAR CLONACIÓN';
                }
                downloadBtn.disabled=requestBusy||!lastPlan;
                setSpeedUi(desiredSpeed);
            }
            function setBusy(on){requestBusy=!!on;updateControls();}

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
                if((data.warnings||[]).length)html+='<div class="notice notice-warning inline"><p><strong>Warnings:</strong> '+n(data.warnings.length)+'. Revisa el JSON antes de arrancar.</p></div>';
                if((data.conflicts||[]).length)html+='<div class="notice notice-error inline"><p><strong>Conflictos:</strong> '+n(data.conflicts.length)+'. ARRANQUE bloqueado.</p></div>';
                summary.innerHTML=html;
                plan.style.display='block';plan.textContent=JSON.stringify(data,null,2);
                status.textContent=data.can_apply?'Simulación correcta. Nada se ha escrito. Pulsa ARRANCAR cuando tú decidas.':'Simulación bloqueada por conflictos.';
                updateControls();
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
                const wanted=['post_type:product','taxonomy:product_cat','kpi:products_with_category','kpi:products_without_category','kpi:products_with_seo_attributes','kpi:products_without_seo_attributes','table:sql_product_atributos','term_relationships','table:seo_faq','table:seo_vocabulary','table:seo_object_vocabulary'];
                const cards=[];
                wanted.forEach(key=>{const c=checks[key];if(!c)return;cards.push('<div class="seo-clonador-kpi '+(c.ok?'ok':'bad')+'"><div class="seo-clonador-kpi-label">'+esc(c.label||key)+'</div><div class="seo-clonador-kpi-value">'+(c.ok?'✓ ':'✕ ')+n(c.staging)+'</div><div>PRO '+n(c.pro)+' · STAGING '+n(c.staging)+'</div></div>');});
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
                currentJob=job||{};
                const state=String(currentJob.status||'idle');
                const running=state==='running' && currentJob.run_requested!==false;
                const progress=currentJob.progress||{};
                const pct=Math.max(0,Math.min(100,Number(progress.percent||0)));
                const verification=verificationFrom(currentJob);
                if(currentJob.speed){desiredSpeed=Number(currentJob.speed);}

                if(workerBox){
                    if(state==='idle'||!currentJob.job_id){workerBox.style.display='none';workerBox.textContent='';}
                    else{
                        workerBox.style.display='block';
                        workerBox.textContent='Job '+(currentJob.job_id||'')+' · estado '+state+' · fase '+(currentJob.phase||'')+' · ARRANQUE: usuario · LOTES: Gestor de procesos · velocidad '+desiredSpeed+'/5';
                    }
                }

                if(state==='idle'||!currentJob.job_id){
                    live.className='seo-clonador-live';live.innerHTML='';
                    if(!lastPlan)status.textContent=ready?'Preparado. Primero SIMULA; nada se inicia al cargar esta pantalla.':'Clonador no preparado.';
                    updateControls();
                    return;
                }

                if(running){
                    const verb=(progress.kind==='delete')?'VACIANDO STAGING':(progress.kind==='verify'?'VERIFICANDO CLONACIÓN':'CLONANDO PRO → STAGING');
                    live.className='seo-clonador-live is-running';
                    live.innerHTML='<div class="seo-clonador-live-title">'+verb+'</div>'+ 
                        '<div class="seo-clonador-live-phase">'+esc(progress.phase_label||currentJob.message||currentJob.phase||'Procesando')+'</div>'+ 
                        '<div class="seo-clonador-progress"><span style="width:'+pct+'%"></span></div>'+ 
                        '<div class="seo-clonador-live-meta"><span><strong>'+pct+'%</strong></span><span>Fase '+n(progress.phase_number||0)+' / '+n(progress.phase_total||0)+'</span><span>Copiado: <strong>'+n(progress.copied_total||0)+'</strong> filas/objetos</span><span>Velocidad: <strong>'+desiredSpeed+'/5 · '+esc(currentJob.speed_label||speedNames[desiredSpeed])+'</strong></span><span>Warnings: '+n(progress.warnings_total||(currentJob.warnings||[]).length)+'</span></div>'+ 
                        renderTables('Tabla(s) en proceso',progress.current_tables||[],true)+
                        renderTables('Tablas que quedan pendientes',progress.pending_tables||[],false)+
                        renderTables('Tablas ya completadas',progress.completed_tables||[],false)+
                        '<p style="margin-bottom:0"><strong>Estado:</strong> '+esc(currentJob.message||'Procesando por lotes.')+'</p>';
                    status.textContent='Clonación iniciada por ti. El worker solo gestiona los lotes; puedes cambiar de pantalla.';
                    updateControls();
                    return;
                }

                if(state==='paused'){
                    live.className='seo-clonador-live is-paused';
                    live.innerHTML='<div class="seo-clonador-live-title">⏸ CLONACIÓN PARADA POR TI</div>'+ 
                        '<div class="seo-clonador-live-phase">El worker NO lanzará más lotes hasta que pulses REANUDAR.</div>'+ 
                        '<div class="seo-clonador-progress"><span style="width:'+pct+'%"></span></div>'+ 
                        '<div class="seo-clonador-live-meta"><span><strong>'+pct+'%</strong></span><span>Última fase: '+esc(progress.phase_label||currentJob.phase||'—')+'</span><span>Copiado: '+n(progress.copied_total||0)+'</span><span>Velocidad elegida: '+desiredSpeed+'/5</span></div>'+ 
                        renderTables('Última(s) tabla(s)',progress.current_tables||[],true)+
                        renderTables('Tablas que quedan pendientes',progress.pending_tables||[],false)+
                        '<p style="margin-bottom:0">'+esc(currentJob.message||'Parada manual.')+'</p>';
                    status.textContent='PARADA manual. Puedes reanudar o simular de nuevo para arrancar una clonación nueva desde cero.';
                    if(pollTimer){clearInterval(pollTimer);pollTimer=null;}
                    updateControls();
                    return;
                }

                if(state==='completed'&&verification&&verification.passed===true){
                    lastPlan=null;
                    const secs=(currentJob.result&&currentJob.result.duration_seconds)||0;
                    const copied=(currentJob.result&&currentJob.result.copied_total)||progress.copied_total||0;
                    live.className='seo-clonador-live is-success';
                    live.innerHTML='<div class="seo-clonador-live-title">✓ CLONACIÓN CORRECTA Y VERIFICADA</div>'+ 
                        '<div class="seo-clonador-live-phase">PRO y STAGING coinciden en todos los controles del perímetro clonado.</div>'+ 
                        '<div class="seo-clonador-progress"><span style="width:100%"></span></div>'+ 
                        '<div class="seo-clonador-live-meta"><span><strong>100%</strong></span><span>Copiado: <strong>'+n(copied)+'</strong> filas/objetos</span><span>Duración: '+n(secs)+' s</span><span>Warnings: '+n((currentJob.result&&currentJob.result.warnings_total)||(currentJob.warnings||[]).length)+'</span></div>'+ 
                        renderKpis(verification)+renderAllChecks(verification);
                    status.textContent='Clonación terminada y VERIFICADA'+(secs?(' en '+secs+' s.'):'.');
                    if(pollTimer){clearInterval(pollTimer);pollTimer=null;}
                    updateControls();
                    return;
                }

                if(state==='completed'){
                    lastPlan=null;
                    live.className='seo-clonador-live is-failed';
                    live.innerHTML='<div class="seo-clonador-live-title">✕ FINALIZÓ SIN VERIFICACIÓN VÁLIDA</div><div class="seo-clonador-live-phase">No se marca como copia correcta porque falta un resultado final passed=true.</div>'+renderKpis(verification)+renderAllChecks(verification);
                    status.textContent='El job terminó, pero no existe una verificación final válida. No des por bueno STAGING.';
                    if(pollTimer){clearInterval(pollTimer);pollTimer=null;}
                    updateControls();
                    return;
                }

                if(state==='failed'){
                    lastPlan=null;
                    live.className='seo-clonador-live is-failed';
                    live.innerHTML='<div class="seo-clonador-live-title">✕ CLONACIÓN FALLIDA · STAGING INCOMPLETO</div>'+ 
                        '<div class="seo-clonador-live-phase">No se considera una copia válida.</div>'+ 
                        '<div class="seo-clonador-progress"><span style="width:'+pct+'%"></span></div>'+ 
                        '<div class="seo-clonador-live-meta"><span>Última fase: <strong>'+esc(progress.phase_label||currentJob.phase||'desconocida')+'</strong></span><span>Copiado antes del fallo: <strong>'+n(progress.copied_total||0)+'</strong></span></div>'+ 
                        renderKpis(verification)+renderAllChecks(verification)+
                        '<div class="seo-clonador-error">'+esc(currentJob.last_error||currentJob.message||'Error desconocido')+'</div>';
                    status.textContent=String(currentJob.phase||'')==='verify'?'Falló solo la verificación final. Puedes pulsar REVERIFICAR COPIA; no volverá a copiar los datos.':'Clonación FALLIDA. STAGING está incompleto; SIMULA y arranca de nuevo desde cero.';
                    if(pollTimer){clearInterval(pollTimer);pollTimer=null;}
                    updateControls();
                    return;
                }
                updateControls();
            }
            async function refreshJob(){
                try{const data=await post('seo_clonador_status');renderJob(data.job||{});return data.job||{};}catch(e){status.textContent='No se pudo leer el estado: '+e.message;return null;}
            }
            function startPolling(){
                if(pollTimer)return;
                refreshJob();
                pollTimer=setInterval(refreshJob,4000);
            }

            previewBtn&&previewBtn.addEventListener('click',async()=>{
                setBusy(true);status.textContent='SIMULANDO. Cero escrituras; no se arranca ningún worker.';summary.innerHTML='';plan.style.display='none';lastPlan=null;
                try{const data=await post('seo_clonador_preview');render(data);}catch(e){status.textContent='Simulación fallida: '+e.message;}finally{setBusy(false);}
            });

            startBtn&&startBtn.addEventListener('click',async()=>{
                const paused=String(currentJob.status||'')==='paused';
                if(paused&&!lastPlan){
                    if(!window.confirm('REANUDAR CLONACIÓN\n\nContinuará desde el último lote confirmado. El worker solo gestionará los siguientes lotes.\n\n¿Reanudar?'))return;
                    setBusy(true);status.textContent='Reanudando por orden del usuario...';
                    try{const data=await post('seo_clonador_resume');renderJob(data.job||{});status.textContent=data.message||'Clonación reanudada por ti.';startPolling();}catch(e){status.textContent='No se pudo reanudar: '+e.message;}finally{setBusy(false);}
                    return;
                }
                if(!lastPlan||!lastPlan.can_apply){status.textContent='Primero pulsa SIMULAR CLONACIÓN y revisa el plan.';return;}
                const restartNote=paused?'\n\nHay una clonación parada. Esta acción iniciará una NUEVA clonación desde cero y sustituirá ese job.':'';
                if(!window.confirm('ARRANCAR CLONACIÓN PRO → STAGING\n\nEsta es la acción que INICIA el proceso. Se eliminará el perímetro gestionado actual de STAGING y se reconstruirá desde PRO. PRO no se modifica.'+restartNote+'\n\nVelocidad: '+desiredSpeed+'/5 ('+(speedNames[desiredSpeed]||'Normal')+').\n\n¿ARRANCAR ahora?'))return;
                setBusy(true);status.textContent='Iniciando clonación por orden del usuario...';
                try{
                    const data=await post('seo_clonador_apply',{confirm:'1',speed:String(desiredSpeed)});
                    lastPlan=null;
                    renderJob(data.job||{});
                    status.textContent=data.message||'Clonación iniciada por ti.';
                    startPolling();
                }catch(e){status.textContent='No se pudo iniciar la clonación: '+e.message;}finally{setBusy(false);}
            });

            reverifyBtn&&reverifyBtn.addEventListener('click',async()=>{
                if(String(currentJob.status||'')!=='failed'||String(currentJob.phase||'')!=='verify')return;
                if(!window.confirm('REVERIFICAR COPIA\n\nSolo se repetirán los controles finales. NO se vaciará STAGING y NO se volverán a copiar las filas.\n\n¿Reverificar ahora?'))return;
                setBusy(true);status.textContent='Reverificando la copia existente sin repetir la clonación...';
                try{const data=await post('seo_clonador_reverify');renderJob(data.job||{});status.textContent=data.message||'Reverificación completada.';}catch(e){status.textContent='Reverificación fallida: '+e.message;await refreshJob();}finally{setBusy(false);}
            });

            stopBtn&&stopBtn.addEventListener('click',async()=>{
                if(!window.confirm('PARAR CLONACIÓN\n\nEl lote que ya esté ejecutándose puede terminar, pero el worker NO lanzará otro lote. STAGING puede quedar incompleto hasta que reanudes o reinicies.\n\n¿PARAR?'))return;
                setBusy(true);status.textContent='Solicitando PARADA manual...';
                try{const data=await post('seo_clonador_stop');renderJob(data.job||{});status.textContent=data.message||'Clonación parada por ti.';}catch(e){status.textContent='No se pudo parar: '+e.message;}finally{setBusy(false);}
            });

            async function changeSpeed(delta){
                const next=Math.max(1,Math.min(5,desiredSpeed+delta));
                if(next===desiredSpeed)return;
                desiredSpeed=next;setSpeedUi(desiredSpeed);
                if(currentJob&&currentJob.job_id){
                    try{const data=await post('seo_clonador_speed',{speed:String(desiredSpeed)});renderJob(data.job||currentJob);status.textContent='Velocidad fijada por ti en '+desiredSpeed+'/5 · '+(speedNames[desiredSpeed]||'')+'.';}catch(e){status.textContent='No se pudo cambiar la velocidad: '+e.message;}
                }else{
                    status.textContent='Velocidad elegida para el próximo arranque: '+desiredSpeed+'/5 · '+(speedNames[desiredSpeed]||'')+'.';
                }
            }
            slowerBtn&&slowerBtn.addEventListener('click',()=>changeSpeed(-1));
            fasterBtn&&fasterBtn.addEventListener('click',()=>changeSpeed(1));

            downloadBtn&&downloadBtn.addEventListener('click',()=>{
                if(!lastPlan)return;
                const blob=new Blob([JSON.stringify(lastPlan,null,2)],{type:'application/json'});
                const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='seo-clonador-dry-run-'+new Date().toISOString().replace(/[:.]/g,'-')+'.json';document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(a.href),500);
            });

            setSpeedUi(3);updateControls();
            /* Solo lee estado. Esta llamada NO inicia, reanuda ni encola nada. */
            refreshJob().then(job=>{if(job&&String(job.status||'')==='running'&&job.run_requested!==false)startPolling();});
        })();
        </script>
        <?php
    }
}
