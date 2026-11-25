<?php
defined('ABSPATH') || exit;

// Imported content is data. Only locally assembled, known builder elements are executable.
function pl_bakery_intent($text) {
    return preg_match('/wpbakery|wp bakery|bakery|import pages|page import/i',$text)
        && preg_match('/import|pages?|website|site/i',$text);
}
function pl_bakery_key() { return 'pl_bakery_'.get_current_user_id(); }
function pl_bakery_store($job) {
    $job['token']=wp_generate_uuid4();
    set_transient(pl_bakery_key(),$job,DAY_IN_SECONDS);
}
function pl_bakery_start($message) {
    preg_match("~https?://[^\\s<>\"']+~i",$message,$url);
    pl_bakery_store(['stage'=>'source','url'=>rtrim($url[0]??'', '.,)'), 'request'=>$message]);
    return 'Enter the original website URL. The active WordPress theme supplies the destination design.';
}
function pl_bakery_url($base,$href,$fragment=false) {
    $href=trim($href);
    if ($href==='' || preg_match('~^(?!https?:)[a-z][a-z0-9+.-]*:~i',$href)) return '';
    $url=WP_Http::make_absolute_url($href,$base);
    $p=wp_parse_url($url);
    if (empty($p['host']) || isset($p['user']) || isset($p['pass']) || !in_array(strtolower($p['scheme']??''),['https','http'],true)) return '';
    $origin=strtolower($p['scheme'].'://'.$p['host']).(isset($p['port'])?':'.$p['port']:'');
    return $origin.($p['path']??'/').(isset($p['query'])?'?'.$p['query']:'').($fragment && isset($p['fragment'])?'#'.$p['fragment']:'');
}
function pl_bakery_in_scope($url,$root) {
    $a=wp_parse_url($url); $b=wp_parse_url($root);
    if (!$a || !$b || strtolower($a['host']??'')!==strtolower($b['host']) || ($a['port']??null)!==($b['port']??null)) return false;
    $path=$a['path']??'/';
    $prefix=rtrim($b['path']??'/','/');
    return ($prefix==='' || $path===$prefix || strpos($path,$prefix.'/')===0)
        && !isset($a['query']) && !preg_match('~/(?:wp-admin|wp-json|wp-login\.php|feed|cart|checkout|my-account)(?:/|$)|\.(?!html?$|php$)[a-z0-9]{1,8}$~i',$path);
}
function pl_bakery_fetch($url,$limit,$binary=false) {
    for ($hop=0;$hop<5;$hop++) {
        $res=wp_safe_remote_get($url,['timeout'=>20,'redirection'=>0,'limit_response_size'=>$limit+1,'cookies'=>[]]);
        if (is_wp_error($res)) throw new Exception($res->get_error_message());
        $code=wp_remote_retrieve_response_code($res);
        if ($code>=300 && $code<400) {
            $next=pl_bakery_url($url,wp_remote_retrieve_header($res,'location'));
            if (!$next) break;
            $url=$next; continue;
        }
        if ($code!==200) throw new Exception('Source returned HTTP '.$code.' for '.$url);
        $body=wp_remote_retrieve_body($res);
        if (strlen($body)>$limit || $body==='') throw new Exception('Empty or oversized source: '.$url);
        if (!$binary && !preg_match('/html|xml/i',(string)wp_remote_retrieve_header($res,'content-type'))) throw new Exception('Expected HTML or sitemap XML: '.$url);
        return [$url,$body];
    }
    throw new Exception('Could not resolve redirects for '.$url);
}
function pl_bakery_dom($html) {
    $doc=new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
    return $doc;
}
function pl_bakery_seed($root) {
    $urls=[];
    $maps=[$root.'sitemap.xml',$root.'wp-sitemap.xml'];
    $seen=[];
    while ($maps && count($seen)<8) {
        $url=array_shift($maps);
        if (isset($seen[$url])) continue;
        $seen[$url]=true;
        try { [, $xml]=pl_bakery_fetch($url,2000000); }
        catch (Exception $e) {continue;}
        $doc=new DOMDocument();
        if (stripos($xml,'<!DOCTYPE')!==false || !$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)) continue;
        $index=$doc->documentElement->localName==='sitemapindex';
        foreach ($doc->getElementsByTagName('loc') as $loc) {
            if (!in_array($loc->parentNode->localName,['url','sitemap'],true)) continue;
            $link=pl_bakery_url($url,$loc->textContent);
            if ($index && wp_parse_url($link,PHP_URL_HOST)===wp_parse_url($root,PHP_URL_HOST)) $maps[]=$link;
            elseif (pl_bakery_in_scope($link,$root)) $urls[$link]=$link;
            if (count($urls)>=200) break 2;
        }
    }
    return $urls;
}
function pl_bakery_discover($base,$html,$root) {
    $urls=[];
    foreach (pl_bakery_dom($html)->getElementsByTagName('a') as $a) {
        $href=$a->getAttribute('href');
        if (!$href || $href[0]==='#') continue;
        $link=pl_bakery_url($base,$href);
        if (pl_bakery_in_scope($link,$root)) $urls[$link]=$link;
    }
    return $urls;
}
// Reuse button styling declared in this site's actual homepage template.
function pl_bakery_button_class() {
    static $result;
    if (isset($result)) return $result;
    $result='wp-element-button';
    if (!function_exists('get_page_template_slug')) return $result;
    $front=(int)get_option('page_on_front');
    $slug=$front?get_page_template_slug($front):'';
    $files=array_filter([$slug,'front-page.php','home.php','header.php']);
    foreach ($files as $name) {
        if (validate_file($name)!==0) continue;
        $path=locate_template([$name],false);
        if (!$path || !is_readable($path) || filesize($path)>250000) continue;
        preg_match_all('~<a\b[^>]*?\bclass=["\x27]([^"\x27<>]+)["\x27]~i',file_get_contents($path),$matches);
        foreach ($matches[1] as $classes) {
            $safe=array_filter(preg_split('/\s+/',trim($classes)),static function($c){
                return preg_match('/^(?:pbtn|btn|button|theme-btn|wp-element-button|btn-[a-z0-9-]+)$/iD',$c);
            });
            if ($safe) { $result=implode(' ',$safe); return $result; }
        }
    }
    return $result;
}
function pl_bakery_inline($node,$base) {
    if ($node instanceof DOMText) return esc_html($node->nodeValue);
    if (!($node instanceof DOMElement)) return '';
    $tag=strtolower($node->tagName);
    if (in_array($tag,['script','style','form','iframe','noscript','svg'],true)) return '';
    if ($tag==='br') return '<br>';
    $html='';
    foreach ($node->childNodes as $child) $html.=pl_bakery_inline($child,$base);
    if ($tag==='a') {
        $href=$node->getAttribute('href');
        $url=preg_match('/^(?:mailto:|tel:|#)/i',$href)?$href:pl_bakery_url($base,$href,true);
        $class=preg_match('/(?:btn|button|cta)/i',$node->getAttribute('class'))?' class="'.esc_attr(pl_bakery_button_class()).'"':'';
        return $url?'<a href="'.esc_url($url).'"'.$class.'>'.$html.'</a>':$html;
    }
    return in_array($tag,['strong','em','b','i','li','ul','ol','sub','sup','p','blockquote'],true)?'<'.$tag.'>'.$html.'</'.$tag.'>':$html;
}
function pl_bakery_blocks($node,$base,&$blocks) {
    if ($node instanceof DOMText) {
        if (trim($node->nodeValue)!=='') $blocks[]=['kind'=>'text','html'=>'<p>'.esc_html(trim($node->nodeValue)).'</p>'];
        return;
    }
    if (!($node instanceof DOMElement)) return;
    $tag=strtolower($node->tagName);
    if (in_array($tag,['script','style','nav','footer','form','iframe','noscript','svg','aside','button','template'],true) || $node->hasAttribute('hidden')) return;
    if ($tag==='details') {
        $summary=$node->getElementsByTagName('summary')->item(0);
        if ($summary && !$node->getElementsByTagName('img')->length) {
            $answer='';
            foreach ($node->childNodes as $child) if (!$child->isSameNode($summary)) $answer.=pl_bakery_inline($child,$base);
            $blocks[]=['kind'=>'faq','text'=>trim($summary->textContent),'html'=>$answer];
            return;
        }
    }
    if ($tag==='img') {
        $src=$node->getAttribute('data-src') ?: ($node->getAttribute('data-lazy-src') ?: $node->getAttribute('src'));
        $url=pl_bakery_url($base,$src);
        if ($url) $blocks[]=['kind'=>'image','url'=>$url,'alt'=>$node->getAttribute('alt')];
        return;
    }
    if (preg_match('/^h[1-6]$/',$tag)) {
        if (trim($node->textContent)!=='') $blocks[]=['kind'=>'heading','tag'=>$tag,'text'=>trim($node->textContent)];
        return;
    }
    if (in_array($tag,['p','blockquote','ul','ol','a','figcaption','summary'],true) && !$node->getElementsByTagName('img')->length) {
        $html=pl_bakery_inline($node,$base);
        if (trim(wp_strip_all_tags($html))!=='') $blocks[]=['kind'=>'text','html'=>$html];
        return;
    }
    // Preserve genuine sibling card groups as individually editable destination columns.
    $cards=[];
    if (in_array($tag,['div','section'],true)) {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMComment || ($child instanceof DOMText && trim($child->nodeValue)==='')) continue;
            if (!($child instanceof DOMElement) || !in_array($child->tagName,['div','article'],true) || !$child->getElementsByTagName('a')->length || !(new DOMXPath($node->ownerDocument))->query('.//h2|.//h3|.//h4',$child)->length) { $cards=[]; break; }
            $cards[]=$child;
        }
    }
    if (count($cards)>=3) {
        $items=[];
        foreach ($cards as $child) {
            $parts=[]; foreach ($child->childNodes as $part) pl_bakery_blocks($part,$base,$parts);
            $items[]=$parts;
        }
        // Nested card collections are rendered by their own nearest parent instead.
        foreach ($items as $parts) if (in_array('cards',array_column($parts,'kind'),true)) { $items=[]; break; }
        if ($items) { $blocks[]=['kind'=>'cards','items'=>$items]; return; }
    }
    foreach ($node->childNodes as $child) pl_bakery_blocks($child,$base,$blocks);
}
function pl_bakery_extract($html,$base) {
    $doc=pl_bakery_dom($html); $xpath=new DOMXPath($doc);
    $main=$xpath->query('//main|//*[@role="main"]')->item(0) ?: $xpath->query('//article')->item(0);
    if (!$main) {
        foreach ($xpath->query('//header|//footer|//nav|//aside') as $discard) $discard->parentNode->removeChild($discard);
        $main=$doc->getElementsByTagName('body')->item(0);
    }
    if (!$main) throw new Exception('No readable page body: '.$base);
    $title=trim($main->getElementsByTagName('h1')->item(0)->textContent ?? ($doc->getElementsByTagName('title')->item(0)->textContent ?? ''));
    if ($title==='') throw new Exception('Source page title missing: '.$base);
    $blocks=[]; pl_bakery_blocks($main,$base,$blocks);
    if (!$blocks) throw new Exception('No readable page content: '.$base);
    return [$title,$blocks];
}

