(function () {
    'use strict';

    window.seoPostEditorPrependPayload = function (payloadId) {
        var source = document.getElementById(payloadId);
        if (!source) {
            return false;
        }

        var addition = source.value || '';
        if (!addition) {
            return false;
        }

        var editorId = 'seo_post_editor_content';
        var textarea = document.getElementById(editorId);
        var current = '';

        if (window.tinymce && window.tinymce.get(editorId) && !window.tinymce.get(editorId).isHidden()) {
            var editor = window.tinymce.get(editorId);
            current = editor.getContent() || '';
            editor.setContent(addition + (current ? '\n\n' + current : ''));
            editor.save();
        } else if (textarea) {
            current = textarea.value || '';
            textarea.value = addition + (current ? '\n\n' + current : '');
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            textarea.dispatchEvent(new Event('change', { bubbles: true }));
        }

        var form = textarea ? textarea.closest('form') : null;
        if (form) {
            form.dataset.seoEditorialPendingInsert = '1';
        }

        return false;
    };

    document.addEventListener('DOMContentLoaded', function () {
        var search = document.getElementById('seo-post-product-cat-search');
        var list = document.getElementById('seo-post-product-cat-list');
        var allButton = document.getElementById('seo-post-product-cat-all');
        var noneButton = document.getElementById('seo-post-product-cat-none');

        if (!search || !list) {
            return;
        }

        function normalize(value) {
            value = String(value || '').toLowerCase();
            if (value.normalize) {
                value = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            }
            return value;
        }

        function visibleItems() {
            return Array.prototype.filter.call(
                list.querySelectorAll('.seo-post-product-cat-item'),
                function (item) {
                    return item.style.display !== 'none';
                }
            );
        }

        search.addEventListener('input', function () {
            var needle = normalize(search.value);
            Array.prototype.forEach.call(
                list.querySelectorAll('.seo-post-product-cat-item'),
                function (item) {
                    var haystack = normalize(item.getAttribute('data-search'));
                    item.style.display = !needle || haystack.indexOf(needle) !== -1 ? 'block' : 'none';
                }
            );
        });

        if (allButton) {
            allButton.addEventListener('click', function () {
                visibleItems().forEach(function (item) {
                    var checkbox = item.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
            });
        }

        if (noneButton) {
            noneButton.addEventListener('click', function () {
                visibleItems().forEach(function (item) {
                    var checkbox = item.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.checked = false;
                    }
                });
            });
        }
    });
})();
