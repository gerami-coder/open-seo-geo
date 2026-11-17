<?php
/** Local content review and conservative robots controls. GPL-3.0-or-later. */
namespace OpenSEO;
defined('ABSPATH') || exit;

final class GEO {
    public static function review($post) {
        if (!class_exists('DOMDocument')) { return ['sources' => [], 'headings' => [], 'findings' => [['level'=>'warning','code'=>'php-dom','evidence'=>'PHP DOM extension is missing.','action'=>'Ask the host to enable DOM for detailed reviews.']], 'summary'=>'', 'summary_truncated'=>false, 'faq_count'=>0, 'word_count'=>0, 'suggestions'=>[]]; }
        $post = get_post($post); $dom = Links::document($post->post_content); $findings = []; $sources = []; $heading_levels = []; $headings = [];
        $description = get_post_meta($post->ID, 'rank_math_description', true) ?: $post->post_excerpt;
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        foreach ($dom->getElementsByTagName('a') as $a) {
            $url = Links::absolute($a->getAttribute('href'), get_permalink($post));
            if ($url && strtolower((string) wp_parse_url($url, PHP_URL_HOST)) !== $host) { $sources[] = $url; }
        }
        foreach ((new \DOMXPath($dom))->query('//h1|//h2|//h3|//h4|//h5|//h6') as $h) { $heading_levels[] = (int) substr($h->nodeName, 1); $headings[] = trim($h->textContent); }
        $add = function ($level, $code, $evidence, $action) use (&$findings) { $findings[] = compact('level', 'code', 'evidence', 'action'); };
        if (!Suite::eligible($post)) { $add('warning', 'indexability', 'This post is unpublished, protected, excluded by robots settings, or the site discourages indexing.', 'Review visibility before optimizing for discovery.'); }
        $canonical = get_post_meta($post->ID, 'rank_math_canonical_url', true);
        if ($canonical && untrailingslashit($canonical) !== untrailingslashit(get_permalink($post))) { $add('review', 'canonical', $canonical, 'Confirm that this other canonical URL is intentional.'); }
        if (!$sources) { $add('review', 'sources', 'No external source links found in saved HTML.', 'Support factual claims with relevant primary evidence; do not add irrelevant citations.'); }
        foreach ($heading_levels as $i => $level) { if ($i && $level > $heading_levels[$i - 1] + 1) { $add('review', 'heading-order', $headings[$i], 'Use a logical heading hierarchy.'); break; } }
        $author = get_the_author_meta('display_name', $post->post_author); $bio = get_the_author_meta('description', $post->post_author);
        if (!$author || !$bio) { $add('review', 'author', $author ?: 'No author name', 'Add a factual author biography and relevant experience in the user profile.'); }
        $modified = strpos($post->post_modified_gmt, '0000-') === 0 ? false : strtotime($post->post_modified_gmt . ' UTC');
        if ($modified && time() - $modified > 180 * DAY_IN_SECONDS) { $add('review', 'freshness', $post->post_modified_gmt . ' UTC', 'Check time-sensitive facts; change the date only after meaningful review.'); }
        $intro = ''; foreach ($dom->getElementsByTagName('p') as $p) { if (trim($p->textContent)) { $intro = trim($p->textContent); break; } }
        $words = preg_split('/\s+/u', $intro, -1, PREG_SPLIT_NO_EMPTY);
        if (!$intro || count($words) > 100) { $add('review', 'answer-summary', count($words) . ' words in the first paragraph.', 'Start with a concise direct answer where appropriate.'); }
        $meta_count = strlen(trim($description));
        if (!$meta_count) { $add('review', 'description', 'No SEO description or excerpt.', 'Write a factual summary that matches the page.'); }
        $summary = implode(' ', array_slice($words, 0, 60));
        return ['sources' => array_values(array_unique($sources)), 'headings' => $headings, 'findings' => $findings,
            'summary' => $summary, 'summary_truncated' => count($words) > 60, 'faq_count' => count(Schema::faq($post)),
            'word_count' => count(preg_split('/\s+/u', trim(wp_strip_all_tags($post->post_content)), -1, PREG_SPLIT_NO_EMPTY)),
            'suggestions' => Links::suggestions($post->ID)];
    }
    public static function notes($html, $author, $description) {
        $notes = [];
        if (strlen(trim(strip_tags($html))) < 300) { $notes[] = 'Consider whether this page answers its topic fully'; }
        if (!preg_match('/<h[23]\b/i', $html)) { $notes[] = 'Add descriptive section headings where useful'; }
        if (!trim($description)) { $notes[] = 'Write a concise SEO description or excerpt'; }
        if (!trim($author)) { $notes[] = 'Identify a responsible author'; }
        if (!preg_match('/<a\b[^>]*href\s*=\s*[\x22\x27]https?:\/\//i', $html)) { $notes[] = 'Cite primary sources for factual claims where relevant'; }
        if (preg_match_all('/<img\b[^>]*>/i', $html, $images)) {
            foreach ($images[0] as $image) {
                if (!preg_match('/\balt\s*=\s*([\x22\x27]).*?\1/is', $image)) { $notes[] = 'Review images missing alt attributes (decorative images may use empty alt)'; break; }
            }
        }
        return $notes ?: ['Core editorial signals found; review accuracy, sources and clarity manually'];
    }
    public static function audit() {
        $result = ['at' => gmdate('c'), 'skipped' => 0, 'rows' => []];
        $posts = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'numberposts' => 200, 'orderby' => 'modified', 'order' => 'DESC', 'has_password' => false]);
        foreach ($posts as $post) {
            $robots = (array) get_post_meta($post->ID, 'rank_math_robots', true);
            if (!Suite::eligible($post)) { ++$result['skipped']; continue; }
            $result['rows'][] = ['id' => $post->ID, 'title' => $post->post_title, 'notes' => self::notes(
                $post->post_content, get_the_author_meta('display_name', $post->post_author),
                get_post_meta($post->ID, 'rank_math_description', true) ?: $post->post_excerpt
            ), 'review' => self::review($post)];
        }
        return $result;
    }
    public static function robots($output, $public) {
        if (!$public) { return $output; }
        $policy = get_option('open_seo_policy', []);
        foreach (Suite::bots() as $bot) {
            if (($policy[$bot] ?? '') === 'block') {
                $output = self::remove_bot_groups($output, $bot);
                $output .= "\n# Open SEO preference\nUser-agent: " . $bot . "\nDisallow: /\n";
            }
        }
        return $output;
    }
    public static function remove_bot_groups($text, $bot) {
        // Replace this bot's existing groups so an Allow: / cannot defeat its block.
        // Shared groups retain their other agents and all their directives.
        $groups = []; $current = []; $has_agent = false; $has_rule = false;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $is_agent = preg_match('/^\s*user-agent\s*:\s*([^#\s]+)/i', $line);
            if ($is_agent && $has_agent && $has_rule) {
                $groups[] = $current; $current = []; $has_agent = false; $has_rule = false;
            }
            $current[] = $line;
            if ($is_agent) { $has_agent = true; }
            elseif ($has_agent && preg_match('/^\s*[a-z-]+\s*:/i', $line)) { $has_rule = true; }
        }
        $groups[] = $current; $result = [];
        foreach ($groups as $group) {
            $matched = false; $other = false; $filtered = [];
            foreach ($group as $line) {
                if (preg_match('/^\s*user-agent\s*:\s*([^#\s]+)/i', $line, $match)) {
                    if (strcasecmp($match[1], $bot) === 0) { $matched = true; continue; }
                    $other = true;
                }
                $filtered[] = $line;
            }
            if (!$matched || $other) { $result = array_merge($result, $filtered); }
            elseif ($matched) {
                // Sitemap is a global record even if written beneath a named group.
                foreach ($filtered as $line) { if (preg_match('/^\s*sitemap\s*:/i', $line)) { $result[] = $line; } }
            }
        }
        return implode("\n", $result);
    }
}
add_filter('robots_txt', [GEO::class, 'robots'], PHP_INT_MAX, 2);

