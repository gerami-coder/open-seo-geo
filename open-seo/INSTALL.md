# Open SEO + GEO 1.3.0

Independent GPLv3-or-later plugin. Full editable source is included. Provenance, original copyrights, third-party licenses and modification records are retained in the source, CHANGES.md, UI-BRANDING-CHANGES.txt and COPYING. The visible product name is Open SEO.

## Install on staging first

1. Back up your database and files. Requires WordPress 6.7+, PHP 7.4+, and HTTPS for Google authorization. OpenSSL is required for the direct Google connection.
2. Deactivate Rank Math, Rank Math PRO, Yoast, and other SEO output plugins before activating this plugin. Do not delete existing SEO data. This derivative uses the inherited Rank Math classes, option names, tables and metadata; the two plugins cannot run together.
3. Upload the ZIP through Plugins → Add New → Upload Plugin. Activate **Open SEO + GEO**. For an existing Open SEO installation, choose **Replace current with uploaded**. Existing SEO metadata/settings are retained. Use one site at a time; network activation and multisite have not been validated. PHP DOM is required for content automation and detailed HTML analysis.
4. Open **Open SEO** for titles/meta, sitemaps, schema, redirects, image SEO, local SEO and editor analysis. Configure and enable the needed modules. Open **Local SEO tools** for the independent additions below. Plugin assets carry a derivative version parameter to refresh old cached interfaces; purge host/CDN caches after upgrading if they serve old assets.
5. Open **Open SEO → SEO Health & Fixes → Scan website**. When it completes, use **Fix safe issues** and scan again to verify the output. Open **AI Search & Console** for crawler preferences and the direct Google connection. Both screens use standard WordPress admin controls.
6. If enabling llms.txt, configure it in General Settings and save WordPress Permalinks once to refresh routes. Review its public output. llms.txt is optional and is not an indexing protocol.

## Shared website information for GEO

Open **Open SEO → GEO Website Info**. Enter your official website/organization name, a concise overview, verified facts, optional public contact details and a website link. Preview the visible section, search for published pages, and select the pages where this information is relevant. Turn on **Display the shared section on selected pages**, then save.

The section appears at the end of the main content, or before the closing body in themes/builders without a main element. It is visible text, not a hidden SEO payload. Existing page content, Oxygen data, SEO titles/descriptions and chosen keywords are preserved. The shared section uses H2, not an additional H1.

Edit the information centrally to update all selected pages. Deselect a page or disable display to remove the section without changing saved page bodies. New pages are not selected automatically. Only public, published, non-password-protected pages can be selected (up to 2,000); the picker searches and paginates results. A selected page becoming private, unpublished or protected stops rendering the section.

Purge hosting/CDN page caches after changes if they still serve older HTML. Third-party cache plugins can use the **open_seo/shared_content_saved** action. Scan selected pages again to refresh health reports. Use a short, relevant overview and keep each page's main content distinct; repeated general information does not guarantee search rankings or AI citations.

## SEO Health & Fixes

Scan website checks public published WordPress pages/posts and public content types in batches. It also checks the indexing setting, HTTPS address, root robots rules, sitemap response and repeated scanned titles/descriptions. Keep the page open for live progress; WP-Cron continues background work when visitors trigger it. Hosting restrictions or cached responses may require manual verification.

The published HTML scan works with Oxygen and other builders when their content is present in server HTML. It excludes navigation and scripts, but is not a full browser, JavaScript, mobile, performance or Google-index audit. Failed fetches remain unverified. Password-protected pages are excluded.

Safe fixes fill blank keywords, SEO titles and descriptions using available content and reuse media-library descriptions for missing image alt attributes. Authored SEO fields, decorative empty alt text, page bodies, builder data, noindex and editing locks are preserved. On builder pages, scan first to provide fresh rendered content for suggestions. Re-scan after fixes and clear hosting/CDN caches if needed. Explicitly reviewed metadata edits can replace existing SEO fields and provide undo with conflict checks.

