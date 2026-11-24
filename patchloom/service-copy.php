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

// Decode entities for recognition only. Keep original markup and offsets for the preview.
function pl_copy_normalize($text) {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/[\p{Z}\s]+/u', ' ', $text) ?? '');
}

function pl_copy_placeholder($text) {
    $text = strtolower(pl_copy_normalize($text));
    if (!preg_match('/^lor[eu]m ipsum\b/', $text)) return false;
    if (preg_match("/^lor[eu]m ipsum is simply dummy text of the printing and typesetting industry[.!]?(?: lorem ipsum has been the industry['’]s standard dummy text ever since the 1500s, when an unknown printer took a galley of type and scrambled it to make a type specimen book[.!]?)?$/u", $text)) return true;
    $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    static $latin = null;
    if ($latin === null) $latin = explode(' ', 'lorem lorum ipsum dolor sit amet consectetur adipiscing adipisicing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua enim ad minim veniam quis nostrud exercitation ullamco laboris nisi aliquip ex ea commodo consequat duis aute irure in reprehenderit voluptate velit esse cillum eu fugiat nulla pariatur excepteur sint occaecat cupidatat non proident sunt culpa qui officia deserunt mollit anim id est laborum elit eiusmodmod tempora incidunt adipisci architecto beatae vitae dicta explicabo nemo enim ipsam voluptatem quia voluptas aspernatur aut odit fugit consequuntur magni dolores eos ratione sequi nesciunt neque porro quisquam dolorem adipisci numquam eius modi tempora incidunt magnam aliquam quaerat pellentesque vestibulum imperdiet fusce donec quam viverra mauris luctus pulvinar facilisis sapien tincidunt cras nunc augue eget morbi quisque tellus gravida mattis vehicula odio tortor purus integer posuere varius nulla aliquet porttitor lacus accumsan at fermentum felis dignissim curabitur blandit habitant egestas praesent quis sed vel nisi erat leo augue suspendisse sagittis eleifend etiam ornare cursus duis a ac aenean phasellus est ligula risus ullamcorper dapibus lobortis maximus vulputate metus elementum bibendum dictum venenatis rutrum ultrices convallis turpis sociosqu hendrerit pretium mi neque massa fringilla consequat rhoncus urna sollicitudin feugiat euismod tristique volutpat nisl iaculis laoreet platea habitasse dictumst nam efficitur nec semper auctor diam nonummy nibh tincidunt ut laoreet dolore magna aliquam erat volutpat');
    return $words && !array_diff($words, $latin);
}

function pl_copy_fields($settings, $base, $heading, &$items, &$misses) {
    $body_keys = ['editor','description_text','description','text','content','tab_content','accordion_content','item_description','item_content','testimonial_content','box_description','item_text'];
    foreach ($settings as $key=>$value) {
        if ($key === '__dynamic__' || !empty($settings['__dynamic__'][$key])) continue;
        $path = array_merge($base, [$key]);
        if (is_array($value)) {
            if (is_int($key) || (isset($value[0]) && is_array($value[0]))) {
                $next_heading = $heading;
                foreach (['tab_title','item_title','title_text'] as $label) {
                    if (!empty($value[$label]) && is_string($value[$label])) {
                        $next_heading = wp_strip_all_tags($value[$label]);
                        break;
                    }
                }
                pl_copy_fields($value, $path, $next_heading, $items, $misses);
            }
        } elseif (is_string($value) && preg_match('/lor[eu]m\s+ipsum/i', pl_copy_normalize(wp_strip_all_tags($value)))) {
            $found = false;
            if (in_array($key, $body_keys, true) && !preg_match('/<(?:script|style|code|pre|textarea)\b|\[|\]/i', $value)) {
                foreach (wp_html_split($value) as $index=>$part) {
                    if (strpos($part,'<') !== false || !pl_copy_placeholder($part)) continue;
                    $items[] = ['heading'=>$heading,'path'=>$path,'index'=>$index,'text'=>$part];
                    $found = true;
                    if (count($items)>=8) return;
                }
            }
            if (!$found && count($misses)<5) $misses[] = implode('.', $path);
        }
        if (count($items)>=8) return;
    }
}

function pl_copy_collect($nodes, &$items, $heading, $prefix = [], &$misses = []) {
    foreach ($nodes as $i=>$node) {
        if (!is_array($node)) continue;
        $settings = $node['settings'] ?? [];
        $base = array_merge($prefix, [$i]);
        if (($node['widgetType'] ?? '')==='heading' && empty($settings['__dynamic__']['title']) && is_string($settings['title'] ?? null)) {
            $heading = wp_strip_all_tags($settings['title']);
        }
        $context = $heading;
        if (empty($settings['__dynamic__']['title_text']) && is_string($settings['title_text'] ?? null)) $context = wp_strip_all_tags($settings['title_text']) ?: $context;
        if (!empty($node['widgetType']) && is_array($settings)) pl_copy_fields($settings,array_merge($base,['settings']),$context,$items,$misses);
        if (count($items)>=8) return;
        if (!empty($node['elements']) && is_array($node['elements'])) pl_copy_collect($node['elements'],$items,$heading,array_merge($base,['elements']),$misses);
        if (count($items)>=8) return;
    }
}

function pl_copy_next($type, $request, $cursor, $slot, &$report = []) {
    global $wpdb;
    if (!isset(pl_copy_types()[$type])) throw new Exception('Choose a registered post type.');
    $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status='publish' AND post_password='' AND ID>%d ORDER BY ID LIMIT 40", $type, $cursor));
    if ($wpdb->last_error) throw new Exception('WordPress could not scan this post type. Please retry.');
    $report += ['posts'=>0,'documents'=>0,'misses'=>[]];
    foreach ($ids as $id) {
        if (!current_user_can('edit_post',$id)) continue;
        $report['posts']++;
        $entry = ['kind'=>'elementor_source'];
        $before = arv_target_value($id,$entry);
        $tree = json_decode($before,true);
        if (!$tree || !is_array($tree)) continue;
        $report['documents']++;
        $items = [];
        $title = get_the_title($id);
        $misses = [];
        pl_copy_collect($tree,$items,$title,[],$misses);
        if ($misses && count($report['misses'])<3) $report['misses'][] = $title.' (#'.$id.') '.implode(', ',$misses);
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
