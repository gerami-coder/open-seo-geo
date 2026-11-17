<?php
/** Native WordPress controls for independent features. GPL-3.0-or-later, 2026-11-16. */
namespace OpenSEO;
defined('ABSPATH') || exit;
final class SuiteAdmin {
    public static function start($op, $tab) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="open_seo_suite"><input type="hidden" name="operation" value="' . esc_attr($op) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">'; wp_nonce_field('open_seo_suite');
    }
    public static function end($label) { submit_button($label); echo '</form>'; }
    public static function input($name, $label, $value = '', $type = 'text') {
        echo '<p><label for="os-' . esc_attr($name) . '">' . esc_html($label) . '</label><br><input class="regular-text" id="os-' . esc_attr($name) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr($value) . '"></p>';
    }
    public static function area($name, $label, $value = '') {
        echo '<p><label for="os-' . esc_attr($name) . '">' . esc_html($label) . '</label></p><textarea class="large-text code" rows="8" id="os-' . esc_attr($name) . '" name="' . esc_attr($name) . '">' . esc_textarea($value) . '</textarea>';
    }
    public static function messages() {
        if (!class_exists('DOMDocument')) { echo '<div class="notice notice-warning rank-math-notice"><p>PHP DOM is required for content automation, detailed GEO reviews and link analysis. Enable it at your host.</p></div>'; }
        $message = get_transient('open_seo_suite_notice_' . get_current_user_id());
        if ($message) { echo '<div class="notice notice-info rank-math-notice" role="status"><p>' . esc_html($message) . '</p></div>'; delete_transient('open_seo_suite_notice_' . get_current_user_id()); }
    }
    public static function handle() {
        guard(); check_admin_referer('open_seo_suite');
        $op = sanitize_key(wp_unslash($_POST['operation'] ?? '')); $tab = sanitize_key(wp_unslash($_POST['tab'] ?? 'automation')); $uid = get_current_user_id();
        try {
            if ($op === 'automation') {
                $was_enabled = Automation::enabled();
                update_option('open_seo_automation', isset($_POST['enabled']) ? 1 : 0, false);
                if (!empty($_POST['enabled']) && (!$was_enabled || !empty($_POST['existing']))) { update_option('open_seo_auto_cursor', 0, false); update_option('open_seo_auto_complete', 0, false); if (!wp_next_scheduled('open_seo_auto_batch')) { wp_schedule_single_event(time() + 10, 'open_seo_auto_batch'); } }
                if (empty($_POST['enabled'])) { wp_clear_scheduled_hook('open_seo_auto_batch'); }
                Suite::notice('Automation preference saved. Existing authored fields are preserved. Background processing uses WP-Cron.');
            }
            elseif ($op === 'autobatch') { $result = Automation::batch(!empty($_POST['reset'])); Suite::notice($result['scanned'] . ' pages scanned; ' . $result['filled'] . ' empty fields filled. ' . ($result['complete'] ? 'Batch scan complete.' : 'Run the next batch to continue.')); }
            elseif ($op === 'media') {
                $opt = []; foreach (['news', 'video', 'podcast'] as $key) { $opt[$key] = isset($_POST[$key]) ? 1 : 0; }
                foreach (['publication', 'description', 'author', 'language'] as $key) { $opt[$key] = sanitize_text_field(wp_unslash($_POST[$key] ?? '')); }
                if (!$opt['publication'] || !preg_match('/^[a-z]{2,3}(?:-[a-z]{2,4})?$/', $opt['language'])) { throw new \RuntimeException('Set a publication name and valid news language code, such as en or zh-cn.'); }
                $opt['image'] = esc_url_raw(wp_unslash($_POST['image'] ?? ''), ['http', 'https']); update_option('open_seo_media', $opt, false); Suite::notice('Sitemaps and podcast preferences saved.');
            } elseif ($op === 'templates') {
                $data = Suite::json(wp_unslash($_POST['json'] ?? '{}'));
                if (count($data) > 50) { throw new \RuntimeException('Use up to 50 templates in this configuration.'); }
                $clean = []; foreach ($data as $key => $template) { if (!is_array($template) || !sanitize_key((string) $key)) { throw new \RuntimeException('Use named template objects.'); } $clean[sanitize_key($key)] = Schema::validate($template); }
                update_option('open_seo_templates', $clean, false); Suite::notice('Schema templates saved. Select a template on each relevant page; verify all required properties and visible supporting content.');
            } elseif ($op === 'locations') { update_option('open_seo_locations', Schema::locations(Suite::json(wp_unslash($_POST['json'] ?? '{}'))), false); Suite::notice('Business locations saved.'); }
            elseif ($op === 'scan') { $result = Links::batch(!empty($_POST['reset'])); Suite::notice($result['count'] . ' posts processed through ID ' . $result['cursor'] . '. ' . ($result['complete'] ? 'Scan complete.' : 'Run next batch to continue.')); }
            elseif ($op === 'checklinks') { Suite::notice(Links::check_batch() . ' distinct URLs checked. Network failures and unresolved post IDs are not confirmed broken links.'); }
            elseif ($op === 'autolinks') {
                $rules = Suite::json(wp_unslash($_POST['json'] ?? '{}')); $clean = [];
                if (count($rules) > 50) { throw new \RuntimeException('Use at most 50 approved linking phrases.'); }
                foreach ($rules as $phrase => $url) { $phrase = sanitize_text_field($phrase); $url = esc_url_raw($url, ['http', 'https']); if (!$phrase || !$url || strtolower((string) wp_parse_url($url, PHP_URL_HOST)) !== strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))) { throw new \RuntimeException('Each rule needs a phrase and a same-site HTTP(S) destination.'); } $clean[$phrase] = $url; }
                update_option('open_seo_autolink_rules', $clean, false); update_option('open_seo_autolink_enabled', isset($_POST['enabled']) ? 1 : 0, false); Suite::notice('Approved keyword linking rules saved. Saved post content is not changed.');
            }
            elseif ($op === 'linkpreview') { $preview = Links::preview(esc_url_raw(wp_unslash($_POST['old'] ?? '')), esc_url_raw(wp_unslash($_POST['new'] ?? ''))); set_transient('open_seo_link_preview_' . $uid, $preview, 600); Suite::notice('Preview ready. Exact saved href matches only; up to 200 pages per batch.'); }
            elseif ($op === 'linkapply') { $preview = get_transient('open_seo_link_preview_' . $uid); if (!$preview) { throw new \RuntimeException('Preview expired.'); } delete_transient('open_seo_link_preview_' . $uid); Suite::notice(Links::apply($preview) . ' links replaced. WordPress revisions retain original content.'); }
            elseif ($op === 'csvpreview') { $rows = Bulk::preview(wp_unslash($_POST['csv'] ?? '')); set_transient('open_seo_csv_preview_' . $uid, $rows, 600); Suite::notice('Metadata preview ready. Blank values in the CSV intentionally clear metadata.'); }
            elseif ($op === 'csvapply') { $rows = get_transient('open_seo_csv_preview_' . $uid); if (!$rows) { throw new \RuntimeException('Preview expired.'); } delete_transient('open_seo_csv_preview_' . $uid); $count = Bulk::apply($rows); set_transient('open_seo_csv_undo_' . $uid, $rows, DAY_IN_SECONDS); Suite::notice($count . ' metadata rows updated. Undo is available for 24 hours, until another edit changes those fields.'); }
            elseif ($op === 'csvundo') { $rows = get_transient('open_seo_csv_undo_' . $uid); if (!$rows) { throw new \RuntimeException('No current undo batch.'); } $count = Bulk::apply($rows, true); delete_transient('open_seo_csv_undo_' . $uid); Suite::notice($count . ' metadata rows restored.'); }
            elseif ($op === 'csvexport') { Bulk::export(absint($_POST['page'] ?? 1)); }
            elseif ($op === 'historysave') {
                $keywords = array_values(array_unique(array_filter(array_map('sanitize_text_field', preg_split('/\r\n|\r|\n/', wp_unslash($_POST['keywords'] ?? ''))))));
                if (count($keywords) > 1000) { throw new \RuntimeException('Use at most 1,000 tracked phrases per site.'); }
                update_option('open_seo_keywords', $keywords, false); update_option('open_seo_history_enabled', isset($_POST['enabled']) ? 1 : 0, false); History::schedule(); Suite::notice('History preferences saved.');
            } elseif ($op === 'historysync') { Suite::notice('Search Console snapshot saved for ' . History::sync()); }
            elseif ($op === 'draft') { set_transient('open_seo_draft_' . $uid, Assistant::generate(absint($_POST['id'] ?? 0), sanitize_key(wp_unslash($_POST['task'] ?? 'brief'))), HOUR_IN_SECONDS); Suite::notice('Local-model draft ready for review. Nothing was published.'); }
            elseif ($op === 'analyze') {
                $report = [Assistant::fetch(esc_url_raw(wp_unslash($_POST['url'] ?? '')))];
                $competitor = esc_url_raw(wp_unslash($_POST['competitor'] ?? ''));
                if ($competitor) { $report[] = Assistant::fetch($competitor); }
                set_transient('open_seo_compare_' . $uid, $report, HOUR_IN_SECONDS); Suite::notice('Public HTML analyzed locally. No upstream account or subscription was used.');
            } else { throw new \RuntimeException('Unknown operation.'); }
        } catch (\Throwable $e) { Suite::notice($e->getMessage()); }
        wp_safe_redirect($tab === 'analyzer' ? admin_url('admin.php?page=rank-math-seo-analysis') : Suite::url($tab)); exit;
    }
    public static function page() {
        guard(); $tabs = ['automation' => 'Automation', 'media' => 'News, video & podcast', 'schema' => 'Schema & locations', 'links' => 'Internal links', 'bulk' => 'Bulk metadata', 'history' => 'Search history', 'assistant' => 'Local writing'];
        $tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'automation')); if (!isset($tabs[$tab])) { $tab = 'automation'; }
        echo '<div class="wrap"><h1>Open SEO · Local tools</h1><p>Independent implementations. No plugin subscription or credit balance. Configure factual data before enabling public output.</p><nav class="nav-tab-wrapper" aria-label="Local SEO tools">';
        foreach ($tabs as $key => $label) { echo '<a class="nav-tab' . ($key === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url(Suite::url($key)) . '" ' . ($key === $tab ? 'aria-current="page"' : '') . '>' . esc_html($label) . '</a>'; }
        echo '</nav>'; self::messages(); self::$tab(); echo '</div>';
    }
    public static function automation() {
        echo '<h2>Fill empty SEO fields from content</h2><p>Extract up to five focus phrases using weighted title, heading and body terms. Titles use the page title; descriptions use saved excerpts or opening text. This does not measure keyword volume or guarantee the best target phrase. Review suggestions for your audience and language.</p>';
        echo '<p>Automation is enabled by default. Installing or upgrading queues existing posts/pages automatically; new saves fill blank fields. Written values and excluded pages are preserved.</p>';
        self::start('automation', 'automation'); echo '<p><label><input name="enabled" type="checkbox" value="1" ' . checked(Automation::enabled(), true, false) . '> Automatically fill empty titles, descriptions and focus keywords when posts/pages are saved</label></p><p><label><input type="checkbox" name="existing" value="1"> Restart the background scan of all existing posts/pages</label></p>'; self::end('Save automation');
        echo '<p>Authored SEO values are preserved. Exclude a page in its Media & schema box. Reload the editor after saving to see values filled on the server. For Gutenberg drafts, save the draft before reloading.</p>';
        self::start('autobatch', 'automation'); echo '<p><label><input type="checkbox" name="reset" value="1"> Restart existing-content scan from the beginning</label></p>'; self::end('Fill next 50 existing pages');
        $status = get_option('open_seo_auto_status');
        if ($status) { echo '<p>Last background batch: ' . esc_html($status['at']) . ' · ' . (int) $status['result']['scanned'] . ' pages scanned · ' . (int) $status['result']['filled'] . ' fields filled · ' . ($status['result']['complete'] ? 'scan complete' : 'more pages remain') . '.</p>'; }
        if (wp_next_scheduled('open_seo_auto_batch')) { echo '<p>A background batch is queued. WP-Cron will run it when site traffic or your server cron triggers the scheduler.</p>'; }
    }
    public static function media() {
        $opt = Media::options(); echo '<h2>Public media feeds</h2><p>News includes marked, indexable articles from the last 48 hours. Video includes pages with complete supplied video metadata. Podcast episodes need a local audio attachment. Configure each page in the editor’s Media & schema box.</p>';
        self::start('media', 'media'); foreach (['news' => 'News sitemap', 'video' => 'Video sitemap', 'podcast' => 'Podcast RSS'] as $key => $label) { echo '<p><label><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked($opt[$key] ?? 0, 1, false) . '> Enable ' . esc_html($label) . '</label> · <a href="' . esc_url(Media::endpoint($key)) . '" target="_blank" rel="noopener">View endpoint</a></p>'; }
        self::input('publication', 'Publication / series name', $opt['publication'] ?? get_bloginfo('name')); self::input('language', 'News language code', $opt['language'] ?? substr(get_locale(), 0, 2)); self::input('description', 'Podcast series description', $opt['description'] ?? get_bloginfo('description')); self::input('author', 'Podcast author', $opt['author'] ?? ''); self::input('image', 'Podcast artwork URL', $opt['image'] ?? '', 'url'); self::end('Save media feeds');
        echo '<p>Sitemaps are paginated in groups of 1,000 candidates using <code>open_seo_page=2</code>, etc. Podcast RSS exposes the latest 100 candidates. Submit additional sitemap pages in Search Console when needed. Empty/disabled feeds are not evidence of search-engine acceptance.</p>';
    }
    public static function schema() {
        echo '<h2>Reusable schema templates</h2><p>Use a JSON object of named templates. Tokens: {{title}}, {{url}}, {{description}}, {{author}}, {{published}}, {{modified}}. Select the template on each page. Supply all properties required for your schema type and visible content. There is no special GEO schema and rich results are not guaranteed.</p>';
        self::start('templates', 'schema'); self::area('json', 'Templates JSON', wp_json_encode(get_option('open_seo_templates', (object) []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); self::end('Save schema templates');
        echo '<p>Example: <code>{"article":{"@type":"Article","headline":"{{title}}","datePublished":"{{published}}","author":{"@type":"Person","name":"{{author}}"}}}</code></p><h2>Multiple business locations</h2><p>Give every location its own public page_id, name and PostalAddress. Optional telephone, geo, image, openingHoursSpecification, sameAs and priceRange are passed through from your supplied facts. The plugin does not verify addresses or opening hours.</p>';
        self::start('locations', 'schema'); self::area('json', 'Locations JSON', wp_json_encode(get_option('open_seo_locations', (object) []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); self::end('Save business locations');
        echo '<p>Example: <code>{"main":{"page_id":123,"name":"Your business","address":{"streetAddress":"Your street","addressLocality":"Your city","addressCountry":"ET"}}}</code></p><p>Add visible questions using <code>[open_seo_faq question="Question"]Answer[/open_seo_faq]</code>. FAQ structured data matches those visible entries. Use <code>[open_seo_summary]Your factual summary[/open_seo_summary]</code> for a visible answer summary.</p>';
    }
    public static function links() {
        global $wpdb; echo '<h2>Internal link inventory</h2><p>Each batch processes 100 posts/pages. Continue until complete. New saves refresh their index. Counts cover links in saved HTML, excluding self-links; menus, reusable blocks and page-builder output are not rendered. Zero incoming links is a review candidate, not proof of a site-wide orphan.</p>';
        self::start('scan', 'links'); echo '<p><label><input type="checkbox" name="reset" value="1"> Restart scan</label></p>'; self::end('Scan next 100 posts');
        echo '<table class="widefat striped"><thead><tr><th>Page (up to 200)</th><th>Incoming pages</th></tr></thead><tbody>';
        foreach (Links::rows() as $row) { echo '<tr><td><a href="' . esc_url(get_edit_post_link($row['ID'])) . '">' . esc_html($row['post_title']) . '</a></td><td>' . (int) $row['incoming'] . '</td></tr>'; } echo '</tbody></table>';
        $unresolved = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Links::table() . ' WHERE internal=1 AND target_id=0');
        echo '<p>' . $unresolved . ' internal links did not resolve to a post ID. Archives, attachment routes and custom endpoints can be valid; this is not a confirmed HTTP-404 count.</p><h2>Replace exact link URLs</h2><p>Preview first. Only matching href attributes change. Original content remains in WordPress revisions. Up to 200 matching pages per batch; rebuild the index first.</p>';
        self::start('checklinks', 'links'); self::end('Check next 5 public link URLs');
        $checks = $wpdb->get_results('SELECT url, http_status FROM ' . Links::table() . ' WHERE last_http_check IS NOT NULL ORDER BY last_http_check DESC LIMIT 30', ARRAY_A);
        if ($checks) { echo '<table class="widefat striped"><thead><tr><th>Checked URL</th><th>HTTP result</th></tr></thead><tbody>'; foreach ($checks as $check) { echo '<tr><td>' . esc_html($check['url']) . '</td><td>' . esc_html((int) $check['http_status'] > 0 ? $check['http_status'] : ((int) $check['http_status'] === -2 ? 'Skipped: non-public/unsafe URL' : 'Network failure')) . '</td></tr>'; } echo '</tbody></table><p>404/410 indicate a missing response. Redirects, 403 and 405 require review; no redirect or access rule is changed automatically.</p>'; }
        self::start('linkpreview', 'links'); self::input('old', 'Existing absolute URL', '', 'url'); self::input('new', 'Replacement absolute URL', '', 'url'); self::end('Preview link replacement');
        $preview = get_transient('open_seo_link_preview_' . get_current_user_id());
        if ($preview) { echo '<h3>Replacement preview</h3><p>' . esc_html($preview['old'] . ' → ' . $preview['new']) . '</p><ul>'; foreach ($preview['rows'] as $row) { echo '<li>' . esc_html(get_the_title($row['id'])) . ': ' . (int) $row['count'] . ' links</li>'; } echo '</ul>'; self::start('linkapply', 'links'); self::end('Apply previewed link changes'); }
        echo '<h2>Approved keyword auto-linking</h2><p>Map your chosen phrases to same-site URLs. At most three links are inserted per rendered page, once per phrase. Existing links, headings, code and form controls are skipped. Saved content is not rewritten. Review rendered output with your theme before enabling broadly.</p>';
        self::start('autolinks', 'links'); echo '<p><label><input name="enabled" type="checkbox" value="1" ' . checked(get_option('open_seo_autolink_enabled'), 1, false) . '> Enable approved linking rules</label></p>'; self::area('json', 'Phrase-to-URL JSON', wp_json_encode(get_option('open_seo_autolink_rules', (object) []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); self::end('Save keyword linking');
    }
    public static function bulk() {
        echo '<h2>Metadata CSV</h2><p>Export or preview up to 100 rows per batch. CSV header: ID,title,description,focus_keyword. Preview expiry: 10 minutes. Empty CSV fields clear existing values. Metadata undo is available for the last applied batch for 24 hours and refuses to overwrite subsequent edits.</p>';
        self::start('csvexport', 'bulk'); self::input('page', 'Export page (100 rows per page)', 1, 'number'); self::end('Download metadata CSV');
        self::start('csvpreview', 'bulk'); self::area('csv', 'Paste metadata CSV'); self::end('Preview metadata changes');
        $rows = get_transient('open_seo_csv_preview_' . get_current_user_id());
        if ($rows) { echo '<h3>Metadata preview</h3><table class="widefat striped"><thead><tr><th>Post</th><th>Current values</th><th>Proposed values</th></tr></thead><tbody>'; foreach ($rows as $row) { echo '<tr><td>' . (int) $row['id'] . '</td><td>' . esc_html(wp_json_encode($row['before'])) . '</td><td>' . esc_html(wp_json_encode($row['after'])) . '</td></tr>'; } echo '</tbody></table>'; self::start('csvapply', 'bulk'); self::end('Apply previewed metadata'); }
        if (get_transient('open_seo_csv_undo_' . get_current_user_id())) { self::start('csvundo', 'bulk'); self::end('Undo last metadata batch'); }
    }
    public static function history() {
        echo '<h2>Site-owned query history</h2><p>Requires the direct Google connection. Saves the top 1,000 Search Console queries for a finalized day, three days before the run. Retains up to 365 property/day snapshots locally. Average position is Google’s aggregated metric, not a fixed country/device SERP rank. A missing phrase means not returned, not position zero. WP-Cron depends on site traffic or a server cron.</p>';
        self::start('historysave', 'history'); echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked(get_option('open_seo_history_enabled'), 1, false) . '> Save a snapshot daily with WP-Cron</label></p>'; self::area('keywords', 'Tracked phrases, one exact query per line', implode("\n", (array) get_option('open_seo_keywords', []))); self::end('Save history preferences');
        self::start('historysync', 'history'); self::end('Fetch finalized daily snapshot');
        $status = get_option('open_seo_history_status'); if ($status) { echo '<p>' . esc_html($status['at'] . ': ' . $status['message']) . '</p>'; }
        $days = History::latest(get_option('open_seo_property', ''));
        foreach ($days as $day) {
            echo '<h3>' . esc_html($day['date']) . '</h3><table class="widefat striped"><thead><tr><th>Query</th><th>Clicks</th><th>Impressions</th><th>Average position</th></tr></thead><tbody>';
            $rows = get_option('open_seo_keywords') ? History::tracked($day) : array_map(function ($row) { return ['query' => $row['keys'][0], 'data' => $row]; }, array_slice($day['rows'], 0, 100));
            foreach ($rows as $row) { echo '<tr><td>' . esc_html($row['query']) . '</td>'; foreach (['clicks', 'impressions', 'position'] as $field) { echo '<td>' . esc_html($row['data'] ? round($row['data'][$field], 2) : 'Not returned') . '</td>'; } echo '</tr>'; } echo '</tbody></table>';
        }
    }
    public static function assistant() {
        echo '<h2>Local-model writing drafts</h2><p>Optional Ollama on the WordPress server at 127.0.0.1:11434. Install a compatible model, then define OPEN_SEO_OLLAMA_MODEL in wp-config.php. No cloud API is called. Content is sent only to that loopback service when requested. The local model needs server resources and may time out on shared hosting. Drafts are never auto-published and require fact checking.</p>';
        self::start('draft', 'assistant'); self::input('id', 'Source post ID', '', 'number'); echo '<p><label for="os-task">Writing task</label> <select id="os-task" name="task"><option value="brief">Content brief</option><option value="metadata">Titles and descriptions</option><option value="rewrite">Introduction and summary</option></select></p>'; self::end('Generate local draft');
        $draft = get_transient('open_seo_draft_' . get_current_user_id()); if ($draft) { echo '<h3>Draft · ' . esc_html($draft['model']) . '</h3><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html($draft['draft']) . '</pre>'; }
    }
    public static function analyzer() {
        guard(); echo '<div class="wrap"><h1>Open SEO · SEO & Competitor Analyzer</h1>'; self::messages(); echo '<p>Fetch and compare the public HTML of two pages. No upgrade, subscription or upstream account. Reports observed on-page signals only; this is not a backlink database, keyword-volume service or search ranking report. Scripts are not executed. Redirects must be entered using their final URLs.</p>';
        self::start('analyze', 'analyzer'); self::input('url', 'Your public page URL', home_url('/'), 'url'); self::input('competitor', 'Competitor page URL (optional)', '', 'url'); self::end('Analyze public pages');
        $reports = get_transient('open_seo_compare_' . get_current_user_id());
        if ($reports) { echo '<table class="widefat striped"><thead><tr><th>Observed signal</th>'; foreach ($reports as $report) { echo '<th>' . esc_html($report['url']) . '</th>'; } echo '</tr></thead><tbody>'; foreach (['status', 'title', 'description', 'canonical', 'h1_count', 'h2_count', 'word_count', 'images_missing_alt', 'json_ld_blocks', 'robots', 'x_robots_tag', 'terms', 'at'] as $key) { echo '<tr><th scope="row">' . esc_html(str_replace('_', ' ', $key)) . '</th>'; foreach ($reports as $report) { echo '<td>' . esc_html(is_array($report[$key]) ? implode(', ', $report[$key]) : $report[$key]) . '</td>'; } echo '</tr>'; } echo '</tbody></table>'; }
        echo '</div>';
    }
}
add_action('admin_notices', function () { if (get_current_screen() && get_current_screen()->base === 'post') { SuiteAdmin::messages(); } });
