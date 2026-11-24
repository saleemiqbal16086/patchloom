<?php
define('ABSPATH',__DIR__);
define('HOUR_IN_SECONDS',3600);
require dirname(__DIR__).'/patchloom/service-copy.php';
function wp_html_split($s){return preg_split('/(<[^>]+>)/',$s,-1,PREG_SPLIT_DELIM_CAPTURE);}
function wp_strip_all_tags($s){return strip_tags($s);}
function get_post_types(){return ['service'=>(object)[]];}
function get_post_meta(){return 'builder';}
function current_user_can(){return true;}
function get_the_title(){return 'Roof repair';}
function wp_generate_uuid4(){return 'test';}
function esc_html($s){return htmlspecialchars($s,ENT_QUOTES);}
function wp_json_encode($s){return json_encode($s);}
function arv_ask($p,$data){
    if ($data['headings']!==['Leak repair','Leak repair']) throw new Exception('Incorrect heading scope');
    return ['paragraphs'=>['Inspect flashing & seals.','Identify the source.']];
}
function arv_target_value(){return $GLOBALS['source'];}
function set_transient($key,$value,$ttl){$GLOBALS['preview']=$value;}
$wpdb=new class {
    public $posts='posts';
    public $last_error = "";
    function prepare(...$args){return '';}
    function get_col(){return [19];}
};
$source='[{"elements":[{"widgetType":"heading","settings":{"title":"Leak repair"}},{"widgetType":"text-editor","settings":{"editor":"<p>Lorem ipsum dolor sit amet.</p><p>Real copy</p><p>Lorem ipsum.</p>","style":{}}},{"widgetType":"text-editor","settings":{"editor":"Lorem ipsum. Keep this client sentence."}},{"widgetType":"text-editor","settings":{"editor":"Lorem ipsum.","__dynamic__":{"editor":"tag"}}}]}]';
$result=pl_copy_next('service','Elementor services lorem ipsum replace',0,'test');
$expected=str_replace('<p>Lorem ipsum dolor sit amet.</p><p>Real copy</p><p>Lorem ipsum.</p>','<p>Inspect flashing &amp; seals.</p><p>Real copy</p><p>Identify the source.</p>',$source);
if ($result['cursor']!==18 || $preview['before']!==$source || json_decode($preview['after'])!=json_decode($expected) || count($preview['diff'])!==2 || strpos($preview['after'],'"style":{}')===false) throw new Exception('Invalid preview');
echo "Service copy preview passed\n";
$items = [];
$misses = [];
pl_copy_collect([['widgetType'=>'accordion','settings'=>['tabs'=>[['tab_title'=>'Pricing','tab_content'=>'<p>&nbsp;Lorem&nbsp;ipsum dolor sit amet…</p>']]]],['widgetType'=>'custom','settings'=>['custom_body'=>'Lorem ipsum.']]],$items,'Service',[],$misses);
if (count($items)!==1 || $items[0]['heading']!=='Pricing' || count($misses)!==1 || !pl_copy_placeholder('Lorem Ipsum is simply dummy text of the printing and typesetting industry.') || pl_copy_placeholder('Lorem ipsum. Keep real copy.')) throw new Exception('Placeholder recognition regression');

// Repeat the full preview against the custom widget setting found on service posts.
$source = str_replace('"editor":', '"text_ed":', $source);
$expected = str_replace('"editor":', '"text_ed":', $expected);
pl_copy_next('service','Replace service placeholders',0,'test');
if ($preview['before'] !== $source || json_decode($preview['after']) != json_decode($expected)) throw new Exception('Custom text_ed preview regression');
$rows=[];
pl_copy_inspect_fields(json_decode($source,true),'','',$rows);
if (pl_copy_inspect_id('Google Ads service (post ID 873) ka saved Elementor data inspect karo')!==873 || pl_copy_inspect_id('Inspect Elementor post 873')!==873 || count($rows)!==3 || $rows[0]!==['0.elements.1.settings.text_ed','Leak repair','<p>Lorem ipsum dolor sit amet.</p><p>Real copy</p><p>Lorem ipsum.</p>']) throw new Exception('Local Elementor inspection failed');