function pl_bakery_identity($url) { return hash('sha256',untrailingslashit($url)); }
function pl_bakery_find($url,$type) {
    $ids=get_posts(['post_type'=>$type,'post_status'=>$type==='attachment'?'inherit':['draft','pending','private','publish','future'],'fields'=>'ids','posts_per_page'=>1,'meta_key'=>'_pl_source_url_hash','meta_value'=>pl_bakery_identity($url)]);
    return $ids?(int)$ids[0]:0;
}
function pl_bakery_image($block) {
    $url=$block['url'];
    $found=pl_bakery_find($url,'attachment');
    if ($found) return $found;
    [, $bytes]=pl_bakery_fetch($url,8*1024*1024,true);
    require_once ABSPATH.'wp-admin/includes/file.php';
    require_once ABSPATH.'wp-admin/includes/media.php';
    require_once ABSPATH.'wp-admin/includes/image.php';
    $tmp=wp_tempnam('patchloom-image');
    if (!$tmp) throw new Exception('Could not create a temporary image.');
    try {
        if (file_put_contents($tmp,$bytes)!==strlen($bytes)) throw new Exception('Could not stage source image.');
        $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/avif'=>'avif'][wp_get_image_mime($tmp)]??'';
        if (!$ext) throw new Exception('Unsupported image format: '.$url);
        $name=sanitize_file_name(pathinfo(wp_parse_url($url,PHP_URL_PATH),PATHINFO_FILENAME)) ?: 'image';
        $id=media_handle_sideload(['name'=>$name.'.'.$ext,'tmp_name'=>$tmp],0);
        if (is_wp_error($id)) throw new Exception($id->get_error_message());
        update_post_meta($id,'_pl_source_url_hash',pl_bakery_identity($url));
        update_post_meta($id,'_wp_attachment_image_alt',wp_slash($block['alt']));
        return $id;
    } finally {if (is_file($tmp)) unlink($tmp);}
}
function pl_bakery_text($value) {
    return str_replace(['[',']'],['&#91;','&#93;'],$value);
}
function pl_bakery_sections($blocks,$title) {
    $groups=[]; $group=[]; $skipped_title=false;
    foreach ($blocks as $b) {
        // The page template renders the title once.
        if (!$skipped_title && $b['kind']==='heading' && $b['tag']==='h1' && $b['text']===$title) { $skipped_title=true; continue; }
        if ($b['kind']==='heading' && in_array($b['tag'],['h1','h2'],true) && $group) { $groups[]=$group; $group=[]; }
        $group[]=$b;
    }
    if ($group) $groups[]=$group;
    return $groups;
}
function pl_bakery_palette() {
    $settings=function_exists('wp_get_global_settings')?wp_get_global_settings():[];
    $palette=$settings['color']['palette']['theme']??[];
    $colors=[];
    foreach ($palette as $color) if (!empty($color['slug']) && !empty($color['color'])) $colors[$color['slug']]=$color['color'];
    return $colors;
}
function pl_bakery_layouts($groups,$brief) {
    $layouts=[]; $summary=[];
    foreach ($groups as $i=>$group) {
        $kinds=array_column($group,'kind');
        $count=count(array_keys($kinds,'image',true));
        if (in_array('cards',$kinds,true)) { $layouts[]='text'; $summary[]=['blocks'=>$kinds]; continue; }
        $layouts[]=$count===1 && count($group)>1 ? ($i%2?'image-left':'image-right') : ($count>=2?'gallery':'text');
        $summary[]=['heading'=>current(array_column($group,'text')) ?: '', 'images'=>$count,'blocks'=>$kinds];
    }
    if (get_option('arv_key') && $summary) {
        $plan=arv_ask('Choose a restrained editorial layout for each existing section using the destination theme and brief. Return {"layouts":["text","image-left","image-right","gallery"]}, exactly one per section in order. No content generation. Prefer generous spacing, alternating image panels and readable text sections.',['theme'=>wp_get_theme()->get('Name'),'palette'=>pl_bakery_palette(),'brief'=>$brief,'sections'=>$summary],true,1200);
        foreach ($groups as $i=>$group) {
            $choice=$plan['layouts'][$i]??'';
            $count=count(array_keys(array_column($group,'kind'),'image',true));
            $allowed=['text'];
            if ($count===1 && count($group)>1) $allowed=array_merge($allowed,['image-left','image-right']);
            if ($count>=2) $allowed[]='gallery';
            if (in_array($choice,$allowed,true)) $layouts[$i]=$choice;
        }
    }
    return $layouts;
}
function pl_bakery_element($b) {
    if ($b['kind']==='cards') {
        $html='';
        foreach (array_chunk($b['items'],3) as $row) {
            $html.='[vc_row_inner equal_height="yes" el_class="pl-import-cards"]';
            foreach ($row as $parts) {
                $html.='[vc_column_inner width="1/3" el_class="pl-import-card"]';
                foreach ($parts as $part) $html.=pl_bakery_element($part);
                $html.='[/vc_column_inner]';
            }
            $html.='[/vc_row_inner]';
        }
        return $html;
    }
    if ($b['kind']==='image') return '[vc_single_image image="'.(int)$b['id'].'" img_size="full" alignment="center" el_class="pl-import-photo"]';
    if ($b['kind']==='heading') {
        $tag=$b['tag']==='h1'?'h2':$b['tag'];
        return '[vc_custom_heading text="'.pl_bakery_text(esc_attr($b['text'])).'" use_theme_fonts="yes" font_container="tag:'.$tag.'|text_align:left" el_class="pl-import-heading"]';
    }
    if ($b['kind']==='faq') return '[vc_toggle title="'.pl_bakery_text(esc_attr($b['text'])).'"][vc_column_text]'.pl_bakery_text($b['html']).'[/vc_column_text][/vc_toggle]';
    return '[vc_column_text]'.pl_bakery_text($b['html']).'[/vc_column_text]';
}
function pl_bakery_render($groups,$layouts) {
    $out='';
    foreach ($groups as $i=>$group) {
        $layout=$layouts[$i]; $content=''; $images='';
        if ($layout==='gallery') {
            // Consecutive images share a grid. Keep intervening headings and copy in source order.
            $run=[];
            foreach ($group as $b) {
                if ($b['kind']==='image') {$run[]=$b['id']; continue;}
                if ($run) {$content.='[vc_gallery type="image_grid" images="'.implode(',',array_map('intval',$run)).'" img_size="large"]';$run=[];}
                $content.=pl_bakery_element($b);
            }
            if ($run) $content.='[vc_gallery type="image_grid" images="'.implode(',',array_map('intval',$run)).'" img_size="large"]';
        } else {
            foreach ($group as $b) {
                if ($layout!=='text' && $b['kind']==='image') $images.=pl_bakery_element($b);
                else $content.=pl_bakery_element($b);
            }
        }
        $out.='[vc_row equal_height="yes" content_placement="middle" el_class="pl-import-section pl-import-'.$layout.'"]';
        if ($images) {
            $photo='[vc_column width="1/2"]'.$images.'[/vc_column]';
            $copy='[vc_column width="1/2"]'.$content.'[/vc_column]';
            $out.=$layout==='image-left'?$photo.$copy:$copy.$photo;
        } else $out.='[vc_column]'.$content.'[/vc_column]';
        $out.='[/vc_row]';
    }
    return $out;
}

