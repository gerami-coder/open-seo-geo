<?php
/** Site-owned Search Console history, GPL-3.0-or-later. Added 2026-11-16. */
namespace OpenSEO;
defined('ABSPATH') || exit;
final class History {
    public static function schedule() {
        if (get_option('open_seo_history_enabled') && !wp_next_scheduled('open_seo_history_daily')) { wp_schedule_event(time() + 60, 'daily', 'open_seo_history_daily'); }
        elseif (!get_option('open_seo_history_enabled')) { wp_clear_scheduled_hook('open_seo_history_daily'); }
    }
    public static function sync() {
        try {
            $property = get_option('open_seo_property', '');
            if (!$property || !get_option('open_seo_tokens')) { throw new \RuntimeException('Connect Google and select a property first.'); }
            $date = gmdate('Y-m-d', time() - 3 * DAY_IN_SECONDS);
            $data = GSC::request('https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/searchAnalytics/query', ['startDate' => $date, 'endDate' => $date, 'dimensions' => ['query'], 'rowLimit' => 1000, 'dataState' => 'final']);
            self::store($property, $date, $data['rows'] ?? []);
            update_option('open_seo_history_status', ['at' => gmdate('c'), 'message' => 'Snapshot saved for ' . $date], false);
            return $date;
        } catch (\Throwable $e) {
            update_option('open_seo_history_status', ['at' => gmdate('c'), 'message' => $e->getMessage()], false);
            if (!wp_doing_cron()) { throw $e; } return false;
        }
    }
    public static function store($property, $date, $rows) {
        $key = 'open_seo_day_' . substr(hash('sha256', $property), 0, 16) . '_' . $date;
        update_option($key, ['property' => $property, 'date' => $date, 'rows' => $rows], false);
        $index = (array) get_option('open_seo_history_index', []); $index[$key] = $date;
        asort($index);
        while (count($index) > 365) { $remove = key($index); delete_option($remove); unset($index[$remove]); }
        update_option('open_seo_history_index', $index, false);
    }
    public static function latest($property) {
        $list = []; $index = array_reverse((array) get_option('open_seo_history_index', []), true);
        foreach ($index as $key => $date) { $day = get_option($key); if ($day && $day['property'] === $property) { $list[] = $day; if (count($list) === 2) { break; } } }
        return $list;
    }
    public static function tracked($snapshot) {
        $tracked = (array) get_option('open_seo_keywords', []); $rows = [];
        $lookup = []; foreach ($snapshot['rows'] as $row) { $lookup[$row['keys'][0]] = $row; }
        foreach ($tracked as $keyword) { $rows[] = ['query' => $keyword, 'data' => $lookup[$keyword] ?? null]; }
        return $rows;
    }
}
