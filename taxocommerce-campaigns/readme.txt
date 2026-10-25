=== TaxoCommerce Campaigns ===
Contributors: taxocommerce
Requires at least: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later

Offer campaigns and commercial publication calendar for the TaxoCommerce family.

== 0.1.0 ==
* Migrates the existing Marketing campaign engine without duplicating campaign data.
* Reuses seo_marketing_campaigns and seo_marketing_campaign_products.
* Keeps campaign creation/editing, products, prices, overlap protection, import/export, apply/restore and safe deletion.
* Adds an independent commercial calendar.
* Moves campaign social offer planning and campaign creatives out of the Social editorial calendar.
* Reuses TaxoCommerce Social connections when that plugin is active.
* Keeps the existing campaign creative cache under uploads/seo-social-campaigns during migration.
* Adds public shortcodes [taxocommerce_campaign] and [oferta].
* Keeps compatibility with TaxoCommerce Templates template-campaign.php after the legacy monolith is disabled.
* Does not create posts for banners or offers.

== Migration ==
Campaign data is not copied. Both old and new modules read the same canonical campaign tables during the short verification period. Avoid editing the same campaign from both interfaces simultaneously.
