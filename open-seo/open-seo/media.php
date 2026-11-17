<?php
/** News/video sitemaps and podcast RSS, GPL-3.0-or-later. Added 2026-11-16. */
namespace OpenSEO;
defined('ABSPATH') || exit;

final class Media {
    public static function invalidate() { if (class_exists('RankMath\\Sitemap\\Cache')) { \RankMath\Sitemap\Cache::invalidate_storage(); } }
    public static function options() { return (array) get_option('open_seo_media', []); }
    public static function endpoint($kind, $page = 1) { return add_query_arg(['open_seo_feed' => $kind, 'open_seo_page' => $page], home_url('/')); }
    public static function video($post) {
        $v = get_post_meta($post->ID, '_open_seo_video', true);
        if (!is_array($v) || empty($v['url']) || empty($v['thumbnail']) || empty($v['description']) || empty($v['name'])) { return null; }
        return $v;
    }
    public static function audio($post) {
        $id = (int) get_post_meta($post->ID, '_open_seo_audio', true);
        if (!$id || strpos((string) get_post_mime_type($id), 'audio/') !== 0) { return null; }
        $file = get_attached_file($id);
        if (!$file || !is_file($file)) { return null; }
        return ['url' => wp_get_attachment_url($id), 'length' => filesize($file), 'type' => get_post_mime_type($id)];
    }
    public static function posts($kind, $page = 1) {
        $args = ['post_type' => ['post', 'page'], 'post_status' => 'publish', 'posts_per_page' => $kind === 'podcast' ? 100 : 1000,
            'paged' => max(1, $page), 'has_password' => false, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true];
        if ($kind === 'news') {
            $args['meta_key'] = '_open_seo_news'; $args['meta_value'] = '1';
            $args['date_query'] = [['after' => gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS), 'column' => 'post_date_gmt', 'inclusive' => true]];
        } else { $args['meta_key'] = $kind === 'video' ? '_open_seo_video' : '_open_seo_audio'; }
        return array_values(array_filter(get_posts($args), [Suite::class, 'eligible']));
    }
    public static function render($kind, $page = 1) {
        $opt = self::options(); $x = [Suite::class, 'xml'];
        if ($kind === 'index') { return '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . self::index_entries() . '</sitemapindex>'; }
        $posts = self::posts($kind, $page);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        if ($kind === 'podcast') {
            $title = $opt['publication'] ?? get_bloginfo('name'); $description = $opt['description'] ?? get_bloginfo('description');
            $xml .= '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:atom="http://www.w3.org/2005/Atom"><channel><title>' . $x($title) . '</title><link>' . $x(home_url('/')) . '</link><description>' . $x($description) . '</description><language>' . $x(get_bloginfo('language')) . '</language><itunes:explicit>false</itunes:explicit><itunes:author>' . $x($opt['author'] ?? $title) . '</itunes:author><atom:link href="' . $x(self::endpoint('podcast')) . '" rel="self" type="application/rss+xml"/>';
            if (!empty($opt['image'])) { $xml .= '<itunes:image href="' . $x($opt['image']) . '"/>'; }
            foreach ($posts as $post) {
                $audio = self::audio($post); if (!$audio) { continue; }
                $xml .= '<item><title>' . $x($post->post_title) . '</title><link>' . $x(get_permalink($post)) . '</link><guid isPermaLink="true">' . $x(get_permalink($post)) . '</guid><pubDate>' . $x(gmdate(DATE_RSS, strtotime($post->post_date_gmt . ' UTC'))) . '</pubDate><description>' . $x(wp_strip_all_tags($post->post_excerpt ?: $post->post_content)) . '</description><enclosure url="' . $x($audio['url']) . '" length="' . (int) $audio['length'] . '" type="' . $x($audio['type']) . '"/></item>';
            }
            return $xml . '</channel></rss>';
        }
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">';
        foreach ($posts as $post) {
            if ($kind === 'news') {
                $lang = $opt['language'] ?? substr(get_locale(), 0, 2);
                $xml .= '<url><loc>' . $x(get_permalink($post)) . '</loc><news:news><news:publication><news:name>' . $x($opt['publication'] ?? get_bloginfo('name')) . '</news:name><news:language>' . $x($lang) . '</news:language></news:publication><news:publication_date>' . $x(get_post_time(DATE_W3C, true, $post)) . '</news:publication_date><news:title>' . $x($post->post_title) . '</news:title></news:news></url>';
            } elseif ($kind === 'video') {
                $v = self::video($post); if (!$v) { continue; }
                $tag = ($v['kind'] ?? 'content') === 'player' ? 'player_loc' : 'content_loc';
                $xml .= '<url><loc>' . $x(get_permalink($post)) . '</loc><video:video><video:thumbnail_loc>' . $x($v['thumbnail']) . '</video:thumbnail_loc><video:title>' . $x($v['name']) . '</video:title><video:description>' . $x($v['description']) . '</video:description><video:' . $tag . '>' . $x($v['url']) . '</video:' . $tag . '><video:publication_date>' . $x($v['date']) . '</video:publication_date></video:video></url>';
            }
        }
        return $xml . '</urlset>';
    }
    public static function route() {
        if (empty($_GET['open_seo_feed'])) { return; }
        $kind = sanitize_key(wp_unslash($_GET['open_seo_feed']));
        $opt = self::options();
        $enabled = $kind === 'index' ? (!empty($opt['news']) || !empty($opt['video'])) : !empty($opt[$kind]);
        if (!in_array($kind, ['index', 'news', 'video', 'podcast'], true) || !$enabled || !get_option('blog_public')) {
            status_header(404); nocache_headers(); exit;
        }
        $page = min(10000, max(1, absint($_GET['open_seo_page'] ?? 1)));
        header('Content-Type: ' . ($kind === 'podcast' ? 'application/rss+xml' : 'application/xml') . '; charset=UTF-8');
        header('X-Robots-Tag: noindex');
        echo self::render($kind, $page); exit;
    }
    public static function index_entries() {
        if (!get_option('blog_public')) { return ''; }
        global $wpdb; $xml = ''; $opt = self::options();
        foreach (['news', 'video'] as $kind) {
            if (empty($opt[$kind])) { continue; }
            $key = $kind === 'news' ? '_open_seo_news' : '_open_seo_video';
            $extra = $kind === 'news' ? $wpdb->prepare(' AND m.meta_value=%s AND p.post_date_gmt>=%s', '1', gmdate('Y-m-d H:i:s', time()-2*DAY_IN_SECONDS)) : '';
            $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type IN ('post','page') AND p.post_status='publish' AND p.post_password='' AND m.meta_key=%s", $key) . $extra);
            for ($page = 1; $page <= min(10000, max(1, (int) ceil($count/1000))); ++$page) { $xml .= '<sitemap><loc>' . Suite::xml(self::endpoint($kind, $page)) . '</loc></sitemap>'; }
        }
        return $xml;
    }
    public static function metabox($post) {
        if (!current_user_can('edit_post', $post->ID)) { return; }
        wp_nonce_field('open_seo_post', 'open_seo_post_nonce');
        echo '<p><label><input type="checkbox" name="open_seo_auto_skip" value="1" ' . checked(get_post_meta($post->ID, '_open_seo_auto_skip', true), '1', false) . '> Exclude this page from automatic SEO metadata</label></p><p>When automation is enabled in Local SEO tools, empty keyword, title and description fields are filled from saved content. Reload the editor after saving to see updated values.</p>';
        $video = get_post_meta($post->ID, '_open_seo_video', true);
        echo '<p><label><input type="checkbox" name="open_seo_news" value="1" ' . checked(get_post_meta($post->ID, '_open_seo_news', true), '1', false) . '> Include this news article in the 48-hour news sitemap</label></p>';
        echo '<p><label for="open-seo-video">Video metadata JSON (name, description, thumbnail, url, kind: content or player, date: ISO 8601)</label></p><textarea class="large-text code" rows="5" id="open-seo-video" name="open_seo_video">' . esc_textarea($video ? wp_json_encode($video, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '') . '</textarea><p>Only describe a video actually visible on this page. A missing required field prevents saving this video configuration.</p>';
        echo '<p><label for="open-seo-audio">Podcast audio attachment ID</label> <input type="number" min="0" id="open-seo-audio" name="open_seo_audio" value="' . absint(get_post_meta($post->ID, '_open_seo_audio', true)) . '"></p>';
        echo '<p><label for="open-seo-schema-template">Reusable schema template</label> <select id="open-seo-schema-template" name="open_seo_schema_template"><option value="">None</option>';
        foreach ((array) get_option('open_seo_templates', []) as $key => $template) { echo '<option value="' . esc_attr($key) . '" ' . selected(get_post_meta($post->ID, '_open_seo_schema_template', true), $key, false) . '>' . esc_html($key) . '</option>'; }
        echo '</select></p><p>Visible FAQ shortcode: <code>[open_seo_faq question="Your question"]Your answer[/open_seo_faq]</code></p>';
    }
    public static function validate_video($value) {
        $v = Suite::json($value);
        foreach (['url', 'thumbnail'] as $key) {
            $v[$key] = esc_url_raw($v[$key] ?? '', ['http', 'https']);
            if (!$v[$key]) { throw new \RuntimeException('Video needs a valid ' . $key . ' URL.'); }
        }
        foreach (['name', 'description'] as $key) {
            $v[$key] = sanitize_text_field($v[$key] ?? '');
            if (!$v[$key]) { throw new \RuntimeException('Video needs a ' . $key . '.'); }
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $v['date'] ?? '') || strtotime($v['date']) === false) { throw new \RuntimeException('Video date must be an ISO 8601 timestamp with timezone.'); }
        if (!checkdate((int) substr($v['date'], 5, 2), (int) substr($v['date'], 8, 2), (int) substr($v['date'], 0, 4)) || (int) substr($v['date'], 11, 2) > 23 || (int) substr($v['date'], 14, 2) > 59 || (int) substr($v['date'], 17, 2) > 59) { throw new \RuntimeException('Video date contains an invalid calendar date or time.'); }
        $v['kind'] = ($v['kind'] ?? '') === 'player' ? 'player' : 'content';
        return array_intersect_key($v, array_flip(['url', 'thumbnail', 'name', 'description', 'date', 'kind']));
    }
    public static function save($id) {
        if (wp_is_post_revision($id) || wp_is_post_autosave($id) || !current_user_can('edit_post', $id) || empty($_POST['open_seo_post_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['open_seo_post_nonce'])), 'open_seo_post')) { return; }
        try {
            $raw = trim(wp_unslash($_POST['open_seo_video'] ?? ''));
            $video = $raw ? self::validate_video($raw) : null;
            $audio = absint($_POST['open_seo_audio'] ?? 0);
            if ($audio && strpos((string) get_post_mime_type($audio), 'audio/') !== 0) { throw new \RuntimeException('Podcast attachment must be an audio file in the media library.'); }
            $template = sanitize_key(wp_unslash($_POST['open_seo_schema_template'] ?? ''));
            if ($template && !isset(((array) get_option('open_seo_templates', []))[$template])) { throw new \RuntimeException('Schema template does not exist.'); }
            update_post_meta($id, '_open_seo_news', isset($_POST['open_seo_news']) ? '1' : '0');
            if ($video) { update_post_meta($id, '_open_seo_video', $video); } else { delete_post_meta($id, '_open_seo_video'); }
            if ($audio) { update_post_meta($id, '_open_seo_audio', $audio); } else { delete_post_meta($id, '_open_seo_audio'); }
            update_post_meta($id, '_open_seo_schema_template', $template);
            update_post_meta($id, '_open_seo_auto_skip', isset($_POST['open_seo_auto_skip']) ? '1' : '');
        } catch (\Throwable $e) { Suite::notice($e->getMessage() . ' Previous media/schema settings were retained.'); }
    }
}
add_action('template_redirect', [Media::class, 'route'], 0);
add_action('save_post', [Media::class, 'save'], 20);
add_action('add_meta_boxes', function () { foreach (['post', 'page'] as $type) { add_meta_box('open-seo-media', 'Open SEO · Media & schema', [Media::class, 'metabox'], $type, 'normal'); } });
add_filter('robots_txt', function ($output, $public) {
    if (!$public) { return $output; }
    if (!empty(Media::options()['news']) || !empty(Media::options()['video'])) { $output .= "\nSitemap: " . Media::endpoint('index') . "\n"; }
    return $output;
}, 30, 2);
add_filter('rank_math/sitemap/index', function ($xml) { return $xml . Media::index_entries(); });
foreach (['added_option','updated_option','deleted_option'] as $event) { add_action($event, function ($option) { if ($option === 'open_seo_media') { Media::invalidate(); } }, 10, 1); }
foreach (['added_post_meta','updated_post_meta','deleted_post_meta'] as $event) { add_action($event, function ($meta_id, $id, $key) { if (in_array($key, ['_open_seo_video','_open_seo_news'], true)) { Media::invalidate(); } }, 10, 3); }
add_action('init', function () { if (get_option('open_seo_media_cache_version') !== Suite::VERSION) { Media::invalidate(); update_option('open_seo_media_cache_version', Suite::VERSION, false); } }, 99);
