/* TaxoCommerce SEO: Academia, Leccion 9 (WordPress-enqueued asset). */
(function(){
            const ajaxUrl = window.taxocommerceL9Settings.ajaxUrl;
            const nonce = window.taxocommerceL9Settings.nonce;
            let running = false;

            const $ = id => document.getElementById(id);
            function escapeHtml(v){
                return String(v == null ? '' : v).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
            }
            function update(s){
                $('l9-products').textContent = s.processed_products + ' / ' + s.total_products;
                $('l9-exercises').textContent = s.exercises;
                $('l9-signals').textContent = s.signals;
                $('l9-state').textContent = s.complete ? 'Completada' : (s.processed_products ? 'En progreso' : 'Sin iniciar');
                $('l9-percent').textContent = s.percent + '%';
                $('l9-bar').style.width = s.percent + '%';
            }
            async function call(action){
                const body = new URLSearchParams();
                body.set('action', action);
                body.set('nonce', nonce);
                const r = await fetch(ajaxUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString()});
                const data = await r.json();
                if (!data || !data.success) throw new Error(data && data.data && data.data.message ? data.data.message : 'Error de Academia');
                return data.data;
            }
            async function refresh(){
                const data = await call('seo_dependiente_v3_l9_status');
                update(data.status);
                const tbody = $('l9-preview');
                tbody.innerHTML = (data.preview || []).map(item => '<tr><td>#'+escapeHtml(item.product_id)+' · '+escapeHtml(item.post_title || 'Producto')+'</td><td>'+escapeHtml(item.exercise_type)+'</td><td>'+escapeHtml(item.question)+'</td><td>'+escapeHtml(item.expected_mode)+'</td></tr>').join('');
            }
            async function loop(){
                running = true;
                $('l9-start').disabled = true;
                $('l9-stop').disabled = false;
                $('l9-message').textContent = 'Academia está generando preguntas y señales desde el catálogo…';
                try {
                    while (running) {
                        const data = await call('seo_dependiente_v3_l9_batch');
                        update(data.status);
                        if (data.status.complete) {
                            running = false;
                            $('l9-message').textContent = 'Lección 9 completada. La memoria L9 ya está disponible para Dependiente V3.';
                            await refresh();
                            break;
                        }
                    }
                } catch (e) {
                    running = false;
                    $('l9-message').textContent = e.message;
                }
                $('l9-start').disabled = false;
                $('l9-stop').disabled = true;
            }
            $('l9-start').addEventListener('click', loop);
            $('l9-stop').addEventListener('click', function(){ running = false; $('l9-message').textContent = 'Proceso detenido. Puedes continuarlo cuando quieras.'; });
            $('l9-reset').addEventListener('click', async function(){
                if (!confirm('¿Borrar la formación de la Lección 9 y empezar de nuevo?')) return;
                running = false;
                try { const data = await call('seo_dependiente_v3_l9_reset'); update(data.status); await refresh(); $('l9-message').textContent = 'Lección 9 reiniciada.'; }
                catch(e){ $('l9-message').textContent = e.message; }
            });
        })();
