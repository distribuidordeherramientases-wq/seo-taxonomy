(function () {
    'use strict';

    var cfg = window.seoFaqTelemetryConfig || {};
    if (!cfg || !cfg.faqs || !cfg.faqs.length) return;

    var sentRender = false;
    var sentView = false;
    var sentError = false;
    var firstOpen = true;
    var opened = new Set();
    var renderedIds = new Set();
    var observer = null;
    var mutationObserver = null;
    var stopTimer = null;

    function normalize(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/[\u2018\u2019\u201c\u201d]/g, '"')
            .replace(/\s+/g, ' ')
            .trim();
    }

    cfg.faqs = cfg.faqs.map(function (faq) {
        faq.normalized = normalize(faq.question);
        return faq;
    });

    function send(event, extra) {
        var data = new URLSearchParams();
        data.set('action', 'seo_faq_track');
        data.set('event', event);
        data.set('object_type', cfg.objectType);
        data.set('object_id', cfg.objectId);
        data.set('token', cfg.token);

        Object.keys(extra || {}).forEach(function (key) {
            data.set(key, extra[key]);
        });

        if (navigator.sendBeacon) {
            navigator.sendBeacon(cfg.ajaxUrl, data);
            return;
        }

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: data.toString()
        }).catch(function () {});
    }

    function explicitNode(faq) {
        return document.querySelector('[data-seo-faq-id="' + faq.id + '"]');
    }

    function questionNode(faq) {
        var explicit = explicitNode(faq);
        if (explicit) return explicit;

        var selectors = [
            'details summary',
            'button',
            '[role="button"]',
            '.faq-question',
            '.accordion-title',
            '.accordion-header',
            '.elementor-tab-title',
            '.vc_tta-panel-title',
            'dt',
            'h2', 'h3', 'h4', 'h5'
        ];
        var nodes = document.querySelectorAll(selectors.join(','));

        for (var i = 0; i < nodes.length; i++) {
            var text = normalize(nodes[i].innerText || nodes[i].textContent);
            if (!text) continue;
            if (text === faq.normalized || text.indexOf(faq.normalized) === 0) {
                return nodes[i];
            }
        }

        var fallback = document.querySelectorAll('div,span,p,a,strong');
        for (var j = 0; j < fallback.length; j++) {
            if (fallback[j].children && fallback[j].children.length > 4) continue;
            var fallbackText = normalize(fallback[j].innerText || fallback[j].textContent);
            if (fallbackText === faq.normalized) return fallback[j];
        }
        return null;
    }

    function markView(node) {
        if (sentView || !node) return;

        if (!('IntersectionObserver' in window)) {
            sentView = true;
            send('view');
            return;
        }

        if (!observer) {
            observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!sentView && entry.isIntersecting) {
                        sentView = true;
                        send('view');
                        observer.disconnect();
                    }
                });
            }, {threshold: 0.1});
        }

        observer.observe(node);
    }

    function recordOpen(faq) {
        if (!faq || opened.has(faq.id)) return;
        if (!sentView) {
            sentView = true;
            send('view');
            if (observer) observer.disconnect();
        }
        opened.add(faq.id);
        send('open', {
            faq_id: faq.id,
            first_open: firstOpen ? 1 : 0
        });
        firstOpen = false;
    }

    function attachFaqNode(node, faq) {
        if (!node || node.dataset.seoFaqTracked === '1') return;
        node.dataset.seoFaqTracked = '1';
        node.dataset.seoFaqId = String(faq.id);
        node.addEventListener('click', function () { recordOpen(faq); }, true);
    }

    document.addEventListener('click', function (event) {
        var node = event.target;
        for (var depth = 0; node && depth < 7; depth++, node = node.parentElement) {
            var explicitId = node.dataset && node.dataset.seoFaqId ? parseInt(node.dataset.seoFaqId, 10) : 0;
            var text = normalize(node.innerText || node.textContent);
            var faq = cfg.faqs.find(function (item) {
                if (explicitId && item.id === explicitId) return true;
                return text && (text === item.normalized || (text.indexOf(item.normalized) === 0 && text.length <= item.normalized.length + 80));
            });
            if (faq) {
                recordOpen(faq);
                break;
            }
        }
    }, true);

    function scan() {
        var found = [];
        var bodyText = normalize(document.body ? document.body.innerText : '');
        var newIds = [];

        cfg.faqs.forEach(function (faq) {
            var appears = bodyText.indexOf(faq.normalized) !== -1;
            if (appears && !renderedIds.has(faq.id)) {
                renderedIds.add(faq.id);
                newIds.push(faq.id);
            }

            var node = questionNode(faq);
            if (node) {
                found.push({faq: faq, node: node});
                attachFaqNode(node, faq);
            }
        });

        if (newIds.length) {
            send('render', {
                faq_ids: newIds.join(','),
                count_block: sentRender ? 0 : 1
            });
            sentRender = true;
        }

        if (found.length) markView(found[0].node);
        return renderedIds.size === cfg.faqs.length;
    }

    function start() {
        scan();

        mutationObserver = new MutationObserver(function () { scan(); });
        mutationObserver.observe(document.documentElement, {childList: true, subtree: true});

        stopTimer = setTimeout(function () {
            scan();
            if (mutationObserver) mutationObserver.disconnect();
            mutationObserver = null;
            if (renderedIds.size < cfg.faqs.length && !sentError) {
                sentError = true;
                send('render_error', {
                    missing_count: cfg.faqs.length - renderedIds.size,
                    error_type: renderedIds.size > 0 ? 'partial' : 'missing'
                });
            }
        }, 5000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, {once: true});
    } else {
        start();
    }
}());
