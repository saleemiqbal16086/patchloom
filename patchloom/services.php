<?php
defined('ABSPATH') || exit;

function pl_services_intent($text) {
    return preg_match('/\\bservices?\\b/i',$text) && preg_match('/\\bcpt\\b|post\\s*type|postype|single-service|services? migration/i',$text);
}
function pl_services_register() {
    if (get_option('pl_services_owned') && !post_type_exists('service')) {
        register_post_type('service',['labels'=>['name'=>'Services','singular_name'=>'Service'],'public'=>true,'show_in_rest'=>true,'has_archive'=>false,'rewrite'=>['slug'=>'service','with_front'=>false],'supports'=>['title','editor','excerpt','thumbnail','page-attributes']]);
    }
}
add_action('init','pl_services_register',99);

function pl_services_key() { return 'pl_services_job_'.get_current_user_id(); }
function pl_services_store($job) {
    $job['token']=wp_generate_uuid4();
    set_transient(pl_services_key(),$job,HOUR_IN_SECONDS);
}
function pl_services_types() {
    return get_post_types(['public'=>true,'_builtin'=>false],'objects');
}
function pl_services_start($request) {
    pl_services_store(['stage'=>'select','request'=>$request,'theme'=>get_stylesheet()]);
    return 'Select your Services listing page, its PHP template, the service detail template and the post type. Approve reading both templates for a preview.';
}
function pl_services_existing($type,$title) {
    if (!post_type_exists($type)) return 0;
    $ids=get_posts(['post_type'=>$type,'post_status'=>['draft','pending','private','future','publish'],'title'=>$title,'posts_per_page'=>2,'fields'=>'ids','suppress_filters'=>true]);
    $slug=get_page_by_path(sanitize_title($title),OBJECT,[$type]);
    if ($slug && $slug->post_status!=='trash') $ids[]=$slug->ID;
    $ids=array_unique(array_map('intval',$ids));
    if (count($ids)>1) throw new Exception('Multiple existing services match '.$title);
    return $ids ? reset($ids) : 0;
}

// Substitute only unique source literals. Generated PHP comes from fixed local mappings.
function pl_services_bind($source,$bindings,$expressions,$required) {
    if (!is_array($bindings)) throw new Exception('Missing template mappings.');
    $replace=[]; $used=[];
    foreach ($bindings as $b) {
        $old=$b['old']??null; $field=$b['field']??'';
        if (!is_string($old) || $old==='' || !isset($expressions[$field]) || (strpos($old,'<?')!==false && $field!=='image') || substr_count($source,$old)<1 || isset($replace[$old])) throw new Exception('Invalid service template mapping. Retry preview.');
        if ($field==='image' && strpos($old,'<?')!==false && !preg_match('~^<\?php\s+(?:echo\s+(?:get_template_directory_uri|get_stylesheet_directory_uri)\(\s*\)|bloginfo\(\s*["\x27](?:template_url|stylesheet_url|template_directory)["\x27]\s*\))\s*;?\s*\?>/[^<>"\x27]+$~iD',$old)) throw new Exception('Unsupported dynamic image source.');
        if (isset($b['wrap']) && $b['wrap']!=='') {
            // Identical text in several places may require different destinations; use exact context.
            $wrap=$b['wrap'];
            if (!is_string($wrap) || substr_count($wrap,$old)!==1 || substr_count($source,$wrap)!==1 || isset($replace[$wrap])) throw new Exception('Ambiguous template context.');
            $replace[$wrap]=str_replace($old,$expressions[$field],$wrap);
        } else {
            $replace[$old]=$expressions[$field];
        }
        $used[$field]=true;
    }
    if (array_diff($required,array_keys($used))) throw new Exception('Incomplete service template mappings.');
    $out=strtr($source,$replace);
    foreach ($required as $field) if (strpos($out,$expressions[$field])===false) throw new Exception('Overlapping service mappings.');
    token_get_all($out,TOKEN_PARSE);
    return $out;
}

function pl_services_visible_text($html) {
    $html=preg_replace('~<\?.*?\?>|<!--.*?-->~s','',$html);
    return trim(preg_replace('/[\s\p{Z}]+/u',' ',html_entity_decode(wp_strip_all_tags($html),ENT_QUOTES | ENT_HTML5,'UTF-8')));
}

