(function(){
                var wrap=document.getElementById('seo-transport-rules');
                var add=document.getElementById('seo-transport-add-rule');
                var tpl=document.getElementById('seo-transport-rule-template');
                if(!wrap||!add||!tpl){return;}
                function bindRemove(root){
                    (root||document).querySelectorAll('.seo-transport-remove').forEach(function(btn){
                        if(btn.dataset.bound){return;} btn.dataset.bound='1';
                        btn.addEventListener('click',function(){var rule=btn.closest('.seo-transport-rule');if(rule){rule.remove();}});
                    });
                }
                bindRemove(document);
                add.addEventListener('click',function(){
                    var index=Date.now();
                    var html=tpl.innerHTML.replace(/__INDEX__/g,String(index));
                    var box=document.createElement('div'); box.innerHTML=html;
                    while(box.firstElementChild){wrap.appendChild(box.firstElementChild);}
                    bindRemove(wrap);
                });
            }());
