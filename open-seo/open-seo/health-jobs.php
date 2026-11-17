<?php
/** Batched whole-site scans and explicit safe-fix jobs. GPL-3.0-or-later, 2026-11-16. */
namespace OpenSEO;
defined('ABSPATH') || exit;
final class HealthJobs {
    public static function robots_allows($text,$agent,$path='/') {
        $groups=[];$agents=[];$rules=[];$hasRules=false;
        foreach(preg_split('/\r\n|\r|\n/',$text)as$line){$line=trim(preg_replace('/#.*$/','',$line));if(strpos($line,':')===false)continue;[$key,$value]=array_map('trim',explode(':',$line,2));$key=strtolower($key);
            if($key==='user-agent'){if($hasRules){$groups[]=[$agents,$rules];$agents=[];$rules=[];$hasRules=false;}$agents[]=$value;}
            elseif(in_array($key,['allow','disallow'],true)&&$agents){$rules[]=[$key,$value];$hasRules=true;}
        }$groups[]=[$agents,$rules];$best=-1;$selected=[];
        foreach($groups as[$names,$lines]){$score=-1;foreach($names as$name){$score=max($score,$name==='*'?0:(stripos($agent,$name)!==false?strlen($name):-1));}if($score<0||$score<$best)continue;if($score>$best){$selected=[];$best=$score;}$selected=array_merge($selected,$lines);}
        $length=-1;$allowed=true;foreach($selected as[$kind,$pattern]){if($pattern==='')continue;$regex=str_replace(['\\*','\\$'],['.*','$'],preg_quote($pattern,'~'));if(!preg_match('~^'.$regex.'~',$path))continue;$n=strlen(str_replace(['*','$'],'',$pattern));if($n>$length||($n===$length&&$kind==='allow')){$length=$n;$allowed=$kind==='allow';}}
        return$allowed;
    }
    public static function site_checks() {
        $checks=[];$checks[]=Health::issue('site-visibility',get_option('blog_public')?'pass':'review','Website indexing setting',get_option('blog_public')?'WordPress permits search indexing.':'WordPress discourages search engines. Review Settings → Reading if this is unintended.','host');
        $checks[]=Health::issue('https',wp_parse_url(home_url(),PHP_URL_SCHEME)==='https'?'pass':'review','HTTPS website address',home_url('/').' — certificate/mixed-content checks require hosting verification.','host');
        $robots=Health::request(home_url('/robots.txt'));
        if($robots['status']===200&&empty($robots['error'])){
            foreach(['Googlebot'=>'Google search access','OAI-SearchBot'=>'ChatGPT search access','Claude-SearchBot'=>'Claude search access','PerplexityBot'=>'Perplexity search access']as$agent=>$label){$allow=self::robots_allows($robots['body'],$agent);$checks[]=Health::issue('robots-'.strtolower($agent),$allow?'pass':'review',$label,$allow?'The robots.txt rules permit the root path. Other paths and the firewall can differ.':'robots.txt blocks the root path for '.$agent.'. Review only if that is not your choice.','host');}
        }elseif($robots['status']===404){$checks[]=Health::issue('robots','pass','robots.txt','No robots.txt file was found (HTTP 404). This alone does not block crawling.');}
        else{$checks[]=Health::issue('robots','unknown','robots.txt','Root crawl rules could not be verified. Check the host/CDN response.','host');}
        $url=class_exists('RankMath\\Sitemap\\Router')?\RankMath\Sitemap\Router::get_base_url(\RankMath\Sitemap\Sitemap::get_sitemap_index_slug().'.xml'):home_url('/wp-sitemap.xml');
        $response=Health::request($url);$valid=false;
        if($response['status']===200&&empty($response['error'])){$old=libxml_use_internal_errors(true);$xml=simplexml_load_string($response['body'],'SimpleXMLElement',LIBXML_NONET);$valid=$xml!==false&&in_array($xml->getName(),['sitemapindex','urlset'],true);libxml_clear_errors();libxml_use_internal_errors($old);}
        $checks[]=Health::issue('sitemap',$valid?'pass':($response['status']?'review':'unknown'),'XML sitemap',$valid?'A sitemap XML response was found at '.$url:'Sitemap not verified at '.$url.'. Check that the sitemap module is enabled and the endpoint is reachable.','host');
        return$checks;
    }
    public static function start($kind='scan') {
        guard();if(!in_array($kind,['scan','fix'],true))throw new \RuntimeException('Invalid job.');
        $state=['id'=>wp_generate_password(16,false,false),'kind'=>$kind,'status'=>'running','cursor'=>0,'processed'=>0,'changed'=>0,'started'=>gmdate('c'),'user'=>get_current_user_id(),'site_pending'=>$kind==='scan'];
        update_option('open_seo_health_job',$state,false);wp_clear_scheduled_hook('open_seo_health_batch');wp_schedule_single_event(time()+10,'open_seo_health_batch');return$state;
    }
    public static function batch($expected='') {
        $state=get_option('open_seo_health_job',[]);if(!$state||$state['status']!=='running')return$state;
        if($expected&&$expected!==$state['id'])return$state;
        if(!user_can($state['user'],'manage_options')){$state['status']='stopped';$state['message']='The scan owner no longer has administrator access.';update_option('open_seo_health_job',$state,false);return$state;}
        $lock=get_option('open_seo_health_lock');if($lock&&time()-(int)$lock<120)return$state;if($lock)delete_option('open_seo_health_lock');if(!add_option('open_seo_health_lock',time(),'',false))return$state;
        $user=get_current_user_id();
        try{
            wp_set_current_user($state['user']);
            if(!class_exists('DOMDocument'))throw new \RuntimeException('Enable PHP DOM at the host to scan HTML.');
            if($state['site_pending']){update_option('open_seo_health_site',['at'=>gmdate('c'),'checks'=>self::site_checks()],false);$state['site_pending']=false;}
            else{
                global$wpdb;$types=Health::types();$slots=implode(',',array_fill(0,count($types),'%s'));$limit=$state['kind']==='scan'?2:20;
                $query="SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND post_status='publish' AND post_password='' AND post_type IN ($slots)";
                $args=array_merge([(int)$state['cursor']],$types);
                if($state['kind']==='fix'){$query.=" AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id={$wpdb->posts}.ID AND m.meta_key=%s)";$args[]=Health::REPORT;}
                $query.=' ORDER BY ID LIMIT '.(int)$limit;$ids=$wpdb->get_col($wpdb->prepare($query,$args));
                if($wpdb->last_error)throw new \RuntimeException('Could not read the website scan queue.');
                foreach($ids as$id){$current=get_option('open_seo_health_job',[]);if(($current['id']??'')!==$state['id']||($current['status']??'')!=='running')return$current;$id=(int)$id;if($state['kind']==='scan'){Health::scan_post($id);}else{$state['changed']+=Health::fix($id);}$state['cursor']=$id;$state['processed']++;}
                if(count($ids)<$limit){$state['status']='complete';$state['finished']=gmdate('c');if($state['kind']==='fix'){update_option('open_seo_health_last_fixes',$state,false);}else{self::duplicates();}}
            }
            // A new job may have replaced this one while its HTTP requests ran.
            $current=get_option('open_seo_health_job',[]);if(($current['id']??'')!==$state['id']||($current['status']??'')!=='running')return$current;
            update_option('open_seo_health_job',$state,false);
            if($state['status']==='running'&&!wp_next_scheduled('open_seo_health_batch'))wp_schedule_single_event(time()+15,'open_seo_health_batch');
            return$state;
        }catch(\Throwable$error){$current=get_option('open_seo_health_job',[]);if(($current['id']??'')!==$state['id']||($current['status']??'')!=='running')return$current;$state['status']='error';$state['message']=$error->getMessage();update_option('open_seo_health_job',$state,false);return$state;}
        finally{wp_set_current_user($user);delete_option('open_seo_health_lock');}
    }
    public static function duplicates() {
        global$wpdb;$rows=$wpdb->get_results("SELECT m.meta_key, COUNT(DISTINCT m.post_id) AS total, GROUP_CONCAT(DISTINCT m.post_id) AS ids FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key IN ('_open_seo_health_title_hash','_open_seo_health_description_hash') AND p.post_status='publish' AND p.post_password='' GROUP BY m.meta_key,m.meta_value HAVING COUNT(DISTINCT m.post_id)>1 LIMIT 50",ARRAY_A);
        $result=[];foreach($rows as$row){$result[]=['field'=>strpos($row['meta_key'],'description')!==false?'description':'title','count'=>(int)$row['total'],'ids'=>array_slice(array_map('intval',explode(',',$row['ids'])),0,10)];}update_option('open_seo_health_duplicates',$result,false);return$result;
    }
}
add_action('open_seo_health_batch',[HealthJobs::class,'batch']);
add_action('rest_api_init',function(){
    register_rest_route('open-seo/v1','/health/batch',['methods'=>'POST','permission_callback'=>function(){return current_user_can('manage_options');},'callback'=>function($request){return rest_ensure_response(HealthJobs::batch(sanitize_text_field($request->get_param('job')??'')));}]);
    register_rest_route('open-seo/v1','/health/page/(?P<id>\d+)',['methods'=>'POST','permission_callback'=>function($request){return current_user_can('manage_options')&&current_user_can('edit_post',(int)$request['id']);},'callback'=>function($request){$id=(int)$request['id'];if(!Health::allowed(get_post($id)))return new \WP_Error('not_public','Choose a public, published page.',['status'=>400]);if($request->get_param('operation')==='scan'){$report=Health::scan_post($id);return['message'=>$report['source'].': report saved. Open the full report for fixes and review steps.'];}if($request->get_param('operation')==='fix'){$changes=Health::fix($id);return['message'=>$changes.' empty fields/settings updated. Existing writing was preserved. Re-scan published SEO to verify the output.'];}return new \WP_Error('bad_operation','Unknown operation.',['status'=>400]);}]);
});
