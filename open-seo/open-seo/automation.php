<?php
/** Conservative content-derived metadata automation. GPL-3.0-or-later, 2026-11-16. */
namespace OpenSEO;
defined('ABSPATH') || exit;
final class Automation {
    public static function enabled() { return (bool) get_option('open_seo_automation', 1); }
    public static function initialize() {
        // One-time install/upgrade migration. Later explicit opt-outs remain respected.
        if (add_option('open_seo_auto_defaults_version', '1.1.1', '', false)) {
            update_option('open_seo_automation', 1, false);
            update_option('open_seo_auto_cursor', 0, false);
            update_option('open_seo_auto_complete', 0, false);
            delete_option('open_seo_auto_status');
        }
        if (self::enabled() && !get_option('open_seo_auto_complete') && !wp_next_scheduled('open_seo_auto_batch')) {
            wp_schedule_single_event(time() + 10, 'open_seo_auto_batch');
        }
    }
    public static function fill_blank($id, $key, $value) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id", $id, $key), ARRAY_A);
        foreach ($rows as $row) {
            $existing = maybe_unserialize($row['meta_value']);
            if (!is_string($existing) || trim($existing) !== '') { return false; }
        }
        if (!$rows) {
            $meta_id = add_post_meta($id, $key, wp_slash($value), true);
            if (!$meta_id) { return false; }
            // A manual insert may have raced the unique metadata check. Keep it,
            // and remove only our own generated row if its value is unchanged.
            $others = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s AND meta_id<>%d", $id, $key, $meta_id));
            foreach ($others as $other) {
                if (trim((string) $other) !== '' && $other !== $value) {
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_id=%d AND meta_value=%s", $meta_id, $value));
                    wp_cache_delete($id, 'post_meta');
                    return false;
                }
            }
            return true;
        }
        $changed = false;
        foreach ($rows as $row) {
            // Compare-and-set prevents a simultaneous author edit being replaced.
            $updated = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value=%s WHERE meta_id=%d AND meta_value=%s", $value, $row['meta_id'], $row['meta_value']));
            if ($updated) {
                $changed = true;
                wp_cache_delete($id, 'post_meta');
                do_action('updated_post_meta', (int) $row['meta_id'], $id, $key, $value);
            }
        }
        return $changed;
    }
    public static function clean($html) {
        $html = preg_replace('#<(script|style|nav)\b[^>]*>[\s\S]*?</\1>#i', '', $html);
        $html = preg_replace('#</(?:p|h[1-6]|div|li|section|article)>|<br\s*/?>#i', ' ', $html);
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags(strip_shortcodes($html)), ENT_QUOTES, 'UTF-8')));
    }
    public static function keywords($title, $html) {
        $headings = ''; $dom = Links::document($html);
        foreach ((new \DOMXPath($dom))->query('//h1|//h2|//h3') as $h) { $headings .= ' ' . $h->textContent; }
        $stop = array_flip(['the','and','for','with','this','that','your','from','have','into','about','their','there','will','what','when','where','which','how','are','was','were','our','you','can','not','but','all','also','has','its','more','than','then','they','these','those','been','being','use','using','is','of','to','in','on','at','an','a','as','by','or','it','be','we','us','my','do','if','so','no']);
        $scores = [];
        foreach ([[$title, 12], [$headings, 5], [substr(self::clean($html), 0, 30000), 1]] as $source) {
            $lower = function_exists('mb_strtolower') ? mb_strtolower($source[0], 'UTF-8') : strtolower($source[0]);
            $tokens = preg_split('/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY); $seen = [];
            for ($i = 0; $i < count($tokens); ++$i) {
                for ($length = 1; $length <= 3 && $i + $length <= count($tokens); ++$length) {
                    $phrase = array_slice($tokens, $i, $length);
                    if (isset($stop[$phrase[0]]) || isset($stop[$phrase[$length - 1]]) || preg_match('/^\p{N}+$/u', $phrase[0])) { continue; }
                    if ($length === 1 && strlen($phrase[0]) < 4) { continue; }
                    $key = implode(' ', $phrase); $seen[$key] = ($seen[$key] ?? 0) + 1;
                }
            }
            foreach ($seen as $key => $count) { $scores[$key] = ($scores[$key] ?? 0) + min($count, 5) * $source[1] * (substr_count($key, ' ') + 1); }
        }
        arsort($scores); $selected = [];
        foreach ($scores as $phrase => $score) {
            $redundant = false; foreach ($selected as $existing) { if (strpos(' ' . $existing . ' ', ' ' . $phrase . ' ') !== false || strpos(' ' . $phrase . ' ', ' ' . $existing . ' ') !== false) { $redundant = true; break; } }
            if (!$redundant && strlen($phrase) <= 100) { $selected[] = $phrase; }
            if (count($selected) === 5) { break; }
        }
        return $selected;
    }
    public static function suggestions($post) {
        $source = $post->post_excerpt;
        if (!$source) { foreach (Links::document($post->post_content)->getElementsByTagName('p') as $paragraph) { if (trim($paragraph->textContent)) { $source .= ' ' . $paragraph->textContent; if (strlen($source) >= 180) { break; } } } }
        $text = self::clean($source ?: $post->post_content);
        $description = wp_html_excerpt($text, 160, '');
        if (strlen($text) > strlen($description) && strpos($description, ' ') !== false) { $description = preg_replace('/\s+\S*$/u', '', $description); }
        return ['rank_math_title' => sanitize_text_field($post->post_title), 'rank_math_description' => $description, 'rank_math_focus_keyword' => implode(', ', self::keywords($post->post_title, $post->post_content))];
    }
    public static function fill($id, $force = false) {
        if (!class_exists('DOMDocument')) { return 0; }
        $post = get_post($id);
        if (!$post || !in_array($post->post_type, ['post', 'page'], true) || wp_is_post_revision($id) || wp_is_post_autosave($id) || $post->post_status === 'auto-draft' || get_post_meta($id, '_open_seo_auto_skip', true)) { return 0; }
        if (!$force && !self::enabled()) { return 0; }
        $source_post = clone $post;
        $source_post->post_content = apply_filters('open_seo/automation_source', $post->post_content, $post);
        $changes = []; $values = self::suggestions($source_post);
        foreach ($values as $key => $value) {
            if ($value && self::fill_blank($id, $key, $value)) { $changes[$key] = $value; }
        }
        if ($changes) { update_post_meta($id, '_open_seo_auto_log', ['at' => gmdate('c'), 'values' => $changes]); }
        return count($changes);
    }
    public static function batch($reset = false, $scheduled = false) {
        global $wpdb; if ($reset) { update_option('open_seo_auto_cursor', 0, false); update_option('open_seo_auto_complete', 0, false); }
        $cursor = (int) get_option('open_seo_auto_cursor', 0); $changed = 0;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND post_type IN ('post','page') AND post_status IN ('publish','draft','pending','private','future') ORDER BY ID LIMIT 50", $cursor));
        foreach ($ids as $id) { if ($scheduled || current_user_can('edit_post', $id)) { $changed += self::fill((int) $id, true); } $cursor = (int) $id; }
        update_option('open_seo_auto_cursor', $cursor, false);
        update_option('open_seo_auto_complete', count($ids) < 50 ? 1 : 0, false);
        return ['scanned' => count($ids), 'filled' => $changed, 'complete' => count($ids) < 50];
    }
    public static function run() {
        if (!self::enabled()) { return; }
        if (!class_exists('DOMDocument')) {
            wp_schedule_single_event(time() + HOUR_IN_SECONDS, 'open_seo_auto_batch');
            return;
        }
        $result = self::batch(false, true);
        update_option('open_seo_auto_status', ['at' => gmdate('c'), 'result' => $result], false);
        if (!$result['complete']) { wp_schedule_single_event(time() + 30, 'open_seo_auto_batch'); }
    }
}
add_action('init', [Automation::class, 'initialize'], 20);
register_activation_hook(dirname(__DIR__) . '/rank-math.php', [Automation::class, 'initialize']);
add_action('save_post', [Automation::class, 'fill'], 99, 1);
foreach (['post', 'page'] as $type) { add_action('rest_after_insert_' . $type, function ($post) { Automation::fill($post->ID); }, 99, 1); }
// Public extension point; no PRO flags or service access is changed.
add_filter('rank_math/focus_keyword/maxtags', function () { return 100; });
add_action('open_seo_auto_batch', [Automation::class, 'run']);
