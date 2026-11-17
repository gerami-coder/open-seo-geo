<?php
/** Reusable schema, visible FAQs and locations. GPL-3.0-or-later, 2026-11-16. */
namespace OpenSEO;
defined('ABSPATH') || exit;

final class Schema {
    public static function validate($data) {
        if (empty($data['@type']) || !is_string($data['@type']) || !preg_match('/^[A-Za-z][A-Za-z0-9]+$/', $data['@type'])) { throw new \RuntimeException('Schema needs a schema.org @type.'); }
        unset($data['@context']);
        return $data;
    }
    public static function substitute($value, $tokens) {
        if (is_array($value)) { foreach ($value as &$part) { $part = self::substitute($part, $tokens); } unset($part); return $value; }
        return is_string($value) ? strtr($value, $tokens) : $value;
    }
    public static function faq($post) {
        $questions = [];
        if (preg_match_all('/' . get_shortcode_regex(['open_seo_faq']) . '/s', $post->post_content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if ($match[1] === '[' && $match[6] === ']') { continue; }
                $attr = shortcode_parse_atts($match[3]);
                $question = sanitize_text_field($attr['question'] ?? ''); $answer = wp_strip_all_tags($match[5]);
                if ($question && $answer) { $questions[] = ['@type' => 'Question', 'name' => $question, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer]]; }
            }
        }
        return $questions;
    }
    public static function graph($graph) {
        if (!is_singular()) { return $graph; }
        $post = get_post(); if (!Suite::eligible($post)) { return $graph; }
        $url = get_permalink($post); $templates = (array) get_option('open_seo_templates', []);
        $key = get_post_meta($post->ID, '_open_seo_schema_template', true);
        if ($key && isset($templates[$key])) {
            $data = self::substitute($templates[$key], ['{{title}}' => $post->post_title, '{{url}}' => $url,
                '{{description}}' => wp_strip_all_tags(get_post_meta($post->ID, 'rank_math_description', true) ?: $post->post_excerpt),
                '{{author}}' => get_the_author_meta('display_name', $post->post_author), '{{published}}' => get_post_time(DATE_W3C, true, $post), '{{modified}}' => get_post_modified_time(DATE_W3C, true, $post)]);
            // A selected primary template replaces the inherited richSnippet node.
            if (!in_array($data['@type'], ['FAQPage', 'LocalBusiness', 'VideoObject', 'PodcastEpisode'], true)) { unset($graph['richSnippet']); }
            $data['@id'] = $url . '#open-seo-template'; $graph['open_seo_template'] = $data;
        }
        $faq = self::faq($post);
        if ($faq) {
            foreach ($graph as $id => $node) { if (($node['@type'] ?? '') === 'FAQPage') { unset($graph[$id]); } }
            $graph['open_seo_faq'] = ['@type' => 'FAQPage', '@id' => $url . '#faq', 'mainEntity' => $faq];
        }
        if ($v = Media::video($post)) {
            $graph['open_seo_video'] = ['@type' => 'VideoObject', '@id' => $url . '#video', 'name' => $v['name'], 'description' => $v['description'], 'thumbnailUrl' => $v['thumbnail'], 'uploadDate' => $v['date'], ($v['kind'] === 'player' ? 'embedUrl' : 'contentUrl') => $v['url']];
        }
        if ($audio = Media::audio($post)) { $graph['open_seo_podcast'] = ['@type' => 'PodcastEpisode', '@id' => $url . '#episode', 'name' => $post->post_title, 'url' => $url, 'datePublished' => get_post_time(DATE_W3C, true, $post), 'associatedMedia' => ['@type' => 'AudioObject', 'contentUrl' => $audio['url'], 'encodingFormat' => $audio['type']]]; }
        foreach ((array) get_option('open_seo_locations', []) as $id => $location) {
            if ((int) ($location['page_id'] ?? 0) !== $post->ID) { continue; }
            unset($location['page_id']);
            $location['@type'] = 'LocalBusiness'; $location['@id'] = $url . '#location-' . sanitize_key($id); $location['url'] = $url;
            $graph['open_seo_location_' . $id] = $location;
        }
        return $graph;
    }
    public static function locations($data) {
        foreach ($data as $id => &$location) {
            if (!is_array($location) || !sanitize_key((string) $id) || empty($location['name']) || empty($location['address']['streetAddress']) || empty($location['page_id'])) { throw new \RuntimeException('Each location needs a key, name, streetAddress and dedicated page_id.'); }
            $post = get_post(absint($location['page_id']));
            if (!$post || !Suite::eligible($post)) { throw new \RuntimeException('Location pages must be public, published and indexable.'); }
            $location['name'] = sanitize_text_field($location['name']);
            $location['page_id'] = $post->ID; $location['address']['@type'] = 'PostalAddress';
            $location = array_intersect_key($location, array_flip(['page_id', 'name', 'address', 'telephone', 'image', 'geo', 'openingHoursSpecification', 'sameAs', 'priceRange']));
        }
        return $data;
    }
}
add_filter('rank_math/json_ld', [Schema::class, 'graph'], 98);
add_shortcode('open_seo_faq', function ($attr, $content = '') {
    $attr = shortcode_atts(['question' => ''], $attr);
    if (!trim($attr['question']) || !trim($content)) { return ''; }
    return '<section class="open-seo-faq"><h3>' . esc_html($attr['question']) . '</h3><div>' . wpautop(esc_html(wp_strip_all_tags($content))) . '</div></section>';
});