GEO means Generative Engine Optimization, presented as AI search readiness: readable pages, useful opening answers, responsible authors/businesses and accurate consistent facts. Automated checks cannot establish factual truth or guarantee AI citations. Review wording and facts yourself. Advanced crawler controls are optional; llms.txt does not guarantee indexing.

The editor's 100-point score is a checklist, not a Google ranking. Keyword placement and repetition may require natural edits in Oxygen. This release never writes a fake perfect score, rewrites builder content or unlocks another editor.

## Direct Search Console setup

1. In your Google Cloud project, enable **Google Search Console API**. Configure the OAuth consent screen. If the app is in Testing, add your Google account as a test user; offline access can expire under Google's testing policies.
2. Create an OAuth client of type **Web application**. Add the exact redirect URI displayed on the AI Search & Console screen to Authorized redirect URIs.
3. Add these constants to wp-config.php before the “stop editing” line, using your own values:

```php
define('OPEN_SEO_GOOGLE_CLIENT_ID', 'YOUR_CLIENT_ID.apps.googleusercontent.com');
define('OPEN_SEO_GOOGLE_CLIENT_SECRET', 'YOUR_CLIENT_SECRET');
```

4. Return to the screen, connect Google, grant read-only Search Console access and select a property available to your account. Click Fetch recent queries. You must already have access to that Search Console property; this plugin does not verify ownership for you.
5. The report covers days 30 through 3 before today (UTC dates), up to 100 top query rows. It is fetched manually, not scheduled. Privacy filters and reporting delays affect the results. This is not a rank tracker or a site-wide total. URL Inspection retrieves the indexed status; it does not submit an indexing request.

Tokens are encrypted with AES-256-GCM using a key derived from WordPress salts and stored without autoloading. Changing salts invalidates saved tokens: disconnect and reconnect. Client secrets are kept in wp-config.php, not forms. Google receives OAuth requests, property identifiers and inspected URLs only when you use these controls. Existing upstream modules may contact services described in the original readme; review those before enabling them.

Disconnect attempts to revoke Google's grant and removes locally stored tokens, the property and query report. If revocation fails, remove this app from your Google account's third-party connections. Do not share database backups or wp-config.php.

## What is included and what is not

Included: the supplied SEO engine and layout, local GEO reviews for up to 200 recently modified public posts/pages, crawler controls, direct Google OAuth, property selection, query reports and URL Inspection. Local additions have no subscription or credit system.

Removed from the module chooser: upstream subscription services, upstream Analytics (replaced by the direct connection), unavailable PRO placeholder cards, and upstream version/rollback controls that could replace this derivative. Local equivalents are in **Local SEO tools**. The SEO Analyzer route now runs the local page/competitor analyzer. The keyword notice and drag-reordering upgrade modal have been replaced with local behavior. Source comments, hooks and identifiers retain the original names for compatibility and licensing; they are not the visible product identity.

### Automation

Automation is enabled by default: no settings action is required. First installation or upgrading to 1.1.1 automatically enables it and queues all existing posts/pages in background batches of 50. Future saves (including Gutenberg REST saves) fill blank SEO fields. Background processing uses WP-Cron; it needs site traffic or a configured server cron, and is not instantaneous. You can monitor progress under **Local SEO tools → Automation**.

The initial 1.1.1 migration enables automation even if the earlier opt-in release was disabled. After that one-time migration, an explicit site-wide opt-out remains respected. Re-enabling it queues an existing-content scan again. The per-page exclusion remains respected during upgrades and automatic scans. Completed scans are not restarted on every request; interrupted scans resume after reactivation. Manual restart/batch controls remain available.

Focus phrases are extracted locally using weighted title, heading and body phrases (up to five suggestions), without keyword search-volume data. SEO titles use the saved page title. Descriptions use the excerpt or opening paragraphs. No invented facts are added. Existing authored SEO values are preserved. The per-page **Media & schema** box has an exclusion checkbox. Reload the editor after saving to see server-generated values. Keyword drag ordering now updates the primary phrase locally. The editor accepts up to 100 manually supplied phrases as a safety limit; the extractor still suggests five.

