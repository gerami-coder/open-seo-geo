<?php
/** Local link index, suggestions and revision-backed link replacement. GPL-3.0-or-later, 2026-11-16. */
namespace OpenSEO;
defined('ABSPATH') || exit;

final class Links {
    public static function table() { global $wpdb; return $wpdb->prefix . 'open_seo_links'; }
    public static function install() {
        if (get_option('open_seo_db_version') === '2') { return; }
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table(); $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned NOT NULL,
            target_id bigint(20) unsigned NOT NULL DEFAULT 0,
            url text NOT NULL,
            anchor varchar(255) NOT NULL DEFAULT '',
            internal tinyint(1) NOT NULL DEFAULT 0,
            checked_at datetime NOT NULL,
            http_status smallint(5) NOT NULL DEFAULT 0,
            last_http_check datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY source_id (source_id),
            KEY target_id (target_id)
        ) $collate;");
        update_option('open_seo_db_version', '2', false);
    }
    public static function document($html) {
        if (!class_exists('DOMDocument')) { throw new \RuntimeException('PHP DOM extension is required.'); }
        $dom = new \DOMDocument(); $before = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($before); return $dom;
    }
    public static function absolute($url, $base) {
        $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
        if (!$url || $url[0] === '#' || preg_match('/^(mailto|tel|javascript|data):/i', $url)) { return ''; }
        if (strpos($url, '//') === 0) { $url = (wp_parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $url; }
        elseif (!preg_match('#^https?://#i', $url)) { $url = \WP_Http::make_absolute_url($url, $base); }
        $url = esc_url_raw(preg_replace('/#.*$/', '', $url), ['http', 'https']);
        return $url;
    }
    public static function remove($id) { global $wpdb; $wpdb->delete(self::table(), ['source_id' => $id], ['%d']); }
    public static function changed($id) {
        if (wp_is_post_revision($id) || wp_is_post_autosave($id)) { return; }
        $post = get_post($id); self::remove($id);
        if (!class_exists('DOMDocument')) { return; }
        if (!$post || !Suite::eligible($post)) { return; }
        global $wpdb; $base = get_permalink($post); $host = strtolower(wp_parse_url(home_url('/'), PHP_URL_HOST));
        foreach (self::document($post->post_content)->getElementsByTagName('a') as $anchor) {
            $url = self::absolute($anchor->getAttribute('href'), $base); if (!$url) { continue; }
            $internal = strtolower((string) wp_parse_url($url, PHP_URL_HOST)) === $host;
            $wpdb->insert(self::table(), ['source_id' => $id, 'target_id' => $internal ? url_to_postid($url) : 0, 'url' => $url,
                'anchor' => wp_html_excerpt(trim($anchor->textContent), 250), 'internal' => $internal ? 1 : 0, 'checked_at' => current_time('mysql', true)]);
        }
        update_post_meta($id, '_open_seo_link_indexed', gmdate('c'));
    }
    public static function batch($reset = false) {
        global $wpdb;
        if ($reset) { update_option('open_seo_link_cursor', 0, false); }
        $cursor = (int) get_option('open_seo_link_cursor', 0);
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ('post','page') ORDER BY ID LIMIT 100", $cursor));
        foreach ($ids as $id) { self::changed((int) $id); $cursor = (int) $id; }
        update_option('open_seo_link_cursor', $cursor, false);
        return ['count' => count($ids), 'cursor' => $cursor, 'complete' => count($ids) < 100];
    }
    public static function suggestions($id) {
        $post = get_post($id); if (!$post) { return []; }
        $words = self::tokens($post->post_title . ' ' . $post->post_excerpt); $results = [];
        foreach (get_posts(['post_type' => ['post', 'page'], 'numberposts' => 200, 'post_status' => 'publish', 'exclude' => [$id]]) as $candidate) {
            if (!Suite::eligible($candidate)) { continue; }
            $overlap = array_intersect($words, self::tokens($candidate->post_title . ' ' . $candidate->post_excerpt));
            if ($overlap) { $results[] = ['id' => $candidate->ID, 'title' => $candidate->post_title, 'url' => get_permalink($candidate), 'matches' => count($overlap)]; }
        }
        usort($results, function ($a, $b) { return $b['matches'] <=> $a['matches']; }); return array_slice($results, 0, 10);
    }
    public static function tokens($text) {
        $words = preg_split('/[^\p{L}\p{N}]+/u', function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter($words, function ($word) { return strlen($word) > 3 && !in_array($word, ['this', 'that', 'with', 'from', 'your', 'have', 'what', 'when', 'will', 'into', 'about', 'their', 'there'], true); })));
    }
    public static function replace($html, $old, $new) {
        // WP_HTML_Tag_Processor changes only href attributes; block markup and formatting survive.
        $processor = new \WP_HTML_Tag_Processor($html); $count = 0;
        while ($processor->next_tag('A')) {
            if ($processor->get_attribute('href') === $old) { $processor->set_attribute('href', $new); ++$count; }
        }
        return ['html' => $processor->get_updated_html(), 'count' => $count];
    }
    public static function preview($old, $new) {
        if (!wp_http_validate_url($new) || !preg_match('#^https?://#i', $old)) { throw new \RuntimeException('Use valid public HTTP(S) source and destination URLs.'); }
        global $wpdb; $rows = [];
        $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT source_id FROM " . self::table() . " WHERE url = %s LIMIT 200", $old));
        foreach ($ids as $id) {
            $post = get_post($id); if (!$post || !current_user_can('edit_post', $id)) { continue; }
            $change = self::replace($post->post_content, $old, $new);
            if ($change['count']) { $rows[] = ['id' => (int) $id, 'hash' => hash('sha256', $post->post_content), 'count' => $change['count']]; }
        }
        return ['old' => $old, 'new' => $new, 'rows' => $rows];
    }
    public static function apply($preview) {
        $count = 0;
        foreach ($preview['rows'] as $row) {
            $post = get_post($row['id']);
            if (!$post || !current_user_can('edit_post', $post->ID) || hash('sha256', $post->post_content) !== $row['hash']) { throw new \RuntimeException('A page changed after preview or access was lost. Preview again. Earlier pages in the batch may already be updated.'); }
            if (!wp_revisions_enabled($post)) { throw new \RuntimeException('Enable WordPress revisions before applying bulk link changes.'); }
            wp_save_post_revision($post->ID);
            $updated = self::replace($post->post_content, $preview['old'], $preview['new']);
            $result = wp_update_post(wp_slash(['ID' => $post->ID, 'post_content' => $updated['html']]), true);
            if (is_wp_error($result)) { throw new \RuntimeException('Could not update page ' . $post->ID); }
            $count += $updated['count'];
        }
        return $count;
    }
    public static function rows() {
        global $wpdb; $table = self::table();
        return $wpdb->get_results("SELECT p.ID,p.post_title,COUNT(DISTINCT i.source_id) AS incoming FROM {$wpdb->posts} p LEFT JOIN $table i ON i.target_id=p.ID AND i.source_id<>p.ID WHERE p.post_status='publish' AND p.post_type IN ('post','page') GROUP BY p.ID,p.post_title ORDER BY incoming ASC,p.ID DESC LIMIT 200", ARRAY_A);
    }
    public static function check_batch() {
        global $wpdb; $table = self::table();
        $urls = $wpdb->get_col("SELECT DISTINCT url FROM $table WHERE last_http_check IS NULL ORDER BY id LIMIT 5");
        foreach ($urls as $url) {
            $status = -1;
            try {
                Assistant::public_url($url);
                $response = wp_safe_remote_head($url, ['timeout' => 5, 'redirection' => 0]);
                if (!is_wp_error($response)) { $status = wp_remote_retrieve_response_code($response); }
            } catch (\Throwable $error) { $status = -2; }
            $wpdb->update($table, ['http_status' => $status, 'last_http_check' => current_time('mysql', true)], ['url' => $url]);
        }
        return count($urls);
    }
    public static function autolink($html) {
        if (!is_singular() || is_feed() || !get_option('open_seo_autolink_enabled')) { return $html; }
        $rules = (array) get_option('open_seo_autolink_rules', []); $used = []; $count = 0; $skip = [];
        $parts = preg_split('/(<(?:[^>\x22\x27]|\x22[^\x22]*\x22|\x27[^\x27]*\x27)+>|&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]+);)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as &$part) {
            if (isset($part[0]) && $part[0] === '<') {
                if (preg_match('/^<\s*(\/?)\s*(a|h[1-6]|script|style|code|pre|button|textarea|select|svg|template)\b/i', $part, $match)) {
                    $tag = strtolower($match[2]);
                    if ($match[1]) { $skip[$tag] = max(0, ($skip[$tag] ?? 0) - 1); }
                    elseif (!preg_match('#/\s*>$#', $part)) { $skip[$tag] = ($skip[$tag] ?? 0) + 1; }
                }
                continue;
            }
            if (array_sum($skip) || $count >= 3 || preg_match('/^&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]+);$/i', $part)) { continue; }
            // A text chunk is split once; never process generated anchors again.
            $keys = array_filter(array_keys($rules), function ($key) use ($used, $rules) { return !isset($used[$key]) && untrailingslashit($rules[$key]) !== untrailingslashit(get_permalink()); });
            if (!$keys) { continue; }
            usort($keys, function ($a, $b) { return strlen($b) <=> strlen($a); });
            $lookup = []; foreach ($keys as $key) { $lookup[function_exists('mb_strtolower') ? mb_strtolower($key, 'UTF-8') : strtolower($key)] = $key; }
            $pattern = '/(?<![\p{L}\p{N}])(' . implode('|', array_map(function ($key) { return preg_quote($key, '/'); }, $keys)) . ')(?![\p{L}\p{N}])/iu';
            $part = preg_replace_callback($pattern, function ($match) use (&$used, &$count, $lookup, $rules) {
                $key = $lookup[function_exists('mb_strtolower') ? mb_strtolower($match[1], 'UTF-8') : strtolower($match[1])] ?? null;
                if ($key === null || isset($used[$key]) || $count >= 3) { return $match[0]; }
                $used[$key] = true; ++$count;
                return '<a href="' . esc_url($rules[$key]) . '" class="open-seo-auto-link">' . $match[0] . '</a>';
            }, $part);
        }
        unset($part); return implode('', $parts);
    }
}
add_filter('the_content', [Links::class, 'autolink'], 25);
