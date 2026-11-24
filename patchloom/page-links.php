<?php
defined('ABSPATH') || exit;

// Rank shared words and minor spelling differences after exact and alias checks.
function patchloom_link_score($source,$candidate) {
    $ignored = ['our','the','a','and','of','to','page','pages','read','learn','more','click','here','view'];
    $words = array_unique(array_diff(explode('-',$source),$ignored,['']));
    $other = array_unique(array_diff(explode('-',$candidate),$ignored,['']));
    if (!$words || !$other) return 0;
    $overlap = count(array_intersect($words,$other));
    $score = 2 * $overlap / (count($words)+count($other));
    if (min(strlen($source),strlen($candidate))>=5 && max(strlen($source),strlen($candidate))<=120 && preg_match('/^[a-z0-9-]+$/D',$source) && preg_match('/^[a-z0-9-]+$/D',$candidate)) {
        $distance = levenshtein($source,$candidate);
        if ($distance<=2) $score=max($score,1-$distance/max(strlen($source),strlen($candidate)));
    }
    return $score;
}

function patchloom_link_options($pages) {
    return implode(', ',array_map(function($p){return '/'.trim(get_page_uri($p),'/').'/';},array_slice($pages,0,4)));
}

function patchloom_link_matches($index,$label,$stem,&$suggestions = []) {
    $suggestions=[];
    if (!empty($index[$label])) return $index[$label];
    if (!empty($index[$stem])) return $index[$stem];
    $groups = [
        ['about','about-us','about-me'],
        ['contact','contact-us','contact-me'],
        ['service','services','our-services'],
    ];
    foreach ($groups as $group) {
        if (!in_array($stem,$group,true)) continue;
        $matches = [];
        foreach ($group as $alias) {
            // A specific filename such as about-me must not imply about-us.
            if ($stem!==$group[0] && $alias!==$group[0]) continue;
            $matches += $index[$alias] ?? [];
        }
        if ($matches) return $matches;
    }
    $scores = $pages = [];
    foreach ($index as $key=>$entries) {
        // Avoid an alias fallback from one specific personal/company page to another.
        if (in_array($stem,['about-me','about-us','contact-me','contact-us'],true) && in_array($key,['about-me','about-us','contact-me','contact-us'],true)) continue;
        $score = max(patchloom_link_score($stem,(string)$key),patchloom_link_score($label,(string)$key));
        if ($score < 0.45) continue;
        foreach ($entries as $id=>$page) {
            $scores[$id] = max($scores[$id] ?? 0,$score);
            $pages[$id] = $page;
        }
    }
    arsort($scores);
    foreach (array_keys($scores) as $id) $suggestions[$id]=$pages[$id];
    $ranked=array_values($scores);
    if ($ranked && $ranked[0]>=0.80 && $ranked[0]-($ranked[1] ?? 0)>=0.15) return [reset($suggestions)];
    return [];
}

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
                $suggestions=[];
                if (!$matches) $matches=$legacy?[$legacy]:patchloom_link_matches($index,strpos($label,$prefix)===false?$key:'',$stem,$suggestions);
                if (count($matches)!==1) { $options=patchloom_link_options($matches ?: $suggestions); $report[]=$raw.' ('.$label.') — '.($options?'review candidates: '.$options:'no matching published page');return $m[0]; }
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
