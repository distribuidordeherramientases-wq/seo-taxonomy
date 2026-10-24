=== TaxoCommerce Vocabulary ===
Contributors: taxocommerce
Requires at least: 6.7
Requires PHP: 7.4
Stable tag: 0.3.2
License: GPLv2 or later

Canonical semantic vocabulary and technical attributes for TaxoCommerce.

== 0.3.2 ==
* Adds a standalone portable JSON catalog backup for Vocabulary, type-role mappings, product/category assignments, canonical product attributes and WooCommerce product tags.
* Adds mandatory import simulation and MERGE/MIRROR assignment modes.
* Resolves destination objects with portable identities; numeric source IDs are audit-only.
* Adds an integrity summary and content fingerprint to exported packages.
* MIRROR never deletes missing canonical masters; it only aligns assignments for objects included in the package.

== 0.3.0 ==
* Uses the existing seo_vocabulary, seo_object_vocabulary, seo_type_role_map and sql_atributos* tables as canonical storage.
* Adds a dedicated Classifier tab that proposes semantic labels and technical attributes from product content without changing data automatically.
* Adds independent CSV import/export for semantic labels and technical attribute masters.
* Keeps product assignments out of Vocabulary master exports.
