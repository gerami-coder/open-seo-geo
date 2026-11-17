<?php
/** Open SEO additions, GPL-3.0-or-later. Modified 2026-11-16. */
namespace OpenSEO;
defined('ABSPATH') || exit;
require_once __DIR__ . '/open-seo/gsc.php';
require_once __DIR__ . '/open-seo/geo.php';
require_once __DIR__ . '/open-seo/suite.php';

add_filter('rank_math/modules', function ($modules) {
    foreach ($modules as $id => &$module) {
        if (!empty($module['disabled']) && !empty($module['probadge'])) {
            unset($modules[$id]);
            continue;
        }
        unset($module['upgradeable']);
    }
    unset($module);
    // Hosted subscriptions are not included in this derivative.
    unset($modules['content-ai'], $modules['ai-visibility'], $modules['analytics'], $modules['version-control']);
    return $modules;
}, 999);

add_filter('site_transient_update_plugins', function ($value) {
    // Prevent an upstream update from silently replacing this derivative.
    if (is_object($value)) {
        unset($value->response[plugin_basename(__DIR__ . '/rank-math.php')]);
    }
    return $value;
});

function stabilize_screen_ids() {
    // WordPress derives child-screen IDs from the parent's display title.
    // Keep the engine's established hook namespace while displaying Open SEO.
    global $admin_page_hooks;
    if (isset($admin_page_hooks['rank-math'])) { $admin_page_hooks['rank-math'] = 'rank-math'; }
}
// The parent menu registers at -1; children start at 4. Run between them.
add_action('admin_menu', __NAMESPACE__ . '\\stabilize_screen_ids', 0);
add_action('network_admin_menu', __NAMESPACE__ . '\\stabilize_screen_ids', 0);

add_action('admin_menu', function () {
    add_submenu_page('rank-math', 'AI Search & Search Console', 'AI Search & Console', 'manage_options', 'open-seo', __NAMESPACE__ . '\\page');
    global $menu;
    remove_submenu_page('rank-math', 'rank-math-link-genius');
    remove_submenu_page('rank-math', 'rank-math-links');
    remove_submenu_page('rank-math', 'rank-math-links-page');
    foreach ($menu as &$item) {
        if (isset($item[2]) && $item[2] === 'rank-math') { $item[0] = 'Open SEO'; }
    }
}, 1000);

function guard() {
    if (!current_user_can('manage_options')) { wp_die('Administrator access required.', '', ['response' => 403]); }
}
function form($operation, $label) {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="open_seo_action"><input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
    wp_nonce_field('open_seo_action');
    if ($label) { submit_button($label, 'secondary'); echo '</form>'; }
}
function finish($message) {
    set_transient('open_seo_message_' . get_current_user_id(), $message, 60);
    wp_safe_redirect(admin_url('admin.php?page=open-seo'));
    exit;
}
add_action('admin_post_open_seo_action', function () {
    guard(); check_admin_referer('open_seo_action');
    $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
    try {
        if ($operation === 'policy') {
            $settings = [];
            foreach (Suite::bots() as $bot) {
                $settings[$bot] = isset($_POST['block'][$bot]) ? 'block' : 'preserve';
            }
            update_option('open_seo_policy', $settings, false);
            finish('Crawler preferences saved. Purge your robots.txt/CDN cache if applicable.');
        }
        if ($operation === 'connect') { GSC::connect(); }
        if ($operation === 'disconnect') { GSC::disconnect(); finish('Google connection removed.'); }
        if ($operation === 'sync') {
            $sites = GSC::request('https://www.googleapis.com/webmasters/v3/sites');
            $property = sanitize_text_field(wp_unslash($_POST['property'] ?? ''));
            $available = array_column($sites['siteEntry'] ?? [], 'siteUrl');
            if (!in_array($property, $available, true)) { throw new \RuntimeException('Select a property available to your Google account.'); }
            update_option('open_seo_property', $property, false);
            $data = GSC::request('https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/searchAnalytics/query', [
                'startDate' => gmdate('Y-m-d', time() - 30 * DAY_IN_SECONDS),
                'endDate' => gmdate('Y-m-d', time() - 3 * DAY_IN_SECONDS),
                'dimensions' => ['query'], 'rowLimit' => 100,
            ]);
            update_option('open_seo_report', ['at' => gmdate('c'), 'property' => $property, 'data' => $data], false);
            finish('Search Console report refreshed. Results show the top 100 queries, not site-wide totals.');
        }
        if ($operation === 'inspect') {
            $url = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
            $property = get_option('open_seo_property', '');
            if (!$property || !GSC::belongs($url, $property)) { throw new \RuntimeException('Enter a URL within your selected property.'); }
            $result = GSC::request('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
                'inspectionUrl' => $url, 'siteUrl' => $property, 'languageCode' => 'en-US',
            ]);
            set_transient('open_seo_inspection_' . get_current_user_id(), $result, 600);
            finish('URL inspection retrieved. This reports Google’s indexed version; it does not request indexing.');
        }
        if ($operation === 'audit') {
            update_option('open_seo_audit', GEO::audit(), false);
            finish('GEO review completed for up to 200 recent public posts and pages.');
        }
        throw new \RuntimeException('Unknown action.');
    } catch (\Throwable $error) { finish($error->getMessage()); }
});

