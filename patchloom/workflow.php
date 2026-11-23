<?php
defined('ABSPATH') || exit;

function pl_work_key() { return 'patchloom_work_'.get_current_user_id(); }
function pl_store_work($job) {
    $job['token']=wp_generate_uuid4();
    set_transient(pl_work_key(),$job,HOUR_IN_SECONDS);
}
function pl_work_start($request) {
    $woo=preg_match('/woocommerce|products?|books?/i',$request) && preg_match('/csv|import|archive|single\.php/i',$request);
    $acf=stripos($request,'acf')!==false && preg_match('/template|home\.php|homepage|home page|json/i',$request);
    if (!$woo && !$acf) return null;
    if (!current_user_can('edit_themes')) throw new Exception('Theme editor permission required.');
    $only=$woo && !$acf && !preg_match('/archive|single\.php|dynamic|dynamize|override/i',$request);
    pl_store_work(['only'=>$only,'stage'=>'select','theme'=>get_stylesheet(),'woo'=>(bool)$woo,'acf'=>(bool)$acf,'request'=>$request]);
    if ($only) return 'Review the product source files and approve reading them to prepare the CSV.';
    return 'Select the existing templates below and approve reading them. Home fields belong to the selected WordPress page.';
}
function pl_require_work($j) {
    if (!$j || $j['theme']!==get_stylesheet() || !current_user_can('manage_options') || !current_user_can('edit_themes') || !wp_is_file_mod_allowed('patchloom_work')) throw new Exception('Theme changed, job expired, or file editing disabled.');
    if ($j['acf'] && !function_exists('acf_import_field_group')) throw new Exception('Activate Advanced Custom Fields.');
    if ($j['woo'] && (!class_exists('WC_Product_Simple') || !current_user_can('publish_products'))) throw new Exception('Activate WooCommerce and grant product publishing permission.');
    if (isset($j['page']) && (!current_user_can('edit_post',$j['page']) || get_post_type($j['page'])!=='page')) throw new Exception('Home page is unavailable.');
}
function pl_work_files($j) {
    $files=[];
    foreach ($j['paths'] as $role=>$path) $files[$role]=patchloom_file_source($j,$path);
    return $files;
}
function pl_roles($j) {
    if (!empty($j['only'])) return [];
    $roles=['home'=>'Home template'];
    if ($j['woo']) $roles+=['archive'=>'Static listing template','single'=>'Static detail template'];
    return $roles;
}
function pl_product_match($row) {
    $sku=$row['sku']!==''?wc_get_product_id_by_sku($row['sku']):0;
    $ids=get_posts(['post_type'=>'product','post_status'=>['publish','draft','private','pending'],'title'=>$row['name'],'fields'=>'ids','posts_per_page'=>2]);
    if (count($ids)>1 || ($sku && $ids && $sku!=(int)$ids[0])) throw new Exception('Ambiguous existing product: '.$row['name']);
    $id=$sku ?: ($ids[0]??0);
    if ($id && $row['sku']!=='' && wc_get_product($id)->get_sku()!==$row['sku']) throw new Exception('SKU conflict: '.$row['name']);
    return (int)$id;
}
function pl_inventory($j,$files) {
    $answer=arv_ask('Extract all distinct actual products from these existing templates. Merge identical products across sources. Return {"products":[{"name":"literal title","sku":"","price":"","description":"","image":""}],"issues":[]}. Empty strings for missing facts. Price must be a decimal from the source. Image must be an existing absolute attachment URL or theme-relative asset filename. Report conflicting values, variants and unresolved product includes under issues. Never invent data.', ['files'=>$files]);
    if (!empty($answer['issues'])) throw new Exception('Resolve source inventory: '.wp_json_encode($answer['issues']));
    if (empty($answer['products']) || !is_array($answer['products'])) throw new Exception('No static products found.');
    $rows=[];
    foreach ($answer['products'] as $item) {
        $r=[];
        foreach (['name','sku','price','description','image'] as $k) $r[$k]=sanitize_text_field($item[$k]??'');
        if (!$r['name'] || ($r['price']!=='' && !preg_match('/^\d+(\.\d+)?$/D',$r['price']))) throw new Exception('Invalid product name or price.');
        if (isset($rows[strtolower($r['name'])])) throw new Exception('Repeated title: '.$r['name']);
        $r['existing']=pl_product_match($r);
        $rows[strtolower($r['name'])]=$r;
    }
    return array_values($rows);
}
function pl_product_image($image,$product_id) {
    if (!$image) return 0;
    $id=attachment_url_to_postid($image);
    if ($id) return $id;
    $uri=trailingslashit(get_stylesheet_directory_uri());
    if (strpos($image,$uri)===0) $image=substr($image,strlen($uri));
    $root=realpath(get_stylesheet_directory());
    $path=realpath($root.'/'.ltrim($image,'/'));
    if (!$path || strpos($path,$root.DIRECTORY_SEPARATOR)!==0 || !is_file($path) || !wp_get_image_mime($path)) throw new Exception('Image unavailable in media library or theme: '.$image);
    require_once ABSPATH.'wp-admin/includes/file.php';
    require_once ABSPATH.'wp-admin/includes/media.php';
    require_once ABSPATH.'wp-admin/includes/image.php';
    $tmp=wp_tempnam(basename($path));
    if (!$tmp || !copy($path,$tmp)) throw new Exception('Cannot copy product image.');
    try {
        $id=media_handle_sideload(['name'=>basename($path),'tmp_name'=>$tmp],$product_id);
        if (is_wp_error($id)) throw new Exception($id->get_error_message());
        return $id;
    } finally {if (is_file($tmp)) unlink($tmp);}
}
function pl_import_rows(&$j) {
    foreach ($j['rows'] as &$r) {
        $id=pl_product_match($r);
        if (!$id) {
            $p=new WC_Product_Simple();
            $p->set_name($r['name']); $p->set_sku($r['sku']); $p->set_regular_price($r['price']);
            $p->set_description($r['description']); $p->set_status('draft');
            $id=$p->save();
            try {
                $p->set_image_id(pl_product_image($r['image'],$id));
                $p->set_status($r['price']===''?'draft':'publish');
                $p->save();
            } catch (Throwable $e) {wp_delete_post($id,true); throw $e;}
        }
        $r['id']=$id;
    }
}
function pl_target($relative) {
    $root=realpath(get_stylesheet_directory());
    if (!is_string($relative) || preg_match('~(^/|\\\\|(?:^|/)\.\.?(/|$)|:)~',$relative)) throw new Exception('Invalid theme path.');
    $path=$root.'/'.$relative;
    for ($p=$path;$p!==$root;$p=dirname($p)) if (is_link($p)) throw new Exception('Symbolic links cannot be modified.');
    return $path;
}
function pl_prepare_templates(&$j,$files) {
    $inventory=[];
    foreach (($j['rows']??[]) as $r) $inventory[$r['id']]=$r['name'];
    $j['writes']=[]; $j['fields']=[];
    foreach (pl_roles($j) as $role=>$label) {
        $src=$files[$role];
        $instruction='Return JSON {"edits":[{"old":"exact unique source fragment","new":"replacement PHP and HTML"}],"fields":[{"name":"unique_snake_case","label":"field label","type":"text|textarea|url","value":"original literal"}]}. Preserve all untouched source, classes, wrappers, scripts, layout, surrounding PHP and section order. Never add unrelated behavior. Return {"issue":"reason"} if incomplete. Do not repeat template boilerplate unnecessarily. ';
        if ($role==='home' && $j['acf']) $instruction.='Convert ALL static nonproduct page content including headings, paragraphs, labels, button URLs and image URLs/alt into editable ACF fields. For each field use a distinct name starting pl_home_. Render with get_field(field_name, PAGE_ID), correctly escaped, using the supplied numeric page ID. Seed original values. For theme images return theme-relative asset URL in field value and construct URL with get_stylesheet_directory_uri when rendering. Preserve existing dynamic fields. For repeated non-product cards use an ACF Pro repeater when enabled: type repeater, sub_fields containing name,label,type and value as an array of row objects keyed by subfield name. Use have_rows/the_row/get_sub_field for that content. ';
        if ($j['woo']) {
            $instruction.=['home'=>'Replace static product cards with WooCommerce products using supplied verified IDs, preserve each section selection and order.','archive'=>'Adapt this listing into woocommerce/archive-product.php using the WooCommerce main query, standard pagination and product links.','single'=>'Adapt this detail page into woocommerce/single-product.php using current queried product, dynamic images, title, price, description and standard WooCommerce add-to-cart for its type.'][$role];
        }
        $answer=arv_ask($instruction,['request'=>$j['request'],'page_id'=>$j['page'],'products'=>$inventory,'acf_repeater_available'=>function_exists('acf_get_field_type') && (bool)acf_get_field_type('repeater'),'source'=>$src],true,12000);
        if (!empty($answer['issue'])) throw new Exception((string)$answer['issue']);
        if (empty($answer['edits']) || !is_array($answer['edits'])) throw new Exception('No usable edits for '.$label);
        $changes=[];
        foreach ($answer['edits'] as $edit) {
            $old=$edit['old']??null; $new=$edit['new']??null;
            if (!is_string($old)||!is_string($new)||$old===''||substr_count($src,$old)!==1) throw new Exception('Source fragment could not be uniquely matched in '.$label);
            $changes[]=[strpos($src,$old),strlen($old),$new];
        }
        usort($changes,function($a,$b){return $b[0]<=>$a[0];});
        $end=strlen($src); $output=$src;
        foreach ($changes as [$pos,$len,$replacement]) {
            if ($pos+$len>$end) throw new Exception('Overlapping changes in '.$label);
            $output=substr_replace($output,$replacement,$pos,$len); $end=$pos;
        }
        if ($role==='home' && $j['acf']) {
            if (empty($answer['fields'])) throw new Exception('No Home fields supplied.');
            foreach ($answer['fields'] as $f) {
                $name=$f['name']??'';
                if (($f['type']??'')==='repeater') {
                    if (!acf_get_field_type('repeater') || !preg_match('/^pl_home_[a-z0-9_]+$/D',$name) || isset($j['fields'][$name]) || strpos($output,$name)===false || empty($f['sub_fields']) || empty($f['value']) || metadata_exists('post',$j['page'],$name)) throw new Exception('Invalid or unavailable repeater: '.$name);
                    $subs=[]; $rows=[];
                    foreach ($f['sub_fields'] as $sub) {
                        $n=$sub['name']??'';
                        if (!preg_match('/^[a-z][a-z0-9_]*$/D',$n) || isset($subs[$n]) || !in_array($sub['type']??'',['text','textarea','url'],true)) throw new Exception('Invalid repeater column.');
                        $subs[$n]=['key'=>'field_pl_'.md5($j['page'].'/'.$name.'/'.$n),'name'=>$n,'label'=>sanitize_text_field($sub['label']??$n),'type'=>$sub['type']];
                    }
                    foreach ($f['value'] as $row) {
                        $data=[];
                        foreach ($subs as $n=>$sub) {
                            if (!isset($row[$n]) || !is_string($row[$n])) throw new Exception('Incomplete repeater row.');
                            $data[$sub['key']]=$row[$n];
                        }
                        $rows[]=$data;
                    }
                    $j['fields'][$name]=['key'=>'field_pl_'.md5($j['page'].'/'.$name),'name'=>$name,'label'=>sanitize_text_field($f['label']??$name),'type'=>'repeater','sub_fields'=>array_values($subs),'layout'=>'block','seed'=>$rows];
                    continue;
                }
                if (!preg_match('/^pl_home_[a-z0-9_]+$/D',$name)||!in_array($f['type']??'',['text','textarea','url'],true)||!is_string($f['value']??null)||isset($j['fields'][$name])||strpos($output,$name)===false) throw new Exception('Invalid or unused Home field.');
                if (metadata_exists('post',$j['page'],$name)) throw new Exception('Home field already exists: '.$name);
                $j['fields'][$name]=['key'=>'field_pl_'.md5($j['page'].'/'.$name),'name'=>$name,'label'=>sanitize_text_field($f['label']??$name),'type'=>$f['type'],'seed'=>$f['value']];
            }
            if (!preg_match('/Template Name\s*:/i',$output)) $output="<?php /* Template Name: PatchLoom Home */ ?>\n".$output;
        }
        token_get_all($output,TOKEN_PARSE);
        $dest=$role==='home'?$j['paths']['home']:'woocommerce/'.($role==='archive'?'archive-product':'single-product').'.php';
        $path=pl_target($dest);
        $before=is_file($path)?file_get_contents($path):null;
        if ($before===false) throw new Exception('Cannot read '.$dest);
        $j['writes'][$dest]=['before'=>$before,'after'=>$output];
    }
    if ($j['acf']) {
        $fields=[];
        foreach ($j['fields'] as $f) {unset($f['seed']);$fields[]=$f;}
        $j['group']=['key'=>'group_pl_home_'.$j['page'],'title'=>'Home page content','fields'=>$fields,'active'=>true,'location'=>[[['param'=>'page_template','operator'=>'==','value'=>$j['paths']['home']],['param'=>'page','operator'=>'==','value'=>(string)$j['page']]]]];
        $json='acf-json/'.$j['group']['key'].'.json';
        if (file_exists(pl_target($json)) || acf_get_field_group($j['group']['key'])) throw new Exception('Home field group already exists.');
        $j['writes'][$json]=['before'=>null,'after'=>wp_json_encode($j['group'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)];
    }
}
function pl_write_bytes($path,$bytes) {
    if (!wp_mkdir_p(dirname($path))) throw new Exception('Cannot create template directory.');
    $tmp=tempnam(dirname($path),'.patchloom-');
    if (!$tmp) throw new Exception('Cannot create temporary file.');
    try {
        if (file_put_contents($tmp,$bytes)!==strlen($bytes)) throw new Exception('Incomplete template write.');
        chmod($tmp,file_exists($path)?(fileperms($path)&0777):0644);
        if (!rename($tmp,$path)) throw new Exception('Cannot replace template.');
    } finally {if (is_file($tmp)) unlink($tmp);}
}
function pl_apply_work($j) {
    $page=$j['page']; $assigned=get_post_meta($page,'_wp_page_template',true);
    foreach ($j['writes'] as $rel=>$w) {
        $path=pl_target($rel);
        if ((is_file($path)?file_get_contents($path):null)!==$w['before']) throw new Exception('Destination changed: '.$rel);
    }
    $done=$seeded=[]; $gid=0;
    try {
        if ($j['acf']) {
            if (acf_get_field_group($j['group']['key'])) throw new Exception('Group already exists. Preview again.');
            $group=acf_import_field_group($j['group']); $gid=(int)($group['ID']??0);
            if (!$gid) throw new Exception('ACF group import failed.');
            foreach ($j['fields'] as $f) {
                if (metadata_exists('post',$page,$f['name'])) throw new Exception('Existing value conflict: '.$f['name']);
                $seeded[]=$f['name'];
                update_field($f['key'],$f['seed'],$page);
                if ((string)get_post_meta($page,$f['name'],true)!==($f['type']==='repeater'?(string)count($f['seed']):$f['seed'])) throw new Exception('Cannot verify '.$f['name']);
            }
        }
        foreach ($j['writes'] as $rel=>$w) {
            $path=pl_target($rel);
            pl_write_bytes($path,$w['after']); $done[]=$rel;
            if (file_get_contents($path)!==$w['after']) throw new Exception('File verification failed.');
        }
        if ($j['acf']) {
            update_post_meta($page,'_wp_page_template',$j['paths']['home']);
            if (get_page_template_slug($page)!==$j['paths']['home']) throw new Exception('Template assignment failed.');
        }
        if ($j['woo']) set_theme_mod('patchloom_wc_templates',true);
        wp_clean_themes_cache();
    } catch (Throwable $e) {
        foreach (array_reverse($done) as $rel) {
            $path=pl_target($rel); $old=$j['writes'][$rel]['before'];
            if ($old===null) unlink($path); else pl_write_bytes($path,$old);
        }
        foreach ($seeded as $name) {delete_field($j['fields'][$name]['key'],$page);delete_post_meta($page,$name);delete_post_meta($page,'_'.$name);}
        if ($gid) acf_delete_field_group($gid);
        if ($j['acf']) update_post_meta($page,'_wp_page_template',$assigned);
        throw $e;
    }
}
add_action('after_setup_theme',function(){if (get_theme_mod('patchloom_wc_templates')) add_theme_support('woocommerce');},20);

function pl_work_action($act) {
    $key=pl_work_key(); $j=get_transient($key);
    pl_require_work($j);
    if (!hash_equals($j['token'],sanitize_text_field($_POST['work_token']??''))) throw new Exception('Preview expired. Reload the page.');
    if ($act==='work_cancel') {delete_transient($key);return;}
    $lock='pl_lock_'.md5(get_stylesheet());
    if (!add_option($lock,time(),'','no')) throw new Exception('Another template operation is in progress.');
    try {
        if ($act==='work_read' && $j['stage']==='select') {
            $j['paths']=[];
            if (empty($j['only'])) $j['page']=absint($_POST['home_page']??0); pl_require_work($j);
            foreach (pl_roles($j) as $role=>$label) $j['paths'][$role]=sanitize_text_field(wp_unslash($_POST['path_'.$role]??''));
            if (count(array_unique($j['paths']))!==count($j['paths'])) throw new Exception('Select separate source templates.');
            foreach ($j['paths'] as $path) if (substr($path,-4)!=='.php') throw new Exception('Select PHP templates.');
            foreach ((array)($_POST['inventory_parts']??[]) as $i=>$part) {
                $part=sanitize_text_field(wp_unslash($part));
                if (!in_array($part,$j['paths'],true)) $j['paths']['part_'.absint($i)]=$part;
            }
            if (empty($j['paths'])) throw new Exception('Select at least one product source file.');
            $files=pl_work_files($j);
            if (array_sum(array_map('strlen',$files))>240000) throw new Exception('Selected files exceed the 240 KB migration context.');
            $j['hashes']=array_map(function($s){return hash('sha256',$s);},$files);
            if ($j['woo']) { $j['rows']=pl_inventory($j,$files); $j['stage']='inventory'; }
            else {pl_prepare_templates($j,$files); $j['stage']='preview';}
        } else {
            $files=pl_work_files($j);
            foreach ($files as $role=>$s) if (hash('sha256',$s)!==$j['hashes'][$role]) throw new Exception('Source changed. Start a new preview.');
            if ($act==='work_import' && $j['stage']==='inventory') {
                pl_import_rows($j);
                if (!empty($j['only'])) {
                    delete_transient($key);
                    echo '<p>Import complete. '.count($j['rows']).' product rows processed. <a href="'.esc_url(admin_url('edit.php?post_type=product')).'">View products</a></p>';
                    return;
                }
                $j['stage']='imported';
            } elseif ($act==='work_plan' && $j['stage']==='imported') {
                pl_prepare_templates($j,$files); $j['stage']='preview';
            } elseif ($act==='work_apply' && $j['stage']==='preview') {
                pl_apply_work($j); delete_transient($key);
                echo '<p>Approved files saved and verified. <a href="'.esc_url(get_permalink($j['page'])).'">Review Home</a></p>';return;
            } else throw new Exception('Action does not match this preview.');
        }
        pl_store_work($j);
    } finally {delete_option($lock);}
}
add_action('admin_post_patchloom_csv',function(){
    $j=get_transient(pl_work_key()); pl_require_work($j);
    check_admin_referer('pl_csv_'.$j['token']);
    if (empty($j['rows'])) wp_die('Inventory unavailable.');
    nocache_headers(); header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=products.csv');
    $out=fopen('php://output','w');
    fputcsv($out,['Type','SKU','Name','Published','Description','Regular price','Images']);
    foreach ($j['rows'] as $r) {
        $img=$r['image'];
        if ($img && !preg_match('~^https?://~i',$img)) $img=trailingslashit(get_stylesheet_directory_uri()).ltrim($img,'/');
        fputcsv($out,['simple',$r['sku'],$r['name'],$r['price']===''?-1:1,$r['description'],$r['price'],$img]);
    }
    fclose($out);exit;
});
function pl_work_controls() {
    $j=get_transient(pl_work_key());
    if (!$j) return;
    $only=!empty($j['only']) || ($j['stage']==='select' && !isset($j['only']) && $j['woo'] && !$j['acf'] && !preg_match('/archive|single\.php|dynamic|dynamize|override/i',$j['request']));
    if ($only && !isset($j['only'])) { $j['only']=true; set_transient(pl_work_key(),$j,HOUR_IN_SECONDS); }
    echo '<form method="post"><h3>'.($only?'Product CSV and import':'Existing template workflow').'</h3>';
    wp_nonce_field('arv_admin');
    echo '<input type="hidden" name="work_token" value="'.esc_attr($j['token']).'">';
    if ($j['stage']==='select') {
        if (!$only) {
        $pages=get_pages(['post_status'=>['draft','publish']]);
        echo '<p><label>Home page <select required name="home_page"><option value="">Choose page</option>';
        foreach ($pages as $page) echo '<option value="'.(int)$page->ID.'" '.selected($page->ID,get_option('page_on_front'),false).'>'.esc_html($page->post_title).'</option>';
        echo '</select></label></p>';
        }
        $paths=array_keys(patchloom_theme_catalog());
        foreach (pl_roles($j) as $role=>$label) {
            $default=['home'=>'home.php','archive'=>'books.php','single'=>'book detail.php'][$role];
            echo '<p><label>'.esc_html($label).' <select required name="path_'.esc_attr($role).'"><option value="">Select file</option>';
            foreach ($paths as $p) if (substr($p,-4)==='.php') echo '<option '.selected($default,$p,false).'>'.esc_html($p).'</option>';
            echo '</select></label></p>';
        }
        if ($j['woo']) {
            echo '<p><label>'.($only?'Product source files':'Additional product inventory sources (optional)').' <select multiple '.($only?'required':'').' name="inventory_parts[]">';
            preg_match_all('~[a-z0-9_./-]+\.php\b~i',$j['request'],$names);
            $chosen=[];
            foreach ($names[0] as $name) {
                if (in_array($name,$paths,true)) $chosen[]=$name;
                else {
                    $hits=array_filter($paths,function($p) use ($name){return basename($p)===$name;});
                    if (count($hits)===1) $chosen[]=reset($hits);
                }
            }
            foreach ($paths as $p) if (substr($p,-4)==='.php') echo '<option '.selected($only && in_array($p,$chosen,true),true,false).'>'.esc_html($p).'</option>';
            echo '</select></label></p>';
        }
        echo '<p>Selected file contents and your instructions will be sent to Arena. Choose the templates containing the actual markup.</p>';
        $action='work_read';$label='Allow read and prepare preview';
    } elseif ($j['stage']==='inventory') {
        echo '<table class="widefat"><tr><th>Product</th><th>SKU</th><th>Price</th><th>Action</th></tr>';
        foreach ($j['rows'] as $r) echo '<tr><td>'.esc_html($r['name']).'</td><td>'.esc_html($r['sku']).'</td><td>'.esc_html($r['price']).'</td><td>'.esc_html($r['existing']?'Reuse #'.$r['existing']:($r['price']===''?'Create draft':'Create published product')).'</td></tr>';
        echo '</table><p><a href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=patchloom_csv'),'pl_csv_'.$j['token'])).'">Download WooCommerce CSV</a></p>';
        $action='work_import';$label='Approve product import';
    } elseif ($j['stage']==='imported') {
        echo '<p>Product inventory imported or matched. Template changes require a separate preview.</p>';
        $action='work_plan';$label='Prepare template changes';
    } else {
        foreach ($j['writes'] as $rel=>$w) {
            echo '<details><summary>'.esc_html($rel).'</summary><h4>Current</h4><pre>'.esc_html($w['before']??'New file').'</pre><h4>Proposed</h4><pre>'.esc_html($w['after']).'</pre></details>';
        }
        foreach ($j['fields'] as $f) echo '<p>'.esc_html($f['label'].' → '.(is_array($f['seed'])?wp_json_encode($f['seed']):$f['seed'])).'</p>';
        $action='work_apply';$label='Approve fields and template writes';
    }
    echo '<button class="button button-primary" name="arv_action" value="'.esc_attr($action).'">'.esc_html($label).'</button> <button formnovalidate class="button" name="arv_action" value="work_cancel">Cancel</button></form>';
}
