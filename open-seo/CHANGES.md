# Derivative changes — 2026-11-16

## 1.3.0 — readable admin forms and shared GEO website information

- Fixed the metadata review form shrinking inside an inline-block action wrapper. It now uses a full-width form, separate current/suggested field cards, larger text areas, consistent spacing and responsive layouts.
- Added scoped native admin styles, accessible check labels, local status icons and clear navigation between health reports and website information. Inherited settings and global WordPress navigation retain their own styling.
- Added GEO Website Info: a central plain-text overview, official name, heading, key facts, public contact details and optional website link, with a live preview and searchable paginated published-page picker.
- Shared information is opt-in and appears visibly only on selected public pages. It is inserted once at the end of main content, with a body-end fallback for builders, without serializing or rewriting existing HTML, page content, Oxygen data or SEO fields. Unselecting pages or disabling output removes it reversibly.
- Central edits propagate to selected output and invalidate selected-page rendered-source caches. Hosts can purge caches through the open_seo/shared_content_saved hook; full-page/CDN caches may still require a manual purge.
- Saves require administrator access, a nonce, editable public-page selection and a matching revision. Text is sanitized and escaped, protected pages are excluded, and scripts/comments/templates cannot misdirect the insertion point.
- Added integration and real WordPress browser coverage for selection, preview, propagation, removal, preservation and desktop/mobile form sizing.

## 1.2.0 — published-page health scans and understandable AI readiness

- Added SEO Health & Fixes with resumable scans of public, published WordPress content, site visibility, HTTPS address, robots policies, XML sitemap and repeated rendered metadata. Requests stay on the site's origin; failures remain unverified.
- Read published HTML for builder pages, excluding navigation, scripts and global site chrome. Report real headings, metadata, image descriptions, chosen phrase placement, repetition and repeated passages. Never write a fabricated editor score.
- Safe fixes fill blank SEO fields, respect exclusions and preserve authored fields, page bodies and builder data. Optional render-time alt inheritance reuses existing media-library descriptions only for missing attributes; empty decorative alt text is retained.
- Added explicit metadata review with concurrency checks and undo. Content-based builder suggestions use a fresh fingerprinted rendered snapshot. Body wording, noindex and Oxygen locks require review.
- Added editor check/fix controls with authenticated REST requests and unsaved-change protection. Background work continues via cron or live admin progress.
- Explained GEO as AI search readiness, with plain-language page guidance and advanced crawler controls collapsed. Factual accuracy and citations are not certified by automated schema checks.
- Added mocked-HTTP integration coverage and real local WordPress browser coverage for preservation, reviewed edits, undo, authenticated fixes and understandable screens.

## 1.1.2 — settings screens restored

- Reproduced the blank General Settings screen. Renaming the parent menu changed WordPress-derived child-screen IDs, so the shared settings renderer did not receive dashboardHeader data and crashed on dashboardUrl/proBadge.
- Stabilize the internal admin hook namespace immediately after the parent menu registers and before child pages register. The visible Open SEO name is unchanged; inherited screen-specific assets, localized data and handlers once again use the identifiers they expect.
- Removed the compiled shared-settings purchase banner, which became visible once the settings renderer loaded correctly. No license or entitlement flag is changed.
- Added browser coverage across inherited settings screens, their tabs, and save/reload/restore cycles. Added a targeted hook-identity regression test. Automation defaults and preservation behavior are unchanged.

## 1.1.1 — automatic by default, existing-content migration and preservation

- Automation defaults to enabled. Activation or the first request after upgrading runs an idempotent migration, enables the earlier opt-in setting and queues an existing-content scan without a settings action.
- Existing posts/pages process in WP-Cron batches of 50; new saves and Gutenberg REST saves fill blank keyword/title/description fields. Cursor/completion markers prevent repeated restarts and allow interrupted scans to resume.
- Per-page exclusions remain respected. A later explicit site-wide opt-out is retained; re-enabling queues a scan again.
- Conditional metadata updates prevent a simultaneous manual edit from being replaced. Generated rows are removed when an authored insert races a missing-field insert. Nonempty fields, page bodies and page modification timestamps are preserved.
- Updated the native automation screen, setup guide, version headers and asset version. Updated automation.php, suite.php, suite-admin.php and package checks. Added targeted multi-batch, upgrade, exclusion, author-preservation and concurrency tests.

## 1.1.0 — local feature suite, automation and independent UI branding

