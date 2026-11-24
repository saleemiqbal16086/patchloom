<?php
defined('ABSPATH') || exit;

function pl_copy_key() {
    return 'pl_copy_' . get_current_user_id();
}

function pl_copy_types() {
    return get_post_types(['show_ui' => true, '_builtin' => false], 'objects');
}

function pl_copy_matches($message) {
    return preg_match('/\b(?:lor[eu]m|placeholder)\b/i', $message)
        && preg_match('/elementor|service|post\s*type/i', $message);
}

// Replace complete placeholder text nodes only. Preserve markup and mixed editorial copy.
function pl_copy_collect($nodes, &$items, $heading, $prefix = []) {
    foreach ($nodes as $i => $node) {
        if (!is_array($node)) continue;
        $settings = $node['settings'] ?? [];
        $widget = $node['widgetType'] ?? '';
        $base = array_merge($prefix, [$i]);
        if ($widget === 'heading') {
            $title = empty($settings['__dynamic__']['title']) ? ($settings['title'] ?? '') : '';
            $heading = is_string($title) ? trim(wp_strip_all_tags($title)) : '';
        }
        $context = $heading;
        if (in_array($widget, ['icon-box','image-box'], true) && empty($settings['__dynamic__']['title_text'])) {
            $context = wp_strip_all_tags($settings['title_text'] ?? '') ?: $context;
        }
        $key = $widget === 'text-editor' ? 'editor' : 'description_text';
        $value = $settings[$key] ?? null;
        if (is_string($value) && empty($settings['__dynamic__'][$key]) && !preg_match('/<(?:script|style|code|pre|textarea)\b|\[|\]/i', $value)) {
            foreach (wp_html_split($value) as $index => $part) {
                if (preg_match('/^\s*lor[eu]m\s+ipsum\b/i', $part) && !preg_match('/[<>]/', $part)) {
                    // Recognize conventional filler vocabulary. Leave mixed or uncertain text for manual editing.
                    $words = preg_split('/[^a-z]+/i', strtolower(trim($part)), -1, PREG_SPLIT_NO_EMPTY);
                    $latin = explode(' ', 'lorem lorum ipsum dolor sit amet consectetur adipiscing adipisicing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua enim ad minim veniam quis nostrud exercitation ullamco laboris nisi aliquip ex ea commodo consequat duis aute irure in reprehenderit voluptate velit esse cillum eu fugiat nulla pariatur excepteur sint occaecat cupidatat non proident sunt culpa qui officia deserunt mollit anim id est laborum');
                    if (!$words || array_diff($words, $latin) || preg_match('/[^\x00-\x7F]/', trim($part))) continue;
                    $items[] = ['heading'=>$context, 'path'=>array_merge($base,['settings',$key]), 'index'=>$index, 'text'=>$part];
                    if (count($items) >= 8) return;
                }
            }
        }
        if (!empty($node['elements']) && is_array($node['elements'])) {
            pl_copy_collect($node['elements'], $items, $heading, array_merge($base,['elements']));
            if (count($items) >= 8) return;
        }
    }
}

function pl_copy_next($type, $request, $cursor, $slot) {
    global $wpdb;
    if (!isset(pl_copy_types()[$type])) throw new Exception('Choose a registered post type.');
    $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status='publish' AND post_password='' AND ID>%d ORDER BY ID LIMIT 40", $type, $cursor));
    foreach ($ids as $id) {
        if (!current_user_can('edit_post',$id) || get_post_meta($id,'_elementor_edit_mode',true) !== 'builder') continue;
        $entry = ['kind'=>'elementor_source'];
        $before = arv_target_value($id,$entry);
        $tree = json_decode($before,true);
        if (!is_array($tree)) continue;
        $items = [];
        $title = get_the_title($id);
        pl_copy_collect($tree,$items,$title);
        if (!$items) continue;
        $headings = array_column($items,'heading');
        $answer = arv_ask('Write one distinct, helpful service paragraph per supplied heading in order. Use the service title if a heading is empty. Follow the requested language, default to the language of the headings. Return {"paragraphs":["plain text"]}. Avoid unsupported claims about pricing, credentials, guarantees or outcomes.', compact('title','headings','request'));
        $paragraphs = $answer['paragraphs'] ?? null;
        if (!is_array($paragraphs) || count($items)!==count($paragraphs)) throw new Exception('Incomplete copy returned. Retry preview.');
        $document = json_decode($before);
        $diff = [];
        foreach ($items as $i=>$item) {
            $copy = $paragraphs[$i] ?? null;
            if (!is_string($copy) || trim($copy)==='' || strlen($copy)>5000 || preg_match('/[<>\[\]]|\blor[eu]m ipsum/i',$copy)) throw new Exception('Invalid paragraph. Retry preview.');
            $field = &$document;
            foreach ($item['path'] as $key) {
                if (is_object($field)) $field = &$field->{$key};
                else $field = &$field[$key];
            }
            $parts = wp_html_split($field);
            $parts[$item['index']] = preg_replace_callback('/^(\s*).*?(\s*)$/s', static function($m) use ($copy) { return $m[1].esc_html(trim($copy)).$m[2]; }, $item['text']);
            $field = implode('', $parts);
            unset($field);
            $diff[] = [$item['heading'].' — '.trim($item['text']),trim($copy)];
        }
        $after = wp_json_encode($document);
        if (!is_string($after)) throw new Exception('Could not prepare Elementor document.');
        set_transient($slot,['id'=>$id,'entry'=>$entry,'before'=>$before,'after'=>$after,'diff'=>$diff,'token'=>wp_generate_uuid4(),'copy_type'=>$type],HOUR_IN_SECONDS);
        // Revisit this post after apply in case it has more than eight placeholders.
        return ['cursor'=>((int)$id)-1,'preview'=>true];
    }
    return count($ids)===40 ? ['cursor'=>(int)end($ids)] : [];
}

function pl_copy_controls($slot) {
    $state = get_transient(pl_copy_key());
    if (!$state || !empty(get_transient($slot)['copy_type'])) return;
    echo '<form method="post"><h3>Elementor service copy</h3>';
    wp_nonce_field('arv_admin');
    if (empty($state['type'])) {
        echo '<label>Post type <select name="copy_type" required><option value="">Select post type</option>';
        foreach (pl_copy_types() as $type) echo '<option value="'.esc_attr($type->name).'">'.esc_html($type->labels->name.' ('.$type->name.')').'</option>';
        echo '</select></label> ';
    }
    echo '<p>Generate copy with Arena using the post title, headings and your instructions. Review one post at a time.</p><button class="button" name="arv_action" value="copy_next">Prepare next preview</button></form>';
}
