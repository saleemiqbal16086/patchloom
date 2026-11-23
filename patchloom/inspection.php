<?php
defined('ABSPATH') || exit;

/** Count literal href targets in markup. PHP-computed destinations need runtime inspection. */
function patchloom_href_count($source) {
    $pattern = '~\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'<>]+))~i';
    preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);
    $count = 0;
    foreach ($matches as $match) {
        $value = trim(html_entity_decode($match[1] ?: (($match[2] ?? '') ?: ($match[3] ?? '')), ENT_QUOTES, 'UTF-8'));
        if (preg_match('~^[^?#<>]*\.html(?:[?#].*)?$~isD', $value)) $count++;
    }
    return $count;
}

function patchloom_theme_inventory($scan, &$hits, &$skips) {
    $roots = array_unique([get_stylesheet_directory(), get_template_directory()]);
    $names = [];
    foreach ($roots as $root) {
        $base = realpath($root);
        if (!$base || !is_readable($base)) { $skips++; continue; }
        $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($tree as $file) {
            if ($file->isLink() || !$file->isFile()) continue;
            $path = $file->getRealPath();
            if (!$path || strpos($path, $base.DIRECTORY_SEPARATOR)!==0) continue;
            $name = basename($base).'/'.substr($path,strlen($base)+1);
            if (preg_match('~(?:^|[/\\\\])(?:\.|vendor[/\\\\]|node_modules[/\\\\])~',$name)) continue;
            if (!preg_match('/\.(?:php|html?|twig|js|css)$/i',$name)) continue;
            if (count($names)>=2000) { $skips++; break 2; }
            $names[]=$name;
            if (!$scan) continue;
            if ($file->getSize()>2097152 || !$file->isReadable()) { $skips++; continue; }
            $source=file_get_contents($path);
            if ($source===false) { $skips++; continue; }
            $n=patchloom_href_count($source);
            if ($n) $hits[$name]=$n;
        }
    }
    sort($names);
    return $names;
}

function patchloom_elementor_links($node) {
    if (!is_array($node)) return 0;
    $count=0;
    foreach ($node as $key=>$value) {
        if (is_array($value)) $count+=patchloom_elementor_links($value);
        elseif (is_string($value)) {
            if ($key==='url' && preg_match('~^[^?#<>]*\.html(?:[?#].*)?$~iD',trim($value))) $count++;
            else $count+=patchloom_href_count($value);
        }
    }
    return $count;
}

function patchloom_inspect($message) {
    $links=(bool)preg_match('/\.html\b/i',$message) && (bool)preg_match('/scan|kitn|count|list|find|dhoond|check|how many|ginti/i',$message);
    $inventory=(bool)preg_match('/theme/i',$message) && (bool)preg_match('/files?|templates?/i',$message) && (bool)preg_match('/dekh|dikha|list|scan|check|access|inspect|show|read|see/i',$message);
    if (!$links && !$inventory) return null;
    if (!current_user_can('manage_options')) throw new Exception('Administrator access required.');
    $hits=[];
    $skips=0;
    $names=patchloom_theme_inventory($links,$hits,$skips);
    $theme=wp_get_theme()->get('Name');
    if (!$links) return "Active theme: ".$theme."\nTheme source files found: ".count($names)."\n".implode("\n",array_slice($names,0,45)).(count($names)>45?"\nShowing first 45.":"").($skips?"\nInventory incomplete.":"");
    global $wpdb;
    $theme_total=array_sum($hits);
    $records=0;
    $cursor=0;
    $types=array_unique(array_merge(get_post_types(['public'=>true],'names'),['nav_menu_item','wp_block','wp_template','wp_template_part']));
    unset($types['attachment']);
    $types=array_values(array_diff($types,['attachment']));
    $placeholders=implode(',',array_fill(0,count($types),'%s'));
    do {
        $sql="SELECT ID, post_type, post_content, post_password FROM {$wpdb->posts} WHERE ID > %d AND post_status='publish' AND post_type IN ($placeholders) ORDER BY ID LIMIT 100";
        $batch=$wpdb->get_results($wpdb->prepare($sql,array_merge([$cursor],$types)));
        if ($wpdb->last_error) throw new Exception('WordPress could not read the content inventory.');
        foreach ($batch as $post) {
            $cursor=(int)$post->ID;
            if ($post->post_password) continue;
            if (++$records>5000) { $skips++; break 2; }
            $elementor=get_post_meta($cursor,'_elementor_data',true);
            if (get_post_meta($cursor,'_elementor_edit_mode',true)==='builder' && is_string($elementor) && is_array($tree=json_decode($elementor,true))) $n=patchloom_elementor_links($tree);
            else $n=patchloom_href_count($post->post_content);
            if ($post->post_type==='nav_menu_item' && preg_match('~^[^?#<>]*\.html(?:[?#].*)?$~iD',(string)get_post_meta($cursor,'_menu_item_url',true))) $n++;
            if ($n) $hits[$post->post_type.' #'.$cursor]=$n;
        }
    } while (count($batch)===100);
    arsort($hits);
    $lines=[];
    foreach (array_slice($hits,0,40,true) as $where=>$number) $lines[]=$where.' — '.$number;
    return "Literal .html link occurrences found: ".array_sum($hits)."\nTheme files: ".$theme_total." | Published content and menu records: ".(array_sum($hits)-$theme_total)."\n".implode("\n",$lines)."\nCounts are stored references, including repeated links, not rendered page or unique URL counts. Dynamic PHP, custom fields and third-party output are outside this scan.".($skips?" Some sources were skipped or a scan limit was reached.":"");
}