- Added subscription-free local implementations for news/video sitemaps, paginated media sitemap indexes, podcast RSS/enclosures and associated structured data. Index entries are appended to the inherited index and relevant metadata/settings changes invalidate its cache.
- Added schema JSON templates, dedicated-page LocalBusiness locations, visible FAQ shortcodes and matching markup, and visible answer-summary shortcodes. Custom primary templates replace the inherited primary node.
- Added a site-owned link table, batched indexing, incoming-link inventory, title/excerpt overlap suggestions, bounded public HTTP HEAD checks, approved render-time keyword linking, and previewed/revision-backed exact href replacement.
- Added metadata CSV exports, preview/apply, conflict checks and 24-hour metadata undo.
- Added optional finalized-day Search Console snapshots, exact-query tracking and daily WP-Cron scheduling. No commercial SERP or volume dataset is claimed.
- Added an optional loopback-only Ollama client for reviewable drafts. No cloud provider or subscription is used. Live-model validation remains outstanding.
- Added opt-in metadata automation: content-derived focus phrases, title and description filling for blank fields, post exclusions, Gutenberg REST-save handling and background/manual existing-content batches. Added a local keyword-order handler and public max-tags filter. No PRO entitlement flags are set.
- Replaced the inherited SEO/Competitor Analyzer sales screen and service dependency with a local public HTML analyzer. Safe HTTP fetching rejects private/credential/nonstandard-port URLs and does not follow redirects.
- Improved GEO findings with evidence/action details for sources, heading hierarchy, author biography, freshness, summaries, canonical/visibility issues and FAQ visibility. Source links are distinguished from internal links. Expanded advisory crawler controls to Anthropic and Perplexity and strengthened llms.txt eligibility filtering.
- Rebranded human-facing PHP/JS string tokens and block metadata to Open SEO while preserving comments, copyright notices, namespaces, hooks, database names and existing-content block identifiers. See UI-BRANDING-CHANGES.txt for the complete original-file modification list.
- Plugin header name/author/description now use the independent identity; original attribution is retained in NOTICE.txt and source. The original readme is preserved as UPSTREAM-README.txt.
- Removed the shown keyword upgrade/account notice. Keyword ordering now synchronizes the real Tagify order with the editor store instead of opening a purchase modal.
- Disabled upstream updater/rollback module and its Status tab to avoid replacing this derivative. Resolved pre-existing merge-conflict markers found in the supplied archive's status/assets/js/versionControl.js; the original archive is untouched.
- Added versioned asset URLs to refresh cached interfaces after upgrades. Missing PHP DOM disables dependent automation/indexing gracefully and displays a configuration notice.
- Added media.php, schema.php, links.php, bulk.php, history.php, assistant.php, automation.php, suite.php and suite-admin.php. Updated geo.php, open-seo.php, the analyzer admin class, editor bundle and Status bundle. Templates and approved rules are user-configured; no business facts or ranking/citation guarantees are fabricated.

## 1.0.0 baseline

Base: user-provided seo-by-rank-math.zip, Rank Math SEO 1.0.277.2, GPLv3-or-later. Original source is retained separately in the workspace under source/seo-by-rank-math for comparison. No source documents or archived agent instructions were treated as user instructions.

- rank-math.php: independent derivative name, version, attribution, update identity, local additions bootstrap, upstream supported registration-skip mode for local functionality, omission of plugin-row purchase link. Engine version and migration behavior retained.
- includes/module/class-manager.php: removed unconditional ordering of two subscription cards to safely accept filtered modules; omitted dashboard purchase card.
- includes/admin/class-admin-init.php: omitted timed PRO and review solicitations.
- includes/admin/class-admin-menu.php: omitted purchase menu link.
- includes/admin/class-admin-header.php: independent header name and generic chart icon.
- includes/admin/class-cmb2-options.php: omitted settings purchase notice.
- open-seo.php: local module filtering, independent menu, WordPress-native forms, capability/nonce checks, direct Google connection controls, reports and editorial review screen.
- open-seo/geo.php: local editorial review and opt-in robots blocking.
- open-seo/gsc.php: OAuth state/PKCE, encrypted token persistence and refresh, read-only Search Console requests, account property selection, URL inspection and token revocation.
- COPYING: GPLv3 text. Original copyright notices and bundled third-party licenses remain unchanged.
- INSTALL.md and CHANGES.md: derivative setup, limits and attribution.

Distribution packaging excludes upstream development-only agent instructions, local agent state/configuration, repository hooks and workflow metadata. Runtime PHP, JS, CSS, assets, vendor source, autoloaders and third-party license notices are retained. The original archive is unmodified.

- assets/admin/js/modules.js: disabled the compiled dashboard purchase card without granting service entitlement.
- assets/admin/js/components.js: independent dashboard header and generic icon; other inherited labels remain attributed to Rank Math.
