<?php
/** Published-page SEO diagnosis and conservative repairs. GPL-3.0-or-later, 2026-11-16. */
namespace OpenSEO;
defined('ABSPATH') || exit;

final class Health {
    const REPORT = '_open_seo_health_report';
    const SOURCE = '_open_seo_health_source';
    public static function types() { return array_values(array_diff(get_post_types(['public'=>true]), ['attachment'])); }
    public static function fingerprint($post) { $builder=[];foreach(get_post_meta($post->ID)as$key=>$value){if(preg_match('/^(?:ct_builder_|_oxygen|_breakdance_|_elementor_)/',$key)){$builder[$key]=$value;}}ksort($builder);$builder=apply_filters('open_seo/health_fingerprint_data',$builder,$post);return hash('sha256', $post->post_content . '|' . $post->post_modified_gmt . '|' . wp_json_encode($builder)); }
    public static function builder($post) {
        if (preg_match('/oxygen-vsb\/|\[ct_|\[oxygen|breakdance/i', $post->post_content) || get_post_meta($post->ID, 'ct_builder_shortcodes', true) || get_post_meta($post->ID, '_oxygen_vsb_enabled', true) || get_post_meta($post->ID, '_breakdance_data', true)) { return 'Oxygen / visual builder'; }
        if (get_post_meta($post->ID, '_elementor_edit_mode', true)) { return 'Elementor'; }
        return '';
    }
    public static function allowed($post) {
        return $post && $post->post_status==='publish' && !$post->post_password && in_array($post->post_type, self::types(), true);
    }
    public static function request($url) {
        $home=wp_parse_url(home_url('/'));$target=wp_parse_url($url);
        if (!$home || !$target || !in_array($target['scheme']??'', ['http','https'],true) || !empty($target['user']) || !empty($target['pass']) || strtolower($home['host'])!==strtolower($target['host']??'') || ($home['port']??null)!==($target['port']??null)) { return ['status'=>0,'error'=>'Only URLs on this website can be scanned.']; }
        $response=wp_safe_remote_get($url,['timeout'=>8,'redirection'=>0,'limit_response_size'=>2097152,'headers'=>['Cache-Control'=>'no-cache'],'user-agent'=>'OpenSEO/1.2 site-owner-audit']);
        if(is_wp_error($response)){return ['status'=>0,'error'=>'The server could not fetch its own public page. Check loopback requests, authentication or firewall settings.'];}
        $body=wp_remote_retrieve_body($response);
        return ['status'=>(int)wp_remote_retrieve_response_code($response),'body'=>$body,'type'=>(string)wp_remote_retrieve_header($response,'content-type'),'robots'=>(string)wp_remote_retrieve_header($response,'x-robots-tag'),'error'=>strlen($body)>=2097152?'Response exceeds the 2 MB scan limit.':''];
    }
    public static function text($node) {
        if($node->nodeType===XML_TEXT_NODE){return $node->nodeValue;}
        $text='';foreach($node->childNodes as$child){$text.=self::text($child);}
        if(in_array(strtolower($node->nodeName),['p','div','section','article','li','br','h1','h2','h3','h4','h5','h6','td','tr'],true)){$text.=' ';}
        return $text;
    }
    public static function words($text) { return preg_match_all('/[\p{L}\p{N}]+(?:[\x27’-][\p{L}\p{N}]+)*/u',$text,$unused); }
    public static function occurrences($text,$keyword) {
        if(!trim($keyword)){return 0;}
        $pattern=str_replace(' ', '\\s+', preg_quote(trim(preg_replace('/\s+/u',' ',$keyword)),'~'));
        return preg_match_all('~(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])~iu',$text,$unused);
    }
    public static function snapshot($html,$url) {
        $dom=Links::document($html);$xpath=new \DOMXPath($dom);
        $get=function($query,$attr=null)use($xpath){$node=$xpath->query($query)->item(0);return $node?trim($attr?$node->getAttribute($attr):$node->textContent):'';};
        $descriptionQuery='//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]';
        $canonicalQuery='//link[contains(concat(" ",translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")," ")," canonical ")]';
        $schema=0;$invalidSchema=0;
        foreach($xpath->query('//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]')as$script){$schema++;json_decode($script->textContent,true);if(json_last_error()!==JSON_ERROR_NONE){$invalidSchema++;}}
        $metadata=['title'=>$get('//title'),'title_count'=>$xpath->query('//title')->length,'description'=>$get($descriptionQuery,'content'),'description_count'=>$xpath->query($descriptionQuery)->length,'canonical'=>$get($canonicalQuery,'href'),'canonical_count'=>$xpath->query($canonicalQuery)->length,'robots'=>$get('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots"]','content'),'schema'=>$schema,'invalid_schema'=>$invalidSchema];
        foreach($xpath->query('//script|//style|//noscript|//template|//header[not(ancestor::main) and not(ancestor::article)]|//footer[not(ancestor::main) and not(ancestor::article)]|//nav|//aside|//*[@hidden]|//*[@aria-hidden="true"]|//*[@role="navigation"]')as$node){if($node->parentNode){$node->parentNode->removeChild($node);}}
        $root=$xpath->query('//main|//*[@role="main"]')->item(0)?:($xpath->query('//body')->item(0)?:$dom->documentElement);
        $main=new \DOMXPath($dom);$headings=[];$paragraphs=[];$images=[];$links=[];
        foreach($main->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6',$root)as$node){$headings[]=['level'=>(int)substr($node->nodeName,1),'text'=>trim($node->textContent)];}
        foreach($main->query('.//p',$root)as$node){$text=trim(preg_replace('/\s+/u',' ',self::text($node)));if($text){$paragraphs[]=$text;}}
        foreach($main->query('.//img',$root)as$img){$id=0;if(preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/',$img->getAttribute('class'),$m)){$id=(int)$m[1];}$images[]=['url'=>Links::absolute($img->getAttribute('src'),$url),'has_alt'=>$img->hasAttribute('alt'),'alt'=>$img->getAttribute('alt'),'id'=>$id];}
        foreach($main->query('.//a[@href]',$root)as$link){$href=Links::absolute($link->getAttribute('href'),$url);if($href){$links[]=['url'=>$href,'text'=>trim($link->textContent)];}}
        $htmlMain='';foreach($root->childNodes as$child){$htmlMain.=$dom->saveHTML($child);}
        $text=trim(preg_replace('/\s+/u',' ',self::text($root)));
        return ['meta'=>$metadata,'text'=>$text,'words'=>self::words($text),'intro'=>$paragraphs[0]??wp_trim_words($text,100,''),'paragraphs'=>$paragraphs,'headings'=>$headings,'images'=>$images,'links'=>$links,'main_html'=>wp_kses_post($htmlMain)];
    }
    public static function issue($code,$state,$title,$detail,$action='review') { return compact('code','state','title','detail','action'); }
    public static function analyze($post,$response) {
        $url=get_permalink($post);$checks=[];$snapshot=null;$status=$response['status'];
        $checks[]=self::issue('http',$status===200?'pass':($status?'review':'unknown'),'Public page response',$status?'HTTP '.$status.($status>=300&&$status<400?' — redirect; check its destination.':''):($response['error']??'Request not verified.'),'host');
        if($status===200&&empty($response['error'])&&stripos($response['type']??'','text/html')!==false){$snapshot=self::snapshot($response['body'],$url);}else{$checks[]=self::issue('unverified','unknown','Published content could not be checked',$response['error']?:'An HTML page was not returned. Unverified checks are not counted as passed.','host');}
        $keyword=trim(explode(',',(string)get_post_meta($post->ID,'rank_math_focus_keyword',true))[0]);
        $checks[]=self::issue('keyword',$keyword?'pass':'review','Chosen focus phrase',$keyword?:'No focus phrase is saved. Extract a suggestion from the page content.',$keyword?'review':'safe');
        $robots=strtolower(($snapshot['meta']['robots']??'').' '.($response['robots']??'').' '.implode(' ',(array)get_post_meta($post->ID,'rank_math_robots',true)));
        $excluded=!get_option('blog_public')||preg_match('/(?:^|[\s,;])(?:noindex|none)(?:$|[\s,;])/i',$robots);
        $checks[]=self::issue('indexability',$excluded?'review':($snapshot?'pass':'unknown'),'Search indexing permission',$excluded?'This page or website requests noindex. This can be intentional; it is never removed automatically.':($snapshot?'No noindex directive found in the checked HTML or response headers.':'Live indexing directives are unverified.'),'host');
        if($snapshot){
            $meta=$snapshot['meta'];
            $checks[]=self::issue('readable-content',$snapshot['words']?'pass':'unknown','Readable main content',$snapshot['words']?$snapshot['words'].' content words found after excluding navigation, scripts and site chrome.':'No main text was found in server HTML. JavaScript-rendered content needs a browser check.');
            foreach(['title'=>'SEO title','description'=>'SEO description']as$key=>$name){$count=$meta[$key.'_count'];$empty=!$meta[$key];$field=$key==='title'?'rank_math_title':'rank_math_description';$auto=$empty&&trim((string)get_post_meta($post->ID,$field,true))==='';$detail=$count>1?'More than one '.$name.' was rendered. Check overlapping theme/plugin output.':($empty?'No '.$name.' was found in the published page.':$meta[$key]);$checks[]=self::issue($key,(!$empty&&$count===1)?'pass':'review',$name,$detail,$auto?'safe':'review');}
            $checks[]=self::issue('canonical',($meta['canonical_count']===1&&$meta['canonical'])?'pass':'review','Canonical URL',$meta['canonical_count']===1&&$meta['canonical']?$meta['canonical']:'Expected one canonical URL. Review theme and SEO output.');
            if($meta['canonical']&&untrailingslashit($meta['canonical'])!==untrailingslashit($url)){$checks[]=self::issue('canonical-other','review','Canonical points to a different URL',$meta['canonical'].' — confirm this is intentional.');}
            $h1=array_values(array_filter($snapshot['headings'],function($h){return$h['level']===1;}));
            $checks[]=self::issue('heading',count($h1)===1?'pass':'review','Clear main heading',count($h1).' H1 headings found. Use a clear main topic; multiple H1s are a review prompt, not an automatic ranking failure.');
            $missing=array_values(array_filter($snapshot['images'],function($img){return!$img['has_alt'];}));
            $fixable=0;foreach(array_slice($missing,0,50)as$image){$id=$image['id'];if(!$id&&wp_parse_url($image['url'],PHP_URL_HOST)===wp_parse_url(home_url(),PHP_URL_HOST)){$id=attachment_url_to_postid($image['url']);}if($id&&trim((string)get_post_meta($id,'_wp_attachment_image_alt',true))!==''){$fixable++;}}
            $checks[]=self::issue('image-alt',$missing?'review':'pass','Image descriptions',count($missing).' images are missing an alt attribute. '.$fixable.' can inherit an existing media-library description; other images need a human description. Empty alt attributes are preserved for decorative images.',$fixable?'safe':'review');
            $checks[]=self::issue('schema',$meta['invalid_schema']?'review':($meta['schema']?'pass':'review'),'Structured facts',$meta['invalid_schema']?$meta['invalid_schema'].' JSON-LD blocks contain invalid JSON.':($meta['schema']?$meta['schema'].' JSON-LD blocks parsed. Rich-result eligibility still depends on required fields and content.':'No JSON-LD found. Add a relevant schema template only when supported by real page content.'));
            if($keyword){
                foreach(['title'=>'SEO title','description'=>'SEO description']as$field=>$label){if($meta[$field]!==''){$has=self::occurrences($meta[$field],$keyword)>0;$checks[]=self::issue('keyword-'.$field,$has?'pass':'review','Topic in '.$label,$has?'The chosen phrase appears in the published '.$label.'.':'Review whether “'.$keyword.'” belongs naturally in this '.$label.'. Written values are only replaced through the reviewed-text form.');}}
                $occurrences=self::occurrences($snapshot['text'],$keyword);$density=$snapshot['words']?round(100*$occurrences/$snapshot['words'],2):0;
                $checks[]=self::issue('keyword-content',$occurrences?'pass':($snapshot['words']?'review':'unknown'),'Chosen phrase in published content',$occurrences?'The exact phrase was found in the checked main text.':'The exact phrase was not found in checked main text. Verify that this is the right topic and that the text renders for crawlers.');
                $early=self::occurrences(wp_trim_words($snapshot['text'],max(1,min(200,(int)ceil($snapshot['words']*.1))),''),$keyword)>0;
                $checks[]=self::issue('keyword-opening',$early?'pass':'review','Topic appears near the beginning',$early?'The chosen phrase appears in the opening part of the published content.':'Mention “'.$keyword.'” naturally near the start if it describes this page. This needs a wording review; content is not rewritten automatically.');
                $checks[]=self::issue('keyword-density',!$snapshot['words']?'unknown':($density>2.5?'review':'pass'),'Repeated focus phrase',$occurrences.' matches in '.$snapshot['words'].' content words ('.$density.'%). '.($density>2.5?'The editor’s repetition heuristic flags this. Review repeated passages and boilerplate; do not mechanically swap words to satisfy a percentage.':'This is a local repetition check, not Google’s preferred percentage.'));
            }
        }
        $ai=[];$bio=trim((string)get_the_author_meta('description',$post->post_author));
        $ai[]=self::issue('ai-access',$excluded?'review':($snapshot?'pass':'unknown'),'Can search systems read it?',$excluded?'Review the intentional noindex setting.':($snapshot?'Published HTML was readable. Site-level crawler and firewall controls also matter.':'Live page access needs verification.'));
        $intro=$snapshot['intro']??'';$ai[]=self::issue('ai-answer',$intro&&self::words($intro)<=100?'pass':'review','Is a short opening answer available?',$intro?'Check that this opening answers the topic accurately: '.wp_trim_words($intro,45,'…'):'Add a useful visible opening summary or answer in the page builder.');
        $ai[]=self::issue('ai-author',$bio?'pass':'review','Is responsibility clear?',$bio?'An author biography is available. Ensure relevant contact/organizer information is visible too.':'Check author, organizer or business information and contact details. Do not invent qualifications.');
        $ai[]=self::issue('ai-facts','review','Are important facts consistent?','Keep business/event names, dates, locations and structured data consistent with visible content. JSON-LD alone does not guarantee AI citations.');
        $repeated=[];if($snapshot&&$keyword){foreach($snapshot['paragraphs']as$p){$n=self::occurrences($p,$keyword);if($n>=3){$repeated[]=['count'=>$n,'text'=>wp_trim_words($p,65,'…')];if(count($repeated)>=5)break;}}}
        return ['id'=>$post->ID,'title'=>$post->post_title,'url'=>$url,'at'=>gmdate('c'),'source'=>$snapshot?'Published HTML':'Live content unverified','builder'=>self::builder($post),'editor_score'=>get_post_meta($post->ID,'rank_math_seo_score',true),'keyword'=>$keyword,'checks'=>$checks,'ai'=>$ai,'repeated'=>$repeated,'snapshot'=>$snapshot,'observed_title'=>$snapshot['meta']['title']??'','observed_description'=>$snapshot['meta']['description']??''];
    }
    public static function scan_post($id) {
        $post=get_post($id);if(!self::allowed($post)){return null;}
        $report=self::analyze($post,self::request(get_permalink($post)));
        if($report['snapshot']){
            $source=['fingerprint'=>self::fingerprint($post),'at'=>time(),'html'=>$report['snapshot']['main_html'],'heading'=>$report['snapshot']['headings'][0]['text']??''];
            // Preserve complete tags: cache a text-only fallback if the HTML limit was reached.
            if(strlen($report['snapshot']['main_html'])>30000){$source['html']='<p>'.esc_html(wp_html_excerpt($report['snapshot']['text'],16000,'')).'</p>';}
            update_post_meta($id,self::SOURCE,$source);
        }else{delete_post_meta($id,self::SOURCE);}
        foreach(['title','description']as$field){$value=trim($report['observed_'.$field]);$key='_open_seo_health_'.$field.'_hash';if($value!==''){update_post_meta($id,$key,hash('sha256',function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value)));}else{delete_post_meta($id,$key);}}
        unset($report['snapshot']);update_post_meta($id,self::REPORT,$report);return$report;
    }
    public static function source($html,$post) {
        $cached=get_post_meta($post->ID,self::SOURCE,true);
        if(is_array($cached)&&$cached['fingerprint']===self::fingerprint($post)&&time()-$cached['at']<DAY_IN_SECONDS){return$cached['html'];}
        return$html;
    }
    public static function fix($id) {
        $post=get_post($id);if(!self::allowed($post)||!current_user_can('edit_post',$id)){return 0;}
        $filled=Automation::fill($id,true);
        $report=(array)get_post_meta($id,self::REPORT,true);$canAlt=false;foreach($report['checks']??[]as$check){if($check['code']==='image-alt'&&$check['action']==='safe'){$canAlt=true;}}
        if($canAlt&&!get_post_meta($id,'_open_seo_auto_skip',true)&&!get_post_meta($id,'_open_seo_inherit_alt',true)){update_post_meta($id,'_open_seo_inherit_alt','1');$filled++;}
        return$filled;
    }
    public static function alt($html) {
        $processor=new \WP_HTML_Tag_Processor($html);$lookups=0;
        while($processor->next_tag('IMG')){
            if($processor->get_attribute('alt')!==null){continue;}
            $id=0;$class=(string)$processor->get_attribute('class');if(preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/',$class,$m)){$id=(int)$m[1];}
            if(!$id&&$lookups<50){$src=$processor->get_attribute('src');if(is_string($src)&&wp_parse_url($src,PHP_URL_HOST)===wp_parse_url(home_url(),PHP_URL_HOST)){$id=attachment_url_to_postid($src);$lookups++;}}
            $alt=$id?trim((string)get_post_meta($id,'_wp_attachment_image_alt',true)):'';if($alt!==''){$processor->set_attribute('alt',$alt);}
        }
        return$processor->get_updated_html();
    }
}
add_filter('open_seo/automation_source',[Health::class,'source'],10,2);
add_action('template_redirect',function(){if(is_singular()&&!is_feed()&&!is_admin()&&!wp_is_json_request()&&get_post_meta(get_queried_object_id(),'_open_seo_inherit_alt',true)){ob_start(function($html){return stripos($html,'<html')!==false?Health::alt($html):$html;});}},99);

