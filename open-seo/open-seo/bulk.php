<?php
/** Metadata CSV previews, exports and conflict-aware undo. GPL-3.0-or-later, 2026-11-16. */
namespace OpenSEO;
defined('ABSPATH') || exit;
final class Bulk {
    public static function fields() { return ['title' => 'rank_math_title', 'description' => 'rank_math_description', 'focus_keyword' => 'rank_math_focus_keyword']; }
    public static function preview($csv) {
        if (strlen($csv) > 262144) { throw new \RuntimeException('CSV exceeds 256 KB.'); }
        $stream = fopen('php://temp', 'r+'); fwrite($stream, $csv); rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', '');
        if ($header !== ['ID', 'title', 'description', 'focus_keyword']) { fclose($stream); throw new \RuntimeException('CSV header must be ID,title,description,focus_keyword in that order.'); }
        $rows = []; $seen = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($row === [null]) { continue; }
            if (count($row) !== 4 || count($rows) >= 100) { fclose($stream); throw new \RuntimeException('Use at most 100 valid four-column CSV rows per preview.'); }
            $id = absint($row[0]);
            if (!$id || isset($seen[$id]) || !get_post($id) || !current_user_can('edit_post', $id)) { fclose($stream); throw new \RuntimeException('Invalid, duplicate or unauthorized post ID.'); }
            $seen[$id] = true; $before = []; $after = []; $exists = [];
            foreach (array_values(self::fields()) as $index => $key) {
                $before[$key] = get_post_meta($id, $key, true); $exists[$key] = metadata_exists('post', $id, $key);
                $value = $row[$index + 1];
                if (preg_match('/^\x27[=+@-]/', $value)) { $value = substr($value, 1); }
                $after[$key] = sanitize_text_field($value);
            }
            $rows[] = ['id' => $id, 'before' => $before, 'exists' => $exists, 'after' => $after];
        }
        fclose($stream);
        if (!$rows) { throw new \RuntimeException('CSV contains no data rows.'); }
        return $rows;
    }
    public static function apply($rows, $undo = false) {
        // Preflight the entire batch before the first write.
        foreach ($rows as $row) {
            if (!get_post($row['id']) || !current_user_can('edit_post', $row['id'])) { throw new \RuntimeException('Post access changed; preview again.'); }
            foreach (($undo ? $row['after'] : $row['before']) as $key => $expected) {
                if (get_post_meta($row['id'], $key, true) !== $expected) { throw new \RuntimeException('Metadata changed since preview/apply. No changes made; review again.'); }
            }
        }
        foreach ($rows as $row) {
            foreach (($undo ? $row['before'] : $row['after']) as $key => $value) {
                if ($undo && !$row['exists'][$key]) { delete_post_meta($row['id'], $key); }
                else { update_post_meta($row['id'], $key, wp_slash($value)); }
            }
        }
        return count($rows);
    }
    public static function cell($value) { return preg_match('/^[=+@\-\t\r]/', (string) $value) ? "'" . $value : $value; }
    public static function export($page) {
        nocache_headers(); header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="open-seo-metadata-page-' . absint($page) . '.csv"');
        $stream = fopen('php://output', 'w'); fputcsv($stream, ['ID', 'title', 'description', 'focus_keyword'], ',', '"', '');
        foreach (get_posts(['post_type' => ['post', 'page'], 'post_status' => ['publish', 'draft', 'pending', 'private'], 'numberposts' => 100, 'paged' => max(1, $page), 'orderby' => 'ID', 'order' => 'ASC']) as $post) {
            if (!current_user_can('edit_post', $post->ID)) { continue; }
            $row = [$post->ID]; foreach (self::fields() as $key) { $row[] = self::cell(get_post_meta($post->ID, $key, true)); }
            fputcsv($stream, $row, ',', '"', '');
        }
        fclose($stream); exit;
    }
}
