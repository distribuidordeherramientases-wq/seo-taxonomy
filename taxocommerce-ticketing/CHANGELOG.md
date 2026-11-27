# Changelog

## 0.1.5 — 2026-11-27 (#2974)
- Localize 55 additional fixed administrative labels and diagnostic messages in five locales (275 entries). Display translated functionality-check details, check and status labels, and activity-event descriptions alongside unchanged event identifiers. Preserve stored diagnostics, JSON exports, customer data and all check behavior. Rebuild and verify five MO catalogs, now 311 entries per locale. No production deployment.

## 0.1.4 — 2026-11-26 (#2974)
- Complete all 256 existing gettext keys in five locales (460 additional entries): technical manual, code-check controls and diagnostic guidance. Rebuild and validate five MO catalogs against the POT and preserve prior translations, identifiers, configured content and behavior. Coverage refers to extracted gettext keys only; review hardcoded interface strings separately. No production deployment.

## 0.1.3 — 2026-11-26 (#2974)
- Add a native gettext template with 256 static interface keys and five bundled PO/MO catalogs (es_ES, en_US, fr_FR, de_DE, nl_NL). First batch: 164 translated keys per locale (820 entries) covering ticket inbox, statuses, priorities, form defaults, fixed notifications, form settings, functional-check labels and activity-log controls. Include the user manual; leave 92 technical manual/code-check strings for later review. Prefer official language packs with bundled regional fallback on init; keep configured labels, ticket content, stored categories, access controls, notifications and database behavior unchanged. No production deployment.

## 0.1.2 — 2026-11-20
- Fix false positive `created` message for honeypot rejection; report failure without generating a ticket.
- Preserve inserted message ID before a separate SQL update and surface submission outcomes through privacy-minimal diagnostic events.
- Add staff Activity log limited to 150 recent events (UTC timestamp, fixed event code and ticket ID; no IP, email or messages), JSON export.
- Split Autocheck into Functionality Check (synthetic ticket/message/attachment create-read-delete with cleanup and real counts) and independent 5-speed Code Check (PHP, WordPress, security), with JSON exports.
- Add WordPress-admin User Manual and Technical Manual; retain plugin independence.
- GitHub Issue #2874. Tests and WordPress.org Plugin Check required before merge and test-site deployment.


## 0.1.1 — 2026-11-20
- Prepared dual-site first installation, Core extension management and public plugin information page on Focazul (Issue #2871).


## Unreleased — WordPress.org preflight corrections
- Replace computed gettext domains with literal plugin text domains.
- Clarify ordered placeholders and translator comments for WordPress Plugin Check.
- Strengthen request sanitization, safely bound nonce-check annotations and add the language directory.
- Follow-up PR #2867, Issue #2862.

## 0.1.0 — 2026-11-20
- Initial independent Ticketing plugin and dedicated private database schema.
- Customer form shortcode, editable labels, request categories, branding and email routing.
- Ticket statuses, priorities, assignees, private and public messages, protected attachments.
- Private customer reply link and staff notifications.
- Optional integration hook for future Tasks adapter, no Core dependency.
- References #2862.
- #2870: first test-site delivery of the complete, independent plugin to STAGING and Focazul, with no automatic activation and no production rollout.