Automation does not rewrite article bodies or their modification timestamps, fabricate image descriptions, force a score of 100, or promise the best commercial keyword. Review phrase relevance, language and description quality. Existing nonempty values are not automatically regenerated after content changes; clear a field and save to regenerate it, or use the CSV tools. Fields without enough source text may remain blank rather than receive invented content. Conditional writes protect edits made while a scan is running; concurrent authored inserts are kept instead of a generated duplicate.

### News, video and podcast

Enable the desired feed in **Local SEO tools → News, video & podcast**. Set a factual publication/series name and language. On each post/page, use **Media & schema** to mark a news article, supply video metadata, or select an audio attachment ID.

- News sitemap: marked, indexable posts/pages from the last 48 hours, up to 1,000 candidates per sitemap page.
- Video sitemap and VideoObject: require name, description, thumbnail, HTTP(S) content/player URL and ISO 8601 date with timezone. Only describe a video actually visible on the page.
- Podcast RSS and PodcastEpisode: require an audio attachment that exists on the WordPress server. RSS uses real file size and MIME type, and exposes the latest 100 candidates. Series title, description, author and artwork are configurable. Remote-only/offloaded attachments need a local file and are not supported by this implementation. Directory-specific podcast requirements and playback need separate validation.
- Index: `/?open_seo_feed=index` automatically lists enabled news/video sitemap pages. Those pages are also appended to the inherited sitemap index. Visibility and metadata changes invalidate the inherited cache. Robots advertises the media sitemap index. Feed endpoints use query parameters and need no permalink rewrite.

Public outputs exclude protected, unpublished and noindex content and respect the site's search-visibility setting. Candidate counts can yield an empty sitemap page if all candidates are excluded. The parent index handles pagination up to 10,000 child pages. Google acceptance is not guaranteed.

### Schema and multiple locations

Save named JSON templates under **Schema & locations**, then select a template on a post/page. Supported substitution tokens are listed on the screen. A selected primary template replaces the inherited primary richSnippet node; FAQ, video, podcast and location nodes may coexist. Each template needs a schema.org @type, factual values and the properties required for its intended use. Validation here checks structure, not eligibility for a search feature.

Each location needs a unique key, real name, PostalAddress and dedicated public page ID. Optional contact, opening hours, coordinates and entity URLs are accepted from your supplied facts. The matching location page receives its LocalBusiness node. Keep visible business information consistent with these facts. No address or credential is invented or verified automatically.

Visible FAQ: `[open_seo_faq question="Question"]Answer[/open_seo_faq]`. Its FAQPage markup is generated from those same entries. Visible answer summary: `[open_seo_summary]Your factual answer[/open_seo_summary]`. FAQ markup does not guarantee FAQ rich results.

### Internal links, checks and bulk changes

Scan saved post/page HTML in batches of 100 until complete. Future saves refresh their own index. Incoming-page counts help identify orphan candidates. Unresolved internal post IDs can be valid archive/custom routes; they are not automatically classified as broken links. Public HTTP HEAD checks process five distinct indexed URLs per request. Private/unsafe URLs are skipped. 404/410, redirects, access restrictions, unsupported HEAD and network failures are reported distinctly, without changing links automatically.

Exact link replacement previews up to 200 matching pages at a time. It changes only matching href attributes, checks for concurrent edits and requires WordPress revisions. Original content can be restored with those revisions. If a batch stops mid-way, review completed pages and generate a fresh preview; it is not a cross-post database transaction.

Approved phrase-to-URL rules can optionally add at most three same-site links during rendering, once per phrase. Existing anchors, headings, code and form controls are skipped. Stored content is not rewritten. These rendered links are not part of the saved-HTML inventory. Inspect actual output with your theme, especially complex builder content.

CSV tools export 100 rows per page and preview up to 100 metadata rows per batch. Supported columns are `ID,title,description,focus_keyword`. Empty values intentionally clear metadata. Formula-like cells are escaped in exports. Apply performs conflict checks. The last metadata batch can be undone for 24 hours if those fields have not been edited since. Preview expiry is 10 minutes.