add_action('wp_enqueue_scripts',function(){
    if (is_page() && get_post_meta(get_queried_object_id(),'_pl_source_url_hash',true)) {
        wp_enqueue_style('pl-import-layout',plugins_url('page-import.css',__FILE__),[],'0.9.0');
    }
});
// Resolve local page links at display time, including pages drafted later in the batch.
add_filter('the_content',function($html){
    if (!is_singular('page') || !get_post_meta(get_the_ID(),'_pl_source_url_hash',true)) return $html;
    return preg_replace_callback('~(<a\b[^>]*?\shref=)(["\x27])([^"\x27]+)\2~i',function($m){
        static $cache=[];
        $url=html_entity_decode($m[3],ENT_QUOTES,'UTF-8');
        if (!preg_match('~^https?://~i',$url)) return $m[0];
        $parts=explode('#',$url,2);
        if (!isset($cache[$parts[0]])) $cache[$parts[0]]=pl_bakery_find($parts[0],'page');
        $id=$cache[$parts[0]];
        if (!$id) return $m[0];
        return $m[1].$m[2].esc_url(get_permalink($id).(isset($parts[1])?'#'.$parts[1]:'')).$m[2];
    },$html);
},99);

function pl_bakery_local_images(&$blocks) {
    foreach ($blocks as &$block) {
        if ($block['kind']==='image') $block['id']=pl_bakery_image($block);
        if ($block['kind']==='cards') foreach ($block['items'] as &$parts) pl_bakery_local_images($parts);
    }
}
function pl_bakery_draft($url,$job,&$sample_data=null) {
    $id=pl_bakery_find($url,'page');
    if ($id) return $id;
    [$actual,$html]=pl_bakery_fetch($url,2000000);
    if (!pl_bakery_in_scope($actual,$job['root'])) throw new Exception('Page redirects outside the selected source: '.$url);
    $existing=pl_bakery_find($actual,'page');
    if ($existing) return $existing;
    [$title,$blocks]=pl_bakery_extract($html,$actual);
    $groups=pl_bakery_sections($blocks,$title);
    if (!$groups) throw new Exception('No body content after the title: '.$url);
    $layouts=pl_bakery_layouts($groups,$job['brief']);
    foreach ($groups as &$group) pl_bakery_local_images($group);
    unset($group);
    $content=pl_bakery_render($groups,$layouts);
    $slug=sanitize_title(basename(untrailingslashit(wp_parse_url($actual,PHP_URL_PATH)?:'')));
    $post=['post_type'=>'page','post_status'=>'draft','post_title'=>$title,'post_content'=>$content,'post_name'=>$slug ?: sanitize_title($title),'meta_input'=>['_wpb_vc_js_status'=>'true','_pl_source_url_hash'=>pl_bakery_identity($actual),'_pl_source_url'=>$actual]];
    $id=wp_insert_post(wp_slash($post),true);
    if (is_wp_error($id)) throw new Exception($id->get_error_message());
    $sample_data=['groups'=>$groups,'hash'=>hash('sha256',$content)];
    return $id;
}

