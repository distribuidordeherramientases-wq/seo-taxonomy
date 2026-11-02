#!/usr/bin/env python3
"""Register WordPress script translations for every updated TaxoCommerce JS file."""
import json
import sys
from pathlib import Path

root = Path(sys.argv[1]).resolve()
manifest = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
by_plugin = {}
for relative_path in manifest:
    plugin, rest = relative_path.split("/", 1)
    by_plugin.setdefault(plugin, []).append(rest)

for plugin, paths in by_plugin.items():
    main = root / plugin / (plugin + ".php")
    source = main.read_text(encoding="utf-8")
    mark = "// Native WordPress JS gettext bindings (#2062)."
    if mark in source:
        continue
    checks = " || ".join(
        "false !== strpos( $url, " + repr("/" + p) + " )"
        for p in sorted(set(paths))
    )
    # Hooks execute after scripts are registered and before their content is printed.
    snippet = """
%s
$taxocommerce_js_register_translations = static function () {
    if ( ! function_exists( 'wp_set_script_translations' ) ) {
        return;
    }
    $scripts = wp_scripts();
    foreach ( $scripts->registered as $handle => $script ) {
        $url = (string) $script->src;
        if ( false === strpos( $url, '/%s/' ) ) {
            continue;
        }
        if ( %s ) {
            wp_set_script_translations( $handle, '%s', __DIR__ . '/languages' );
        }
    }
};
add_action( 'admin_enqueue_scripts', $taxocommerce_js_register_translations, 9999 );
add_action( 'wp_enqueue_scripts', $taxocommerce_js_register_translations, 9999 );
add_action( 'enqueue_block_editor_assets', $taxocommerce_js_register_translations, 9999 );
add_action( 'wp_print_scripts', $taxocommerce_js_register_translations, 1 );
add_action( 'wp_print_footer_scripts', $taxocommerce_js_register_translations, 1 );
""" % (mark, plugin, checks, plugin)
    main.write_text(source.rstrip() + "\n" + snippet, encoding="utf-8")
    print(plugin, len(paths), "JS files registered for native wp.i18n")