add_action('add_meta_boxes', function () {
    foreach (['post', 'page'] as $type) {
        add_meta_box('open-seo-geo', 'Open SEO · AI search readiness', function ($post) {
            if (!current_user_can('edit_post', $post->ID)) { return; }
            echo '<p>GEO means AI search readiness: readable pages, clear answers and consistent facts. Use the Check & fix box above for published-page checks.</p><details><summary>Advanced saved-content review</summary><p>Review prompts for the last saved content:</p><ul>';
            foreach (GEO::notes($post->post_content, get_the_author_meta('display_name', $post->post_author), get_post_meta($post->ID, 'rank_math_description', true) ?: $post->post_excerpt) as $note) {
                echo '<li>' . esc_html($note) . '</li>';
            }
            echo '</ul><p>Validate sources, facts and usefulness manually. No AI citation score is implied.</p>';
            $review = GEO::review($post);
            echo '<p>' . esc_html($review['word_count']) . ' words · ' . count($review['sources']) . ' external source URLs · ' . esc_html($review['faq_count']) . ' visible FAQ entries.</p>';
            foreach ($review['findings'] as $finding) { echo '<p><strong>' . esc_html($finding['code']) . ':</strong> ' . esc_html($finding['evidence'] . ' ' . $finding['action']) . '</p>'; }
            if ($review['summary']) { echo '<p><strong>Extracted opening (review before reuse):</strong> ' . esc_html($review['summary'] . ($review['summary_truncated'] ? '…' : '')) . '</p>'; }
            if ($review['suggestions']) { echo '<p>Related content candidates (title/excerpt overlap in the latest 200 posts):</p><ul>'; foreach ($review['suggestions'] as $suggestion) { echo '<li><a href="' . esc_url($suggestion['url']) . '">' . esc_html($suggestion['title']) . '</a></li>'; } echo '</ul>'; }
            echo '</details>';
        }, $type, 'normal', 'default');
    }
});
add_filter('rank_math/llms_txt/posts', function ($posts) { return array_values(array_filter($posts, [Suite::class, 'eligible'])); });
add_action('rank_math/llms_txt/before_output', function () { if (!get_option('blog_public')) { status_header(404); exit; } });
add_shortcode('open_seo_summary', function ($attr, $content = '') { return '<aside class="open-seo-summary" aria-label="Answer summary">' . wpautop(esc_html(wp_strip_all_tags($content))) . '</aside>'; });
