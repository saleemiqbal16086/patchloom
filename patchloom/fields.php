<?php
defined('ABSPATH') || exit;

// Each entry identifies an editable leaf and the root object needed for conflict checks.
function arv_leaf(&$leaves,$label,$kind,$key,$path,$value) {
    if (!is_string($value) || strlen($value)>5000 || count($leaves)>=800) return;
    $leaves[]=['label'=>$label,'kind'=>$kind,'key'=>$key,'path'=>$path,'value'=>$value];
}
function arv_acf_leaves(&$leaves,$field,$value,$root,$path,$label) {
    if (in_array($field['type'],['text','textarea','url'],true)) {
        arv_leaf($leaves,$label,'acf',$root,$path,$value);
    } elseif ($field['type']==='repeater' && is_array($value)) {
        foreach ($value as $i=>$row) foreach ($field['sub_fields'] as $sub) {
            if (!is_array($row)) continue;
            $k=array_key_exists($sub['key'],$row)?$sub['key']:$sub['name'];
            if (isset($row[$k])) arv_acf_leaves($leaves,$sub,$row[$k],$root,array_merge($path,[$i,$k]),$label.' / '.($i+1).' / '.$sub['label']);
        }
    }
}
function arv_element_leaves(&$leaves,$nodes,$prefix=[]) {
    foreach ($nodes as $i=>$node) {
        if (!is_array($node)) continue;
        $base=array_merge($prefix,[$i]);
        foreach (['title','text','button_text','description_text'] as $k) {
            $v=$node['settings'][$k] ?? null;
            if (is_string($v) && strpos($v,'<')===false && empty($node['settings']['__dynamic__'][$k])) arv_leaf($leaves,($node['widgetType']??'element').' '.$k,'elementor','',array_merge($base,['settings',$k]),$v);
        }
        if (!empty($node['elements'])) arv_element_leaves($leaves,$node['elements'],array_merge($base,['elements']));
    }
}
function arv_target_value($id,$entry) {
    switch ($entry['kind']) {
        case 'acf':
            if (!function_exists('get_field')) throw new Exception('Activate ACF.');
            return get_field($entry['key'],$id,false);
        case 'elementor_source': return get_post_meta($id,'_elementor_data',true);
        case 'elementor':
            return json_decode(get_post_meta($id,'_elementor_data',true),true);
        case 'product':
            $product=wc_get_product($id);
            if (!$product) throw new Exception('Product unavailable.');
            return $product->get_regular_price('edit');
        case 'excerpt': return get_post_field('post_excerpt',$id,'raw');
        default: return get_post_field('post_content',$id,'raw');
    }
}
function arv_target_save($id,$entry,$value,$previous=null) {
    switch ($entry['kind']) {
        case 'acf': update_field($entry['key'],$value,$id); break;
        case 'elementor_source':
        case 'elementor':
            if ($entry['kind']==='elementor_source') {
                if (!update_post_meta($id,'_elementor_data',wp_slash($value),$previous)) throw new Exception('Elementor document changed or save failed. Prepare a fresh preview.');
            } else update_post_meta($id,'_elementor_data',wp_slash(wp_json_encode($value)));
            delete_post_meta($id,'_elementor_element_cache');
            if (class_exists('Elementor\\Plugin')) \Elementor\Plugin::$instance->files_manager->clear_cache();
            break;
        case 'product':
            $product=wc_get_product($id);
            $product->set_regular_price($value);
            $product->save();
            break;
        default:
            $result=wp_update_post(wp_slash(['ID'=>$id,($entry['kind']==='excerpt'?'post_excerpt':'post_content')=>$value]),true);
            if (is_wp_error($result)) throw new Exception($result->get_error_message());
    }
}
function arv_field_plan($request,$id) {
    $leaves=[];
    $owners=array_unique(array_merge([$id],get_post_meta($id,'_arv_owner',false)));
    foreach ($owners as $owner) {
        if (!$owner || !current_user_can('edit_post',$owner) || !function_exists('get_field_objects')) continue;
        $start=count($leaves);
        foreach ((array)get_field_objects($owner,false,true) as $field) {
            if (is_array($field)) arv_acf_leaves($leaves,$field,$field['value'],$field['key'],[],get_the_title($owner).' / '.$field['label']);
        }
        for ($i=$start;$i<count($leaves);$i++) $leaves[$i]['owner']=$owner;
    }
    $element=get_post_meta($id,'_elementor_edit_mode',true)==='builder';
    if ($element) arv_element_leaves($leaves,(array)json_decode(get_post_meta($id,'_elementor_data',true),true));
    if (function_exists('wc_get_product') && in_array(get_post_type($id),['product','product_variation'],true)) {
        $product=wc_get_product($id);
        if ($product && $product->is_type(['simple','variation'])) arv_leaf($leaves,'Regular price','product','',[],$product->get_regular_price('edit'));
    }
    if (!$leaves) return null;
    $options=[];
    foreach ($leaves as $i=>$leaf) $options[$i]=[$leaf['label'],$leaf['value']];
    if (strlen(wp_json_encode($options))>120000) throw new Exception('Too many fields on this page for one request.');
    $reply=arv_ask('Select one editable field for the ENTIRE request. Return {"index":0,"text":"replacement"}. If no field matches return {}. If several changes or unclear return {"question":"clarify"}.',['request'=>$request,'fields'=>$options]);
    if (isset($reply['question'])) throw new Exception((string)$reply['question']);
    if (!isset($reply['index'])) return null;
    $i=$reply['index'];
    $s=$reply['text']??null;
    if (!is_int($i) || !isset($leaves[$i]) || !is_string($s) || preg_match('/[<>\[\]]/',$s)) throw new Exception('Unsupported field change.');
    $entry=$leaves[$i];
    if ($entry['kind']==='product' && !preg_match('/^\d+(\.\d+)?$/D',$s)) throw new Exception('Enter a nonnegative decimal price.');
    $owner=$entry['owner']??$id;
    $before=arv_target_value($owner,$entry);
    $after=$before;
    $leaf=&$after;
    foreach ($entry['path'] as $k) $leaf=&$leaf[$k];
    if ($leaf!==$entry['value']) throw new Exception('Field changed. Preview again.');
    $leaf=$s;
    return ['id'=>$owner,'entry'=>$entry,'before'=>$before,'after'=>$after,'diff'=>[[$entry['label'].' — '.$entry['value'],$s]],'token'=>wp_generate_uuid4()];
}
