<?php
/** Central website information editor. GPL-3.0-or-later, 2026-11-16. */
namespace OpenSEO;
defined('ABSPATH') || exit;

final class SharedContentAdmin {
    public static function url() { return admin_url('admin.php?page=open-seo-geo-content'); }
    public static function handle() {
        guard();check_admin_referer('open_seo_shared_content');
        $input=[];foreach(array_keys(SharedContent::defaults())as$key){if($key!=='ids')$input[$key]=is_string($_POST[$key]??null)?wp_unslash($_POST[$key]):'';}
        $raw=is_string($_POST['target_ids']??null)?wp_unslash($_POST['target_ids']):'[]';
        try {
            if(strlen($raw)>24000)throw new \RuntimeException('Too many selected pages.');
            $input['ids']=json_decode($raw,true);
            $revision=is_string($_POST['revision']??null)?wp_unslash($_POST['revision']):'';
            SharedContent::save($input,$revision);
            delete_transient('open_seo_shared_draft_'.get_current_user_id());
            Suite::notice('Website information saved. It appears only on selected public pages while enabled. Purge host/CDN page caches if they still show older content.');
        } catch(\Throwable$error) {
            $draft=SharedContent::defaults();foreach(['heading','name','contact','website','overview','facts']as$key)$draft[$key]=sanitize_textarea_field(substr($input[$key]??'',0,12000));
            $draft['ids']=array_slice(array_values(array_filter((array)($input['ids']??[]),'is_numeric')),0,2000);$draft['ids']=array_map('absint',$draft['ids']);$draft['enabled']=!empty($input['enabled']);
            set_transient('open_seo_shared_draft_'.get_current_user_id(),$draft,600);
            Suite::notice($error->getMessage().' Your input is retained below for review; it has not been applied.');
        }
        wp_safe_redirect(self::url());exit;
    }
    public static function field($key,$label,$help,$data,$rows=0) {
        echo'<div class="open-seo-field"><label for="geo-'.$key.'">'.esc_html($label).'</label>';
        if($rows){echo'<textarea id="geo-'.$key.'" name="'.$key.'" rows="'.(int)$rows.'" maxlength="12000" aria-describedby="geo-'.$key.'-help">'.esc_textarea($data[$key]).'</textarea>';}
        else{echo'<input type="'.($key==='website'?'url':'text').'" id="geo-'.$key.'" name="'.$key.'" value="'.esc_attr($data[$key]).'" maxlength="500" aria-describedby="geo-'.$key.'-help">';}
        echo'<p class="description" id="geo-'.$key.'-help">'.esc_html($help).'</p></div>';
    }
    public static function page() {
        guard();$saved=SharedContent::settings();$draft=get_transient('open_seo_shared_draft_'.get_current_user_id());$data=is_array($draft)?wp_parse_args($draft,SharedContent::defaults()):$saved;
        echo'<div class="wrap open-seo-admin open-seo-shared-admin"><h1>Website information for GEO</h1><p class="open-seo-lead">Write your website information once. Display a consistent, visible section on the pages you choose.</p>';
        SuiteAdmin::messages();
        echo'<nav class="open-seo-page-links" aria-label="Open SEO tools"><a href="'.esc_url(HealthAdmin::url()).'">SEO Health & Fixes</a><span aria-hidden="true"> / </span><span aria-current="page">Website information</span></nav>';
        echo'<form id="open-seo-shared-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="open_seo_shared_content"><input type="hidden" name="revision" value="'.esc_attr(SharedContent::revision($saved)).'"><input type="hidden" name="target_ids" id="geo-target-ids" value="'.esc_attr(wp_json_encode($data['ids'])).'">';
        wp_nonce_field('open_seo_shared_content');
        echo'<div class="open-seo-content-grid"><div><section class="open-seo-card"><div class="open-seo-card-heading"><span class="open-seo-step">1</span><h2>Your general website information</h2></div><p>Use concise facts that are relevant to every page you select. Include only information you can verify.</p>';
        self::field('heading','Section heading','For example: About our exhibition or About our company.',$data);
        self::field('name','Website, organization or event name','Use the same official name that appears on your website.',$data);
        self::field('overview','Website overview','Explain what your website or business offers and who it is for. Plain text; separate paragraphs with a blank line.',$data,6);
        self::field('facts','Key facts','One fact per line. Include verified services, locations or event dates where relevant.',$data,5);
        self::field('contact','Contact information','Public contact details only. Leave empty if you do not want them repeated.',$data);
        self::field('website','Website link','Optional complete https:// address.',$data);
        echo'</section><section class="open-seo-card"><div class="open-seo-card-heading"><span class="open-seo-step">2</span><h2>Choose pages</h2></div><p>Only selected, published pages display this section. Your selection stays while you search or switch result pages.</p><noscript><p>Enable JavaScript to change the page selection. Existing saved selections will be retained.</p></noscript><div class="open-seo-picker-search"><label for="geo-page-search">Find a page</label><input type="search" id="geo-page-search" placeholder="Search published page titles" autocomplete="off"></div><p id="geo-picker-status" role="status" aria-live="polite">Loading published pages…</p><div id="geo-picker-results" class="open-seo-picker-results"></div><div class="open-seo-picker-pagination"><button type="button" class="button" id="geo-picker-prev" disabled>Previous</button><span id="geo-picker-page"></span><button type="button" class="button" id="geo-picker-next" disabled>Next</button></div><h3>Selected pages <span id="geo-selected-count">'.count($data['ids']).'</span></h3><ul id="geo-selected-pages" class="open-seo-selected-pages">';
        foreach($data['ids']as$id){$post=get_post($id);echo'<li data-id="'.(int)$id.'"><span>'.esc_html($post?get_the_title($post):'Unavailable page #'.$id).'</span><button type="button" class="button-link" data-remove="'.(int)$id.'" aria-label="'.esc_attr('Remove '.($post?get_the_title($post):'page '.$id)).'">Remove</button></li>';}
        echo'</ul></section><section class="open-seo-card"><div class="open-seo-card-heading"><span class="open-seo-step">3</span><h2>Apply to your selected pages</h2></div><label class="open-seo-enable"><input type="checkbox" name="enabled" id="geo-enabled" value="1" '.checked($data['enabled'],true,false).'> Display the shared section on selected pages</label><p>It appears at the end of the main content. Themes without a main element display it before the end of the page body. Existing page text, Oxygen data and SEO fields stay intact.</p><p>Deselect a page or turn this off to remove the section. Central changes update every selected page after page caches refresh.</p><div class="open-seo-save-bar"><button type="submit" class="button button-primary button-hero">Save website information</button><span id="geo-save-note">'.($data['enabled']?'Enabled for selected pages':'Currently disabled').'</span></div></section></div>';
        echo'<aside class="open-seo-preview-column"><section class="open-seo-card open-seo-preview-card"><h2>Preview shared section</h2><p class="description">Unsaved preview. This section is visible to visitors and search crawlers when enabled on a selected page.</p><div id="geo-content-preview">'.SharedContent::render($data).'</div></section><section class="open-seo-card"><h2>How this helps GEO</h2><p>GEO means AI search readiness. Clear website information helps readers and search systems understand who you are and what you offer.</p><p>Keep page-specific content useful and distinct. Repeating this section does not guarantee rankings or AI citations, and it does not replace accurate content or relevant schema.</p></section></aside></div></form></div>';
        wp_enqueue_script('open-seo-shared-admin',plugins_url('shared-content-admin.js',__FILE__),['wp-api-fetch'],Suite::VERSION,true);
        wp_enqueue_style('open-seo-shared-preview',plugins_url('shared-content.css',__FILE__),[],Suite::VERSION);
    }
}
add_action('admin_menu',function(){add_submenu_page('rank-math','Website information for GEO','GEO Website Info','manage_options','open-seo-geo-content',[SharedContentAdmin::class,'page'],2);},1002);
add_action('admin_post_open_seo_shared_content',[SharedContentAdmin::class,'handle']);