function page() {
    guard();
    echo '<div class="wrap open-seo-admin"><h1>AI Search Readiness & Search Console</h1><p><strong>GEO means helping AI-assisted search understand and potentially cite useful content.</strong> Start with readable pages, clear answers and accurate, consistent facts. It is not a special AI tag or a guaranteed citation score.</p><p><a class="button button-primary" href="'.esc_url(HealthAdmin::url()).'">Check SEO and AI readiness</a> <a class="button" href="'.esc_url(SharedContentAdmin::url()).'">Set shared website information</a></p>';
    $message = get_transient('open_seo_message_' . get_current_user_id());
    if ($message) { echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>'; delete_transient('open_seo_message_' . get_current_user_id()); }
    echo '<details><summary>Advanced: AI crawler preferences and llms.txt</summary><h2>AI crawler preferences</h2><p>Unchecked means preserve existing policy. Blocking is advisory and does not enforce access control. Search visibility also depends on your host, CDN, robots rules and indexing settings.</p>';
    if (!get_option('blog_public')) { echo '<div class="notice notice-warning inline"><p>This site discourages search engines. Open SEO will not override that setting.</p></div>'; }
    if (file_exists(ABSPATH . 'robots.txt')) { echo '<div class="notice notice-warning inline"><p>A physical robots.txt exists. Virtual WordPress crawler preferences may not reach visitors; edit the physical file at your host.</p></div>'; }
    form('policy', '');
    $policy = get_option('open_seo_policy', []);
    foreach (['GPTBot' => 'GPTBot — OpenAI model training', 'OAI-SearchBot' => 'OAI-SearchBot — ChatGPT search discovery', 'Google-Extended' => 'Google-Extended — Gemini training and grounding (separate from Google Search)', 'ClaudeBot' => 'ClaudeBot — Anthropic training', 'Claude-SearchBot' => 'Claude-SearchBot — Claude search', 'PerplexityBot' => 'PerplexityBot — Perplexity search'] as $bot => $label) {
        echo '<p><label><input type="checkbox" name="block[' . esc_attr($bot) . ']" value="1" ' . checked($policy[$bot] ?? '', 'block', false) . '> Block ' . esc_html($label) . '</label></p>';
    }
    submit_button('Save crawler preferences'); echo '</form>';
    echo '<p><a href="' . esc_url(home_url('/robots.txt')) . '" target="_blank" rel="noopener">View robots.txt</a> · <a href="' . esc_url(admin_url('admin.php?page=rank-math-options-general#setting-panel-llms')) . '">Configure the inherited llms.txt module</a></p>';
    echo '</details><details><summary>Advanced: saved-content editorial checks</summary><h2>AI search readiness (GEO)</h2><p>This older editorial checklist inspects saved HTML. For Oxygen and other builders, use SEO Health & Fixes to inspect published HTML. These are review prompts, not ranking or citation scores. llms.txt is optional.</p>';
    form('audit', 'Review recent public content');
    $audit = get_option('open_seo_audit', []);
    if ($audit) {
        echo '<p>Reviewed ' . esc_html($audit['at']) . '. Skipped ' . esc_html($audit['skipped']) . ' protected or noindex items.</p><table class="widefat striped"><thead><tr><th>Content</th><th>Review prompts</th></tr></thead><tbody>';
        foreach ($audit['rows'] as $row) {
            echo '<tr><td><a href="' . esc_url(get_edit_post_link($row['id'])) . '">' . esc_html($row['title']) . '</a></td><td>' . esc_html(implode(' · ', $row['notes']));
            if (!empty($row['review'])) { foreach ($row['review']['findings'] as $finding) { echo '<p><strong>' . esc_html($finding['code']) . ':</strong> ' . esc_html($finding['evidence'] . ' ' . $finding['action']) . '</p>'; } }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</details><h2>Direct Google Search Console</h2><p>Uses your own Google OAuth application and read-only access. No Open SEO account is required for this connection.</p><p>Set <code>OPEN_SEO_GOOGLE_CLIENT_ID</code> and <code>OPEN_SEO_GOOGLE_CLIENT_SECRET</code> in wp-config.php. Enable Search Console API and register this exact Web application redirect URI:</p><p><code>' . esc_html(GSC::callback()) . '</code></p>';
    if (!GSC::configured()) { echo '<p>Google credentials are not configured yet. See INSTALL.md in the package.</p>'; }
    elseif (!get_option('open_seo_tokens')) { form('connect', 'Connect Google'); }
    else {
        form('disconnect', 'Disconnect and revoke Google tokens');
        try {
            $sites = GSC::request('https://www.googleapis.com/webmasters/v3/sites');
            form('sync', '');
            echo '<label for="open-seo-property">Verified Search Console property</label> <select id="open-seo-property" name="property">';
            foreach ($sites['siteEntry'] ?? [] as $site) {
                echo '<option value="' . esc_attr($site['siteUrl']) . '" ' . selected(get_option('open_seo_property'), $site['siteUrl'], false) . '>' . esc_html($site['siteUrl']) . '</option>';
            }
            echo '</select>'; submit_button('Fetch recent queries'); echo '</form>';
        } catch (\Throwable $e) { echo '<p>' . esc_html($e->getMessage()) . '</p>'; }
        form('inspect', '');
        echo '<label for="open-seo-url">Inspect a URL in the selected property</label><p><input class="large-text" type="url" required id="open-seo-url" name="url" placeholder="https://example.com/page/"></p>';
        submit_button('Inspect indexed URL'); echo '</form>';
    }
    $report = get_option('open_seo_report', []);
    if ($report) {
        echo '<h3>Recent query report</h3><p>' . esc_html($report['property'] . ' · fetched ' . $report['at']) . '</p><table class="widefat striped"><thead><tr><th>Query</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr></thead><tbody>';
        foreach ($report['data']['rows'] ?? [] as $row) {
            echo '<tr><td>' . esc_html($row['keys'][0]) . '</td><td>' . esc_html($row['clicks']) . '</td><td>' . esc_html($row['impressions']) . '</td><td>' . esc_html(round($row['ctr'] * 100, 2)) . '%</td><td>' . esc_html(round($row['position'], 2)) . '</td></tr>';
        }
        echo '</tbody></table><p>Empty results can mean no traffic, privacy filtering or reporting delay.</p>';
    }
    $inspection = get_transient('open_seo_inspection_' . get_current_user_id());
    if ($inspection) { echo '<h3>URL inspection response</h3><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html(wp_json_encode($inspection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>'; }
    echo '</div>';
}

