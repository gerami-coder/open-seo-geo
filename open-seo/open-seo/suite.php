<?php
/** Independent local SEO suite, GPL-3.0-or-later. Added 2026-11-16. */
namespace OpenSEO;
defined('ABSPATH') || exit;

final class Suite {
    const VERSION = '1.3.0';
    public static function eligible($post) {
        $post = get_post($post);
        if (!$post || !get_option('blog_public') || $post->post_status !== 'publish' || $post->post_password || !is_post_type_viewable($post->post_type)) { return false; }
        if (class_exists('RankMath\\Helper') && !\RankMath\Helper::is_post_indexable($post)) { return false; }
        return !in_array('noindex', (array) get_post_meta($post->ID, 'rank_math_robots', true), true);
    }
    public static function json($text, $limit = 65536) {
        if (strlen($text) > $limit) { throw new \RuntimeException('JSON is too large.'); }
        $data = json_decode($text, true, 32);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) { throw new \RuntimeException('Enter valid JSON containing an object or array.'); }
        return $data;
    }
    public static function notice($message) {
        set_transient('open_seo_suite_notice_' . get_current_user_id(), $message, 120);
    }
    public static function url($path) {
        return admin_url('admin.php?page=open-seo-suite&tab=' . sanitize_key($path));
    }
    public static function xml($value) { return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
    public static function bots() { return ['GPTBot', 'OAI-SearchBot', 'Google-Extended', 'ClaudeBot', 'Claude-SearchBot', 'PerplexityBot']; }
}

require_once __DIR__ . '/media.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/links.php';
require_once __DIR__ . '/history.php';
require_once __DIR__ . '/assistant.php';
require_once __DIR__ . '/bulk.php';
require_once __DIR__ . '/automation.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/health-jobs.php';
require_once __DIR__ . '/health-admin.php';
require_once __DIR__ . '/shared-content.php';
require_once __DIR__ . '/shared-content-admin.php';
require_once __DIR__ . '/suite-admin.php';

add_action('admin_enqueue_scripts',function(){
    $screen=get_current_screen();
    if($screen&&preg_match('/(?:_page_)(?:open-seo-health|open-seo-geo-content|open-seo)$/',$screen->id)){
        wp_enqueue_style('open-seo-admin-ui',plugins_url('admin-ui.css',__FILE__),[],Suite::VERSION);
    }
},99);

add_action('init', [Links::class, 'install'], 5);
add_action('init', [History::class, 'schedule']);
add_action('open_seo_history_daily', [History::class, 'sync']);
add_action('save_post', [Links::class, 'changed'], 30, 1);
add_action('before_delete_post', [Links::class, 'remove']);
add_action('admin_post_open_seo_suite', [SuiteAdmin::class, 'handle']);
add_action('admin_menu', function () {
    add_submenu_page('rank-math', 'Local SEO tools', 'Local SEO tools', 'manage_options', 'open-seo-suite', [SuiteAdmin::class, 'page']);
}, 1001);
foreach (['script_loader_src', 'style_loader_src'] as $hook) {
    add_filter($hook, function ($src) {
        $base = plugins_url('/', dirname(__DIR__) . '/rank-math.php');
        return strpos($src, $base) === 0 ? add_query_arg('open_seo_ver', Suite::VERSION, $src) : $src;
    });
}
add_filter('gettext', function ($translated, $original, $domain) {
    if (in_array($domain, ['seo-by-rank-math', 'rank-math'], true)) { return str_ireplace(['Rank Math SEO','Rank Math'], 'Open SEO', $translated); }
    return $translated;
}, 20, 3);

register_deactivation_hook(dirname(__DIR__) . '/rank-math.php', function () {
    wp_clear_scheduled_hook('open_seo_history_daily');
    wp_clear_scheduled_hook('open_seo_link_batch');
    wp_clear_scheduled_hook('open_seo_auto_batch');
    wp_clear_scheduled_hook('open_seo_health_batch');
});