// Keep byte offsets into the original source, including theme PHP in attributes.
function pl_services_spans($source) {
    $masked='';
    foreach (token_get_all($source) as $token) {
        $text=is_array($token)?$token[1]:$token;
        $masked.=is_array($token) && $token[0]===T_INLINE_HTML ? $text : str_repeat(' ',strlen($text));
    }
    preg_match_all('~<!--.*?-->|<(script|style)\b(?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*>.*?</\1\s*>|<(/?)([a-z][a-z0-9:-]*)\b(?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*>~is',$masked,$tags,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
    $stacks=[]; $spans=[];
    foreach ($tags as $t) {
        if (empty($t[3][0])) continue;
        $tag=strtolower($t[3][0]); $offset=$t[0][1]; $length=strlen($t[0][0]);
        if ($t[2][0]==='/') {
            if (empty($stacks[$tag])) continue;
            [$start,$open]=array_pop($stacks[$tag]);
            $spans[$open][]=[$start,$offset+$length];
        } elseif (!in_array($tag,['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'],true) && substr($t[0][0],-2)!=='/>') {
            $open=trim(preg_replace('/\s+/',' ',substr($source,$offset,$length)));
            $stacks[$tag][]=[$offset,$open];
        }
    }
    return $spans;
}

function pl_services_locate_card($source,$spans,$card,$end,$number,$file) {
    $title=pl_services_visible_text($card['title']??'');
    $context=$file.' — card '.$number.' ('.$title.')';
    if ($title==='') throw new Exception($context.': missing service title.');
    $html=$card['html']??'';
    $pos=is_string($html) && $html!=='' ? strpos($source,$html,$end) : false;
    if ($pos!==false && stripos(pl_services_visible_text($html),$title)!==false) return [$pos,$html];
    $open=trim(preg_replace('/\s+/',' ',$card['opening_tag']??''));
    if (!$open && is_string($html) && preg_match('~^\s*(<[^>]+>)~',$html,$m)) $open=trim(preg_replace('/\s+/',' ',$m[1]));
    $candidates=[];
    foreach ($spans[$open]??[] as [$start,$stop]) {
        if ($start<$end) continue;
        $raw=substr($source,$start,$stop-$start);
        if (stripos(pl_services_visible_text($raw),$title)!==false) $candidates[$start]=$raw;
    }
    if ($candidates) {
        ksort($candidates);
        return [key($candidates),reset($candidates)];
    }
    throw new Exception($context.': could not locate the card wrapper and visible title together. Select the PHP template containing the actual card markup.');
}

function pl_services_inventory(&$j) {
    $listing=patchloom_file_source($j,$j['listing']);
    $inventory=arv_ask('Identify all repeated adjacent outer service cards in listing order. Return compact JSON {cards:[{opening_tag:"exact outer opening tag only",title:"literal visible title",excerpt:"original short description or empty",image:"theme-relative image path or absolute URL or empty"}],card_bindings:[{old:"exact source literal from FIRST card",field:"title|excerpt|link|image",wrap:"optional exact unique context"}]}. Bind title and detail links, image and excerpt when present. Include a theme PHP prefix in the old image src value. Do not return whole cards, PHP or generated copy. Return {issue:"reason"} if impossible.',['request'=>$j['request'],'listing'=>$listing],true,6000,120);
    if (!empty($inventory['issue'])) throw new Exception(sanitize_text_field($inventory['issue']));
    if (empty($inventory['cards']) || empty($inventory['card_bindings'])) throw new Exception('No service cards or card mappings returned.');
    $j['listing_source']=$listing;
    $j['inventory']=$inventory;
    $j['stage']='detail';
}

function pl_services_pending_copy($j) {
    return array_filter($j['rows'],static function($row){ return !$row['existing'] && $row['content']===''; });
}

function pl_services_copy_step(&$j) {
    $pending=pl_services_pending_copy($j);
    if ($pending) {
        $index=key($pending);
        $row=$pending[$index];
        $answer=arv_ask('Prepare one service draft. Return JSON {excerpt:"preserve meaningful original excerpt, replace missing or dummy copy",content:"plain text detail paragraphs",sections:{}}. Provide a value for each supplied section key. Match the service and headings. Do not invent credentials, prices or guarantees.',['request'=>$j['request'],'service'=>$row['title'],'excerpt'=>$row['excerpt'],'section_labels'=>$j['labels']],true,2500,120);
        $row['content']=sanitize_textarea_field($answer['content']??'');
        $row['excerpt']=sanitize_textarea_field($answer['excerpt']??$row['excerpt']);
        if ($row['content']==='') throw new Exception('Missing detail copy for '.$row['title'].'. Retry this step.');
        foreach ($j['labels'] as $key=>$label) {
            $row['sections'][$key]=sanitize_textarea_field($answer['sections'][$key]??'');
            if ($row['sections'][$key]==='') throw new Exception('Missing section for '.$row['title'].'. Retry this step.');
        }
        $j['rows'][$index]=$row;
    }
    $j['stage']=pl_services_pending_copy($j)?'copy':'preview';
}

function pl_services_prepare(&$j) {
    $listing=patchloom_file_source($j,$j['listing']);
    $detail=patchloom_file_source($j,$j['detail']);
    $type=$j['type'];
    $target='single-'.$type.'.php';
    if ($target===$j['listing'] || $j['listing']===$j['detail']) throw new Exception('Select separate listing and detail templates.');
    $target_path=pl_target($target);
    $prior=is_file($target_path)?patchloom_file_source($j,$target):null;
    if ($listing!==$j['listing_source']) throw new Exception('Listing changed. Cancel and start a new preview.');
    $mapping=arv_ask('Map only the single service detail template. Return JSON {detail_bindings:[{old:"exact source literal",field:"title|excerpt|content|image|section_name",wrap:"optional exact unique context"}],section_labels:{section_name:"Editable heading"}}. Map title and main content and any additional detail text sections. Preserve the source HTML and layout. Use literals inside text nodes or attribute values. Do not write service copy or PHP. Return {issue:"reason"} if impossible.',['request'=>$j['request'],'detail'=>$detail],true,4096,120);
    if (!empty($mapping['issue'])) throw new Exception(sanitize_text_field($mapping['issue']));
    $data=$j['inventory'];
    $data['section_labels']=$mapping['section_labels']??[];
    $data['detail_bindings']=$mapping['detail_bindings']??[];
    $cards=$data['cards']??[];
    if (!is_array($cards) || !$cards) throw new Exception('Arena could not identify service cards.');
    $labels=$data['section_labels']??[];
    if (!is_array($labels)) throw new Exception('Invalid detail field mapping.');
    $expr=['title'=>'<?php echo esc_html(get_the_title()); ?>','excerpt'=>'<?php echo esc_html(get_the_excerpt()); ?>','link'=>'<?php echo esc_url(get_permalink()); ?>','image'=>'<?php echo esc_url(get_the_post_thumbnail_url(get_the_ID(),"full") ?: get_post_meta(get_the_ID(),"_pl_service_image",true)); ?>','content'=>'<?php echo nl2br(esc_html(get_post_field("post_content",get_the_ID()))); ?>'];
    foreach ($labels as $key=>$label) {
        if (!preg_match('/^section_[a-z0-9_]{1,40}$/D',$key) || !is_string($label) || trim($label)==='') throw new Exception('Invalid service section name.');
        $expr[$key]='<?php echo nl2br(esc_html(get_post_meta(get_the_ID(),"pl_'.$key.'",true))); ?>';
    }
    $rows=[]; $start=null; $end=0;
    $spans=pl_services_spans($listing);
    foreach ($cards as $index=>$c) {
        [$pos,$html]=pl_services_locate_card($listing,$spans,$c,$end,$index+1,$j['listing']);
        $name=pl_services_visible_text($c['title']);
        if ($start===null) { $start=$pos; $first_card=$html; }
        elseif (!preg_match('~^(?:\s|<!--(?:(?!-->).)*-->)*$~sD',substr($listing,$end,$pos-$end))) {
            throw new Exception($j['listing'].' — before card '.($index+1).' ('.$name.'): intervening markup or PHP separates the selected wrappers. Check that the listing contains adjacent outer card wrappers.');
        }
        $end=$pos+strlen($html);
        $slug=sanitize_title($name);
        if (isset($rows[$slug])) {
            if ($rows[$slug]['title']!==$name) throw new Exception('Conflicting service titles: '.$name);
            continue;
        }
        $image=trim($c['image']??'');
        if ($image && !preg_match('~^https?://~i',$image)) {
            $path=pl_target(ltrim($image,'/'));
            if (!is_file($path)) throw new Exception('Theme image unavailable: '.$image);
            $image=trailingslashit(get_stylesheet_directory_uri()).ltrim($image,'/');
        }
        $rows[$slug]=['title'=>$name,'excerpt'=>sanitize_textarea_field($c['excerpt']??''),'content'=>'','image'=>esc_url_raw($image,['http','https']),'sections'=>[],'existing'=>pl_services_existing($type,$name)];
    }
    $required=['title','link'];
    if (!empty($cards[0]['image'])) $required[]='image';
    $card=pl_services_bind($first_card,$data['card_bindings']??[],array_intersect_key($expr,array_flip(['title','excerpt','link','image'])),$required);
    $loop='<?php $pl_services = new WP_Query('.var_export(['post_type'=>$type,'post_status'=>'publish','posts_per_page'=>-1,'orderby'=>['menu_order'=>'ASC','ID'=>'ASC'],'no_found_rows'=>true],true).'); if ($pl_services->have_posts()) : while ($pl_services->have_posts()) : $pl_services->the_post(); ?>'.$card.'<?php endwhile; wp_reset_postdata(); else : ?>'.substr($listing,$start,$end-$start).'<?php endif; ?>';
    $list_after=substr($listing,0,$start).$loop.substr($listing,$end);
    token_get_all($list_after,TOKEN_PARSE);
    $single=pl_services_bind($detail,$data['detail_bindings']??[],$expr,array_merge(['title','content'],array_keys($labels)));
    $single=preg_replace('/^(\s*\*?\s*)Template Name\s*:/mi','$1Source template:', $single);
    $j['rows']=array_values($rows); $j['labels']=$labels;
    $j['detail_hash']=hash('sha256',$detail);
    $j['writes']=[$j['listing']=>['before'=>$listing,'after'=>$list_after],$target=>['before'=>$prior,'after'=>$single]];
    unset($j['inventory'],$j['listing_source']);
    $j['stage']=pl_services_pending_copy($j) ? 'copy' : 'preview';
}

add_action('acf/init',function(){
    foreach ((array)get_option('pl_service_fields',[]) as $type=>$labels) {
        if (!$labels) continue;
        $fields=[];
        foreach ($labels as $key=>$label) $fields[]=['key'=>'field_pl_s_'.md5($type.'/'.$key),'name'=>'pl_'.$key,'label'=>$label,'type'=>'textarea'];
        acf_add_local_field_group(['key'=>'group_pl_s_'.md5($type),'title'=>'Service detail sections','fields'=>$fields,'location'=>[[['param'=>'post_type','operator'=>'==','value'=>$type]]]]);
    }
});

function pl_services_apply($j) {
    $page=$j['page']; $type=$j['type'];
    if (!current_user_can('edit_post',$page) || get_post_type($page)!=='page' || get_page_template_slug($page)!==$j['assigned']) throw new Exception('Listing page changed. Prepare a fresh preview.');
    if (hash('sha256',patchloom_file_source($j,$j['detail']))!==$j['detail_hash']) throw new Exception('Detail source changed. Prepare a fresh preview.');
    foreach ($j['writes'] as $rel=>$w) {
        $path=pl_target($rel);
        if ((is_file($path)?file_get_contents($path):null)!==$w['before']) throw new Exception('Destination changed: '.$rel);
    }
    if (post_type_exists($type)=== $j['create']) throw new Exception('Post type registration changed. Preview again.');
    if ($j['create'] && (get_page_by_path('service') || taxonomy_exists('service'))) throw new Exception('The service rewrite base is already in use.');
    foreach ($j['rows'] as $row) if (pl_services_existing($type,$row['title'])!==$row['existing']) throw new Exception('Existing service changed: '.$row['title']);
    $created=[]; $written=[]; $registered=false; $assigned=false; $fields_saved=false;
    $old_fields=get_option('pl_service_fields',[]);
    try {
        if ($j['create']) {
            if (!add_option('pl_services_owned',1,'','no')) throw new Exception('Post type registration conflict.');
            $registered=true; pl_services_register();
        }
        $object=get_post_type_object($type);
        if (!$object || !current_user_can($object->cap->create_posts)) throw new Exception('Service creation permission is required.');
        foreach ($j['rows'] as $index=>$row) {
            if ($row['existing']) continue;
            $id=wp_insert_post(wp_slash(['post_type'=>$type,'post_status'=>'draft','post_title'=>$row['title'],'post_content'=>$row['content'],'post_excerpt'=>$row['excerpt'],'menu_order'=>$index]),true);
            if (is_wp_error($id) || !$id) throw new Exception('Could not create draft: '.$row['title']);
            $created[]=$id;
            $meta=[];
            if ($row['image']) {
                $meta['_pl_service_image']=$row['image'];
                $attachment=attachment_url_to_postid($row['image']);
                if ($attachment) $meta['_thumbnail_id']=(string)$attachment;
            }
            foreach ($row['sections'] as $key=>$value) $meta['pl_'.$key]=$value;
            foreach ($meta as $key=>$value) {
                update_post_meta($id,$key,wp_slash($value));
                if (get_post_meta($id,$key,true)!==$value) throw new Exception('Could not save '.$key);
            }
        }
        if ($j['labels']) {
            $fields=$old_fields;
            $fields[$type]=array_merge($fields[$type]??[],$j['labels']);
            if ($fields!==$old_fields && !update_option('pl_service_fields',$fields,false)) throw new Exception('Could not save detail field mappings.');
            $fields_saved=$fields!==$old_fields;
        }
        foreach ($j['writes'] as $rel=>$w) {
            pl_write_bytes(pl_target($rel),$w['after']); $written[]=$rel;
            if (file_get_contents(pl_target($rel))!==$w['after']) throw new Exception('Could not verify '.$rel);
        }
        if ($j['assign'] && $j['listing']!==$j['assigned']) {
            update_post_meta($page,'_wp_page_template',$j['listing']);
            $assigned=true;
            if (get_page_template_slug($page)!==$j['listing']) throw new Exception('Could not assign listing template.');
        }
        if ($registered) flush_rewrite_rules(false);
        wp_clean_themes_cache();
    } catch (Throwable $e) {
        if ($assigned) update_post_meta($page,'_wp_page_template',$j['assigned'] ?: 'default');
        foreach (array_reverse($written) as $rel) {
            $before=$j['writes'][$rel]['before'];
            if ($before===null) unlink(pl_target($rel)); else pl_write_bytes(pl_target($rel),$before);
        }
        foreach ($created as $id) wp_delete_post($id,true);
        if ($fields_saved) update_option('pl_service_fields',$old_fields,false);
        if ($registered) { delete_option('pl_services_owned'); unregister_post_type('service'); flush_rewrite_rules(false); }
        throw $e;
    }
    return count($created);
}

function pl_services_action($act) {
    $j=get_transient(pl_services_key());
    if (!$j || !hash_equals($j['token'],sanitize_text_field($_POST['services_token']??''))) throw new Exception('Services preview expired.');
    if ($act==='services_cancel') { delete_transient(pl_services_key()); return; }
    if (!current_user_can('manage_options') || !current_user_can('edit_themes') || !wp_is_file_mod_allowed('patchloom_work') || $j['theme']!==get_stylesheet()) throw new Exception('Theme changed or template editing unavailable.');
    $lock='pl_lock_'.md5(get_stylesheet());
    if (!add_option($lock,time(),'','no')) throw new Exception('Another template operation is in progress.');
    try {
        if ($act==='services_prepare' && $j['stage']==='select') {
            $j['page']=absint($_POST['services_page']??0);
            if (get_post_type($j['page'])!=='page' || !current_user_can('edit_post',$j['page'])) throw new Exception('Choose an editable listing page.');
            $j['assigned']=get_page_template_slug($j['page']);
            $j['listing']=sanitize_text_field(wp_unslash($_POST['services_listing']??'')) ?: $j['assigned'];
            $j['detail']=sanitize_text_field(wp_unslash($_POST['services_detail']??''));
            foreach ([$j['listing'],$j['detail']] as $file) if (!is_string($file) || substr($file,-4)!=='.php' || !isset(patchloom_theme_catalog()[$file])) throw new Exception('Choose PHP templates from the active theme.');
            $selected=sanitize_key($_POST['services_type']??'');
            $j['create']=$selected==='new_service';
            $j['type']=$j['create']?'service':$selected;
            if ($j['create'] ? post_type_exists('service') : !isset(pl_services_types()[$selected])) throw new Exception('Select an available services post type.');
            $source=patchloom_file_source($j,$j['listing']);
            $j['assign']=preg_match('/Template Name\s*:/i',$source)===1;
            $hierarchy=['page-'.$j['page'].'.php','page-'.get_post_field('post_name',$j['page']).'.php'];
            if (!$j['assign'] && ($j['assigned'] || !in_array($j['listing'],$hierarchy,true))) throw new Exception('Select a named page template or this page’s specific page-slug.php / page-ID.php template.');
            pl_services_inventory($j); pl_services_store($j);
        } elseif ($act==='services_next' && in_array($j['stage'],['detail','copy'],true)) {
            if ($j['stage']==='detail') pl_services_prepare($j); else pl_services_copy_step($j);
            pl_services_store($j);
        } elseif ($act==='services_apply' && $j['stage']==='preview') {
            $count=pl_services_apply($j);
            delete_transient(pl_services_key());
            echo '<p>'.(int)$count.' service drafts created. Templates saved. <a href="'.esc_url(admin_url('edit.php?post_type='.$j['type'])).'">Review services</a></p>';
        } else throw new Exception('Action does not match the current services preview.');
    } finally { delete_option($lock); }
}

function pl_services_controls() {
    $j=get_transient(pl_services_key());
    if (!$j) return;
    echo '<form method="post"><h3>Services migration</h3>';
    wp_nonce_field('arv_admin');
    echo '<input type="hidden" name="services_token" value="'.esc_attr($j['token']).'">';
    if ($j['stage']==='select') {
        echo '<p><label>Services listing page <select required name="services_page"><option value="">Select page</option>';
        foreach (get_pages(['post_status'=>['draft','publish']]) as $page) if (current_user_can('edit_post',$page->ID)) echo '<option value="'.(int)$page->ID.'">'.esc_html($page->post_title).'</option>';
        echo '</select></label></p><p><label>Service post type <select required name="services_type"><option value="">Choose existing or create</option>';
        foreach (pl_services_types() as $type) echo '<option value="'.esc_attr($type->name).'">'.esc_html($type->labels->name.' ('.$type->name.')').'</option>';
        if (!post_type_exists('service')) echo '<option value="new_service">Create service</option>';
        echo '</select></label></p>';
        $paths=array_keys(patchloom_theme_catalog());
        foreach (['listing'=>'Listing template','detail'=>'Existing detail template'] as $key=>$label) {
            echo '<p><label>'.esc_html($label).' <select name="services_'.esc_attr($key).'"><option value="">'.($key==='listing'?'Use assigned template':'Select template').'</option>';
            foreach ($paths as $path) if (substr($path,-4)==='.php') echo '<option>'.esc_html($path).'</option>';
            echo '</select></label></p>';
        }
        echo '<p>Arena will receive both PHP templates and your request to prepare the draft copy and mappings.</p><button name="arv_action" value="services_prepare" class="button button-primary">Allow read and prepare preview</button> ';
    } elseif (in_array($j['stage'],['detail','copy'],true)) {
        $pending=$j['stage']==='copy'?pl_services_pending_copy($j):[];
        $next=$pending ? reset($pending) : null;
        $status=$next ? 'Preparing copy for '.$next['title'].' ('.count($pending).' remaining)' : 'Preparing detail template mappings';
        echo '<p>'.esc_html($status).'</p><button class="button button-primary" name="arv_action" value="services_next">Continue preview</button> ';
    } else {
        echo '<p>'.esc_html(($j['create']?'Create ':'Reuse ').$j['type'].' · '.count($j['rows']).' unique services · Listing page: '.get_the_title($j['page']).' · Detail source: '.$j['detail']).'</p>';
        echo '<p>The listing keeps its static cards until a service is published, then shows published services in menu order. New drafts appear after publication. Existing matches keep their content and order.</p>';
        foreach ($j['rows'] as $r) {
            echo '<details><summary>'.esc_html($r['title'].($r['existing']?' — reuse #'.$r['existing']:' — new draft')).'</summary>';
            if (!$r['existing']) {
                echo '<p>'.esc_html($r['image']).'</p><p>'.esc_html($r['excerpt']).'</p><pre>'.esc_html($r['content']).'</pre>';
                foreach ($r['sections'] as $k=>$v) echo '<p>'.esc_html($j['labels'][$k].' (pl_'.$k.'): '.$v).'</p>';
            }
            echo '</details>';
        }
        foreach ($j['writes'] as $rel=>$w) echo '<details><summary>'.esc_html($rel).'</summary><h4>Current</h4><pre>'.esc_html($w['before']??'(new file)').'</pre><h4>Proposed</h4><pre>'.esc_html($w['after']).'</pre></details>';
        echo '<button name="arv_action" value="services_apply" class="button button-primary">Approve drafts and templates</button> ';
    }
    echo '<button class="button" name="arv_action" value="services_cancel" formnovalidate>Cancel</button></form>';
}
