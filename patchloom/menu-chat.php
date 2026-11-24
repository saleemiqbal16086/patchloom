<?php
defined('ABSPATH') || exit;

function patchloom_menu_intent($text) {
    if (preg_match('/\.html|permalinks?|\bslugs?\b|get_permalink/i',$text) && !preg_match('/wp_nav_menu/i',$text)) return false;
    return preg_match('/\bmenu\b/i',$text) && preg_match('/dynamic|wp[_ ]nav[_ ]menu|wordpress menu|wp menu/i',$text);
}

add_action('after_setup_theme',function(){
    if (get_theme_mod('patchloom_menu_enabled')) register_nav_menu('patchloom-header','PatchLoom header');
});

function patchloom_menu_preview($source,&$task) {
    if (!current_user_can('edit_theme_options')) throw new Exception('Menu editing permission is required.');
    if (strpos($source,"'patchloom-header'")!==false) throw new Exception('This header already uses the PatchLoom menu location. Open Appearance → Menus.');
    if (!preg_match_all('~<ul\b([^>]*)>((?:(?!<ul\b|</ul>).)*)</ul>~is',$source,$lists,PREG_SET_ORDER)) throw new Exception('No simple menu list found in this template.');
    $candidates=array_values(array_filter($lists,function($list){return preg_match('~\bid\s*=\s*["\x27]menu["\x27]~i',$list[1]);}));
    if (!$candidates && count($lists)===1) $candidates=$lists;
    if (count($candidates)!==1) throw new Exception('Could not identify one flat navigation list. No changes made.');
    [$old,$attrs,$body]=$candidates[0];
    $ul_id='menu'; $ul_class='menu';
    foreach (['id'=>&$ul_id,'class'=>&$ul_class] as $attr=>&$value) {
        if (preg_match('~\b'.$attr.'\s*=\s*(["\x27])([\w -]+)\1~i',$attrs,$found)) $value=$found[2];
    }
    unset($value);
    $pattern='~<li\s*>\s*<a\s+href\s*=\s*(["\x27])((?:<\?php.*?\?>|(?!\1).)*?)\1(?:\s+class\s*=\s*(["\x27])active\3)?\s*>([^<>]+)</a>\s*</li>~is';
    preg_match_all($pattern,$body,$anchors,PREG_SET_ORDER);
    if (!$anchors || trim(preg_replace($pattern,'',$body))!=='') throw new Exception('Menu contains nested items or custom markup. Automatic conversion requires a flat text menu.');
    $index=[];
    foreach (get_pages(['post_status'=>'publish']) as $p) {
        if ($p->post_password) continue;
        foreach ([$p->post_name,sanitize_title($p->post_title)] as $k) $index[$k][$p->ID]=$p;
    }
    $task['items']=$task['report']=[];
    foreach ($anchors as $a) {
        $label=trim(html_entity_decode($a[4],ENT_QUOTES,'UTF-8'));
        $href=trim($a[2]);
        $id=0;
        if (preg_match('~^(?:index\.html|/|<\?php\s+echo\s+esc_url\(\s*home_url\(\s*["\x27]/["\x27]\s*\)\s*\)\s*;\s*\?>)$~i',$href)) $url=home_url('/');
        else {
            if (preg_match('~^<\?php\s+echo\s+esc_url\(\s*get_permalink\(\s*(\d+)\s*\)\s*\)\s*;\s*\?>$~',$href,$m)) $id=(int)$m[1];
            elseif (preg_match('~^<\?php\s+echo\s+esc_url\(\s*home_url\(\s*["\x27](/[\w/-]+)["\x27]\s*\)\s*\)\s*;\s*\?>$~',$href,$m)) {
                $page=get_page_by_path(trim($m[1],'/')); $id=$page?$page->ID:0;
            } elseif (preg_match('~^(?:\./|/)?([\w-]+)\.html$~i',$href,$m)) {
                $matches=patchloom_link_matches($index,sanitize_title($label),sanitize_title($m[1]));
                if (count($matches)===1) $id=reset($matches)->ID;
            }
            $page=$id?get_post($id):null;
            if (!$page || $page->post_type!=='page' || $page->post_status!=='publish' || $page->post_password) throw new Exception('Could not verify destination for '.$label.'. Menu unchanged.');
            $url=get_permalink($id);
        }
        $task['items'][]=['label'=>$label,'page'=>$id,'url'=>$url];
        $task['report'][]=$label.' → '.$url;
    }
    $new="<?php wp_nav_menu( ".var_export(['theme_location'=>'patchloom-header','container'=>false,'menu_id'=>$ul_id,'menu_class'=>$ul_class,'fallback_cb'=>false,'depth'=>1],true)." ); ?>";
    return [$old,$new];
}

function patchloom_create_header_menu($items) {
    if (!current_user_can('edit_theme_options')) throw new Exception('Menu editing permission is required.');
    $locations=get_theme_mod('nav_menu_locations',[]);
    if (!empty($locations['patchloom-header'])) throw new Exception('Header location already assigned. Review Appearance → Menus.');
    $menu=wp_create_nav_menu('PatchLoom Header '.substr(wp_generate_uuid4(),0,8));
    if (is_wp_error($menu)) throw new Exception($menu->get_error_message());
    try {
        foreach ($items as $i=>$item) {
            if ($item['page'] && get_post_status($item['page'])!=='publish') throw new Exception('Destination changed. Preview again.');
            $result=wp_update_nav_menu_item($menu,0,wp_slash(['menu-item-title'=>$item['label'],'menu-item-type'=>$item['page']?'post_type':'custom','menu-item-object'=>$item['page']?'page':'custom','menu-item-object-id'=>$item['page'],'menu-item-url'=>$item['url'],'menu-item-position'=>$i+1,'menu-item-status'=>'publish']));
            if (is_wp_error($result) || !$result) throw new Exception('Could not create menu item '.$item['label']);
        }
    } catch (Throwable $e) { wp_delete_nav_menu($menu); throw $e; }
    $locations['patchloom-header']=$menu;
    set_theme_mod('nav_menu_locations',$locations);
    return $menu;
}