function pl_bakery_action($act) {
    $key=pl_bakery_key(); $j=get_transient($key);
    if (!$j || !hash_equals($j['token'],sanitize_text_field($_POST['bakery_token']??''))) throw new Exception('Page import expired. Refresh the dashboard.');
    if ($act==='bakery_cancel') {delete_transient($key);return;}
    if (!current_user_can('manage_options') || !current_user_can('edit_pages') || !current_user_can('upload_files')) throw new Exception('Page and media permissions required.');
    if (!shortcode_exists('vc_row') || !shortcode_exists('vc_column_text')) throw new Exception('Activate WPBakery Page Builder on this site.');
    if (!class_exists('DOMDocument')) throw new Exception('Enable the PHP DOM extension to read source pages.');
    $lock='pl_bakery_import_lock';
    if (!add_option($lock,time(),'','no')) throw new Exception('Another page import is running.');
    try {
        $current=get_transient($key);
        if (!$current || $current['token']!==$j['token']) throw new Exception('Import progress changed. Refresh.');
        if ($act==='bakery_scan' && $j['stage']==='source') {
            $url=esc_url_raw(trim(wp_unslash($_POST['bakery_url']??'')),['http','https']);
            [$base,$html]=pl_bakery_fetch($url,2000000);
            $path=wp_parse_url($base,PHP_URL_PATH)?:'/';
            $scope=preg_match('~\.[a-z0-9]{1,6}$~i',$path)?trailingslashit(dirname($path)):trailingslashit($path);
            $j['root']=preg_replace('~^(https?://[^/]+).*~','$1',$base).'/'.ltrim($scope,'/');
            $j['brief']=sanitize_textarea_field(wp_unslash($_POST['bakery_brief']??''));
            $j['pages']=[$base=>$base];
            $j['seen']=[$base=>true];
            $j['notes']=[];
            $j['pages']+=pl_bakery_seed($j['root'])+pl_bakery_discover($base,$html,$j['root']);
            $j['stage']='discover';
        } elseif ($act==='bakery_discover' && $j['stage']==='discover') {
            $next=array_diff_key($j['pages'],$j['seen']);
            if ($next) {
                $url=reset($next);
                try {
                    [$base,$html]=pl_bakery_fetch($url,2000000);
                    if (!pl_bakery_in_scope($base,$j['root'])) throw new Exception('Outside selected site');
                    $j['pages']+=pl_bakery_discover($base,$html,$j['root']);
                } catch (Exception $e) {
                    $j['notes'][]=$url.' — '.$e->getMessage();
                    unset($j['pages'][$url]);
                }
                $j['seen'][$url]=true;
            }
            if (count($j['pages'])>=200) {
                $j['pages']=array_slice($j['pages'],0,200,true);
                $j['notes'][]='Discovery stopped at the 200-page limit.';
                $j['stage']='select';
            } elseif (!array_diff_key($j['pages'],$j['seen'])) $j['stage']='select';
        } elseif ($act==='bakery_finish_scan' && $j['stage']==='discover') {
            $j['stage']='select';
        } elseif ($act==='bakery_sample' && $j['stage']==='select') {
            $selected=(array)wp_unslash($_POST['bakery_pages']??[]);
            $queue=array_values(array_intersect($j['pages'],$selected));
            $url=wp_unslash($_POST['bakery_sample_url']??'');
            if (!in_array($url,$queue,true)) throw new Exception('Include the sample page in your selection.');
            $j['sample']=pl_bakery_draft($url,$j,$j['sample_data']);
            $j['queue']=array_values(array_diff($queue,[$url]));
            $j['done']=[$url=>$j['sample']]; $j['stage']='review';
        } elseif ($act==='bakery_revise' && $j['stage']==='review') {
            $id=$j['sample']; $brief=sanitize_textarea_field(wp_unslash($_POST['bakery_revision']??''));
            if (!$brief || empty($j['sample_data']) || !get_option('arv_key')) throw new Exception('Enter a layout instruction and configure Arena for layout revisions.');
            if (!current_user_can('edit_post',$id) || get_post_status($id)!=='draft' || hash('sha256',get_post_field('post_content',$id,'raw'))!==$j['sample_data']['hash']) throw new Exception('This draft was edited separately. Review those changes before continuing.');
            $j['brief'].="\n".$brief;
            $groups=$j['sample_data']['groups'];
            $content=pl_bakery_render($groups,pl_bakery_layouts($groups,$j['brief']));
            $result=wp_update_post(wp_slash(['ID'=>$id,'post_content'=>$content]),true);
            if (is_wp_error($result)) throw new Exception($result->get_error_message());
            $j['sample_data']['hash']=hash('sha256',$content);
        } elseif ($act==='bakery_approve' && $j['stage']==='review') {
            unset($j['sample_data']);
            $j['stage']=$j['queue']?'import':'done';
        } elseif (in_array($act,['bakery_next','bakery_skip'],true) && $j['stage']==='import') {
            $url=$j['queue'][0];
            if ($act==='bakery_skip') $j['notes'][]='Skipped '.$url;
            else $j['done'][$url]=pl_bakery_draft($url,$j);
            array_shift($j['queue']); if (!$j['queue']) $j['stage']='done';
        } else throw new Exception('Action does not match the current import step.');
        pl_bakery_store($j);
    } finally {delete_option($lock);}
}
function pl_bakery_button($act,$text) {
    echo '<button class="button" name="arv_action" value="'.esc_attr($act).'">'.esc_html($text).'</button> ';
}
function pl_bakery_controls() {
    $j=get_transient(pl_bakery_key()); if (!$j) return;
    echo '<form method="post"><h3>Import pages with WPBakery</h3>';
    wp_nonce_field('arv_admin');
    echo '<input type="hidden" name="bakery_token" value="'.esc_attr($j['token']).'">';
    if ($j['stage']==='source') {
        echo '<label>Original website or subdirectory URL <input type="url" class="regular-text" name="bakery_url" value="'.esc_attr($j['url']).'"></label><p>Destination theme: <strong>'.esc_html(wp_get_theme()->get('Name')).'</strong></p><label>Layout preferences <textarea name="bakery_brief" rows="2">Follow our current website typography, colors and spacing. Keep the original wording.</textarea></label><p>Arena receives section headings and layout preferences when a key is configured. Source images will be copied to the Media Library when drafting.</p>';
        pl_bakery_button('bakery_scan','Find source pages');
    } elseif ($j['stage']==='discover') {
        echo '<p>'.count($j['pages']).' URLs discovered · '.count($j['seen']).' checked</p>';
        pl_bakery_button('bakery_discover','Continue discovery'); pl_bakery_button('bakery_finish_scan','Use current list');
    } elseif ($j['stage']==='select') {
        echo '<p>Choose pages to draft and pick the first page for design review.</p>';
        foreach ($j['pages'] as $url) echo '<label style="display:block"><input type="checkbox" checked name="bakery_pages[]" value="'.esc_attr($url).'"> '.esc_html($url).'</label>';
        echo '<p><label>First draft <select name="bakery_sample_url">';
        foreach ($j['pages'] as $url) echo '<option>'.esc_html($url).'</option>';
        echo '</select></label></p>';pl_bakery_button('bakery_sample','Create first draft');
    } elseif ($j['stage']==='review') {
        echo '<p><a target="_blank" rel="noopener" href="'.esc_url(get_preview_post_link($j['sample'])).'">Preview first page</a> · <a target="_blank" rel="noopener" href="'.esc_url(get_edit_post_link($j['sample'])).'">Edit layout</a></p><p>'.count($j['queue']).' remaining pages selected. Drafts use your destination theme and the saved layout preferences.</p>';
        if (!empty($j['sample_data']) && get_option('arv_key')) {
            echo '<label>Layout feedback <input class="regular-text" name="bakery_revision" placeholder="Place the image on the right"></label> ';
            pl_bakery_button('bakery_revise','Revise first draft');
        }
        pl_bakery_button('bakery_approve','Approve and draft selected pages');
    } elseif ($j['stage']==='import') {
        echo '<p>Next: '.esc_html($j['queue'][0]).' · '.count($j['queue']).' remaining</p>';
        pl_bakery_button('bakery_next','Continue import'); pl_bakery_button('bakery_skip','Skip this URL');
    } else {
        echo '<p>'.count($j['done']).' pages available for review.</p>';
        foreach ($j['done'] as $url=>$id) echo '<p><a href="'.esc_url(get_edit_post_link($id)).'">'.esc_html(get_the_title($id)).'</a> · '.esc_html($url).'</p>';
    }
    if (!empty($j['notes'])) echo '<details><summary>Import notes</summary><pre>'.esc_html(implode("\n",$j['notes'])).'</pre></details>';
    pl_bakery_button('bakery_cancel',$j['stage']==='done'?'Close':'Stop');echo '</form>';
}
add_filter('template_include',function($template){
    if (!is_page() || (function_exists('wp_is_block_theme') && wp_is_block_theme())) return $template;
    $id=get_queried_object_id();
    return get_post_meta($id,'_pl_source_url_hash',true) && !get_page_template_slug($id) ? __DIR__.'/imported-page.php' : $template;
},50);
