<?php
/** Selected-page shared website information. GPL-3.0-or-later, 2026-11-16. */
namespace OpenSEO;
defined('ABSPATH') || exit;

final class SharedContentTags extends \WP_HTML_Tag_Processor {
    public function offset() {
        if(!$this->set_bookmark('open-seo-insert'))return null;
        return isset($this->bookmarks['open-seo-insert'])?$this->bookmarks['open-seo-insert']->start:null;
    }
}
final class SharedContent {
    const OPTION = 'open_seo_shared_content';
    public static function defaults() {
        return ['enabled'=>false,'heading'=>'About this website','name'=>'','overview'=>'','facts'=>'','contact'=>'','website'=>'','ids'=>[]];
    }
    public static function settings() { return wp_parse_args((array)get_option(self::OPTION,[]),self::defaults()); }
    public static function eligible($id) {
        $post=get_post($id);
        return $post && $post->post_type==='page' && $post->post_status==='publish' && !$post->post_password;
    }
    public static function validate($input) {
        $data=self::defaults();
        foreach(['heading','name','contact'] as $key){$data[$key]=sanitize_text_field($input[$key]??'');if(strlen($data[$key])>500)throw new \RuntimeException('Keep headings, names and contact details under 500 characters.');}
        $data['overview']=sanitize_textarea_field($input['overview']??'');
        $data['facts']=sanitize_textarea_field($input['facts']??'');
        if(strlen($data['overview'])>12000||strlen($data['facts'])>12000)throw new \RuntimeException('Keep the overview and facts under 12,000 characters each.');
        $raw=trim((string)($input['website']??''));$data['website']=esc_url_raw($raw,['https','http']);
        if($raw&&(!$data['website']||!wp_http_validate_url($data['website'])))throw new \RuntimeException('Enter a valid public http or https website URL, or leave it empty.');
        $ids=$input['ids']??[];if(!is_array($ids)||count($ids)>2000)throw new \RuntimeException('Select up to 2,000 published pages.');
        foreach($ids as$id){if(!is_int($id)&&!(is_string($id)&&ctype_digit($id)))throw new \RuntimeException('Invalid selected page.');}
        $data['ids']=array_values(array_unique(array_filter(array_map('absint',$ids))));
        foreach($data['ids'] as $id){if(!self::eligible($id)||!current_user_can('edit_post',$id))throw new \RuntimeException('One selected page is no longer public or editable. Reload the page picker and remove it.');}
        $data['enabled']=!empty($input['enabled']);
        if($data['enabled']&&(!$data['heading']||!$data['overview']))throw new \RuntimeException('Add a section heading and overview before enabling the shared section.');
        return $data;
    }
    public static function revision($data) { return hash('sha256',wp_json_encode($data)); }
    public static function save($input,$revision) {
        guard();$current=self::settings();
        if(!hash_equals(self::revision($current),(string)$revision))throw new \RuntimeException('Someone changed the shared information while you were editing. Reload before saving.');
        $data=self::validate($input);update_option(self::OPTION,$data,false);
        foreach(array_unique(array_merge($current['ids'],$data['ids'])) as $id){clean_post_cache($id);delete_post_meta($id,Health::SOURCE);}
        /** Hosts can purge selected page caches here. Existing content is never rewritten. */
        do_action('open_seo/shared_content_saved',$data,$current);
        return $data;
    }
    public static function active($id,$data=null) {
        $data=$data??self::settings();
        return !empty($data['enabled'])&&!empty($data['overview'])&&in_array((int)$id,(array)$data['ids'],true)&&self::eligible($id);
    }
    public static function render($data=null) {
        $data=$data??self::settings();if(!trim($data['overview']))return '';
        $html='<section class="open-seo-shared-info" data-open-seo-geo="shared" aria-labelledby="open-seo-shared-heading"><div class="open-seo-shared-inner"><h2 id="open-seo-shared-heading">'.esc_html($data['heading']).'</h2>';
        if($data['name'])$html.='<p class="open-seo-shared-name"><strong>'.esc_html($data['name']).'</strong></p>';
        $html.=wpautop(esc_html($data['overview']));
        $facts=array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$data['facts'])));
        if($facts){$html.='<ul>';foreach($facts as $fact)$html.='<li>'.esc_html($fact).'</li>';$html.='</ul>';}
        if($data['contact'])$html.='<p><strong>Contact:</strong> '.esc_html($data['contact']).'</p>';
        if($data['website'])$html.='<p><a href="'.esc_url($data['website']).'">'.esc_html($data['website']).'</a></p>';
        return $html.'</div></section>';
    }
    public static function inject($html,$id) {
        if(!self::active($id))return $html;
        $tags=new SharedContentTags($html);$main=null;$body=null;$hasHtml=false;$hasBody=false;$template=0;
        while($tags->next_tag(['tag_closers'=>'visit'])){
            $name=$tags->get_tag();$close=$tags->is_tag_closer();
            if($name==='TEMPLATE'){$template+=($close?-1:1);$template=max(0,$template);continue;}
            if($template)continue;
            if(!$close&&$tags->get_attribute('data-open-seo-geo')==='shared')return $html;
            if(!$close&&$name==='HTML')$hasHtml=true;
            if(!$close&&$name==='BODY')$hasBody=true;
            if($close&&$name==='MAIN'&&$main===null)$main=$tags->offset();
            if($close&&$name==='BODY')$body=$tags->offset();
        }
        if(!$hasHtml||!$hasBody)return $html;
        $block=self::render();
        // Token positions preserve every original byte and ignore script/comment decoys.
        $at=$main!==null?$main:$body;
        return $at===null?$html:substr($html,0,$at).$block.substr($html,$at);
    }
    public static function start() {
        if(!is_singular('page')||is_feed()||is_admin()||wp_is_json_request()||is_preview()||!self::active(get_queried_object_id()))return;
        $id=get_queried_object_id();
        ob_start(function($html)use($id){foreach(headers_list()as$header){if(stripos($header,'Content-Type:')===0&&stripos($header,'text/html')===false)return $html;}return self::inject($html,$id);});
    }
    public static function picker($request) {
        $paged=max(1,absint($request->get_param('page')));
        $search=sanitize_text_field((string)$request->get_param('search'));
        $query=new \WP_Query(['post_type'=>'page','post_status'=>'publish','has_password'=>false,'posts_per_page'=>20,'paged'=>$paged,'s'=>$search,'orderby'=>'title','order'=>'ASC']);
        $items=[];foreach($query->posts as$post){if(current_user_can('edit_post',$post->ID))$items[]=['id'=>$post->ID,'title'=>wp_strip_all_tags(get_the_title($post))?:'(Untitled page)','url'=>get_permalink($post)];}
        return ['items'=>$items,'pages'=>(int)$query->max_num_pages,'total'=>(int)$query->found_posts];
    }
}
add_action('template_redirect',[SharedContent::class,'start'],98);
add_action('wp_enqueue_scripts',function(){if(is_singular('page')&&SharedContent::active(get_queried_object_id()))wp_enqueue_style('open-seo-shared-info',plugins_url('shared-content.css',__FILE__),[],Suite::VERSION);});
add_filter('open_seo/health_fingerprint_data',function($data,$post){$settings=SharedContent::settings();if(SharedContent::active($post->ID,$settings))$data['shared_content']=SharedContent::revision($settings);return $data;},10,2);
add_action('rest_api_init',function(){register_rest_route('open-seo/v1','/geo-content/pages',['methods'=>'GET','permission_callback'=>function(){return current_user_can('manage_options');},'callback'=>[SharedContent::class,'picker']]);});