### Search history and local writing

Search history uses your authorized Search Console connection. Manual or daily WP-Cron fetches store the top 1,000 finalized queries for the day three days before the run. Up to 365 property/day snapshots are retained locally. Track up to 1,000 exact phrases; a phrase not returned is shown as unknown, not position zero. The interface shows the latest two snapshots. This measures Search Console average position, not live country/device rank. It cannot backfill a paid rank-tracking provider's historical database. Disconnecting Google removes tokens and the current report; existing site-owned history snapshots remain until retention removes them or an administrator deletes them.

Local writing optionally calls Ollama at `http://127.0.0.1:11434/api/generate` on the **WordPress server**. Install a compatible model there and set `define('OPEN_SEO_OLLAMA_MODEL', 'your-installed-model');` in wp-config.php. Calls are limited to 55 seconds and return reviewable drafts/briefs/metadata suggestions. Nothing is auto-published. No paid/cloud endpoint or provider key is used by this tool. Server resources, installed models and factual accuracy remain your responsibility. Shared hosts may not support Ollama. No local model was installed or run in validation; the client was tested with mocked responses.

Not implemented: an exhaustive clone of every upstream PRO/service feature, commercial keyword-volume/backlink datasets, localized live SERP tracking, agency/client reporting, or automated cross-platform AI mention/sentiment measurement. Local model output is not a measurement of ChatGPT, Claude, Gemini or Perplexity visibility. No license checks are bypassed and no paid service is accessed without authorization. Google accounts/API quotas remain external.

GEO prompts are heuristics, not an AI citation score. They inspect saved HTML, not rendered page-builder output or dynamic blocks. Detailed findings include external source URLs (distinguished from internal links), heading order, author biography, meaningful freshness, opening summaries, canonical differences and visibility barriers. Related-content candidates use title/excerpt overlap in the latest 200 posts/pages. A source URL is not proof that its claims are accurate. Schema is added only from your explicit templates, media metadata, locations or visible FAQ entries. The inherited engine remains responsible for its own metadata/schema; selected primary templates replace its primary node to reduce duplication.

Crawler preferences default to preserving existing policy. Checking a bot adds a Disallow rule to WordPress's virtual robots.txt. A physical robots.txt, upstream custom robots output, CDN cache or firewall can prevent this from taking effect. Verify the public response. Rules are advisory; they cannot enforce compliance or remove previously collected data. This plugin does not explicitly allow all AI bots or override a private site's visibility choice. Google-Extended is separate from Google Search and can affect Gemini grounding as well as training.

## Updates and rollback

Automatic replacement by the upstream WordPress.org release is suppressed for this derivative. Track upstream security releases and merge/test updates before use. The original engine version remains 1.0.277.2 internally for migration compatibility; this release's plugin header version is 1.3.0. Upstream rollback/auto-update controls are not offered, since they could overwrite this independent build.

Version 1.3.0 preserves stable internal admin-screen identifiers independently of the visible Open SEO menu name, fixing blank inherited settings screens. Upload it and replace the current Open SEO plugin; settings/data and the default-on automation behavior are retained. Plugin assets receive a new version query parameter. Purge host/CDN caches only if they continue serving the previous files.

To roll back, disconnect Google, deactivate Open SEO and reactivate your original Rank Math plugin. Do not run both. Inherited SEO data remains by default. Removing the plugin does not erase settings; explicitly disconnect Google before deletion. The upstream clear-data-on-uninstall filter still applies to inherited Rank Math data if you enabled it yourself.

No ranking, rich result, indexing, AI citation or error-free operation is guaranteed. Validate staging output, caching, theme/builder compatibility and Google authorization before production.

## Reference documentation

- https://developers.google.com/webmaster-tools/v1/how-tos/authorizing
- https://developers.google.com/webmaster-tools/v1/searchanalytics/query
- https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect
- https://developers.google.com/search/docs/appearance/ai-features
- https://developers.openai.com/api/docs/bots
- https://developers.google.com/search/docs/crawling-indexing/overview-google-crawlers#google-extended




