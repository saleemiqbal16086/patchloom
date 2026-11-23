<?php
defined('ABSPATH') || exit;

function patchloom_page_links($source,&$report) {
    $index=$by_id=[];
    foreach (get_posts(['post_type'=>'page','post_status'=>'publish','posts_per_page'=>-1]) as $page) {
        if ($page->post_password) continue;
        $by_id[$page->ID]=$page;
        foreach ([$page->post_name,sanitize_title($page->post_title)] as $key) $index[$key][$page->ID]=$page;
    }

    // Mask PHP so markup matching never scans strings, comments or executable code.
    $prefix='PL'.substr(hash('sha256',$source),0,20).'X';
    $masked=$block='';
    $tokens=[];
    foreach (token_get_all($source) as $token) {
        $type=is_array($token)?$token[0]:null;
        $text=is_array($token)?$token[1]:$token;
        if ($type===T_INLINE_HTML) { $masked.=$text; continue; }
        $block.=$text;
        if ($type===T_CLOSE_TAG) {
            $key=$prefix.count($tokens).'Z';
            $tokens[$key]=$block;
            $masked.=$key;
            $block='';
        }
    }
    $pattern='~<!--.*?-->|<(?:script|style)\b[^>]*>.*?</(?:script|style)\s*>|(<a\s+(?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*>)(.*?)</a\s*>~is';
    $masked=preg_replace_callback($pattern,function($a) use ($tokens,$prefix,$index,$by_id,&$report) {
        if (empty($a[1])) return $a[0];
        $label=html_entity_decode(wp_strip_all_tags($a[2]),ENT_QUOTES,'UTF-8');
        $key=sanitize_title(trim($label));
        $open=preg_replace_callback('~(?:"[^"]*"|\x27[^\x27]*\x27)(*SKIP)(*F)|(\s+href\s*=\s*)(["\x27])([^"\x27]*)\2~i',function($m) use ($tokens,$prefix,$index,$by_id,$key,$label,&$report) {
            $raw=$m[3];
            $suffix='';
            $legacy=null;
            if (isset($tokens[$raw]) && preg_match('~^<\?php\s+echo\s+esc_url\(\s*get_permalink\(\s*(\d+)\s*\)\s*\)\s*;\s*\?>$~iD',$tokens[$raw],$id)) {
                $legacy=$by_id[(int)$id[1]]??null;
                if (!$legacy) { $report[]=$label.' — unpublished or missing page ID'; return $m[0]; }
                $stem='';
            } elseif (preg_match('~^(?:\./|/)?([\w-]+)\.html([?#][^<>]*)?$~iD',$raw,$path)) {
                $stem=sanitize_title($path[1]);
                $suffix=html_entity_decode($path[2]??'',ENT_QUOTES,'UTF-8');
            } else return $m[0];
            if ($stem==='index' && in_array($key,['home','homepage',''],true)) {
                $expr="home_url( '/' )";
                $dest='/';
            } else {
                $matches=strpos($label,$prefix)===false?($index[$key]??[]):[];
                if (!$matches) $matches=$legacy?[$legacy]:($index[$stem]??[]);
                if (count($matches)!==1) { $report[]=$label.' — no unique published page match';return $m[0]; }
                $page=reset($matches);
                $dest='/'.trim(get_page_uri($page),'/').'/';
                if ($dest==='//') return $m[0];
                $expr="home_url( ".var_export(user_trailingslashit($dest),true)." )";
                if (!get_option('permalink_structure')) {
                    $expr="add_query_arg( 'pagename', ".var_export(trim($dest,'/'),true).", home_url( '/' ) )";
                    if (substr($suffix,0,1)==='?') $suffix='&'.substr($suffix,1);
                }
            }
            if ($suffix!=='') $expr.=' . '.var_export($suffix,true);
            $report[]=$label.' → '.$dest.$suffix;
            return $m[1].$m[2].'<?php echo esc_url( '.$expr.' ); ?>'.$m[2];
        },$a[1],1);
        return $open.substr($a[0],strlen($a[1]));
    },$masked);
    if ($masked===null) throw new Exception('Could not parse this template.');
    return strtr($masked,$tokens).$block;
}
