<?php
define('ABSPATH',__DIR__);
function add_action(...$args){}
function add_filter(...$args){}
function esc_html($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}
function esc_url($v){return esc_html($v);}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function wp_strip_all_tags($v){return strip_tags($v);}
function get_option($key){return false;}
class WP_Http {static function make_absolute_url($u,$b){return strpos($u,'://')!==false?$u:'https://old.test/'.ltrim($u,'/');}}
require dirname(__DIR__).'/patchloom/page-import.php';
$html='<html><body><header>Client navigation</header><main><h1>Roof repairs</h1><h2>Our work</h2><p>Lorem ipsum [custom_shortcode] &amp; original copy.</p><img src="/roof.jpg" alt="Roof"><p><a class="cta" href="/contact/">Contact</a></p><details><summary>When?</summary><p>On request.</p></details></main><footer>Old footer</footer></body></html>';
[$title,$blocks]=pl_bakery_extract($html,'https://old.test/');
$groups=pl_bakery_sections($blocks,$title);
foreach ($groups as &$group) foreach ($group as &$block) if ($block['kind']==='image') $block['id']=42;
unset($group,$block);
$out=pl_bakery_render($groups,pl_bakery_layouts($groups,''));
foreach (['[vc_row','[vc_single_image image="42"','Our work','&#91;custom_shortcode&#93;','On request.','[vc_toggle','https://old.test/contact/','wp-element-button'] as $want) if (strpos($out,$want)===false) throw new Exception('Missing output: '.$want);
if ($title!=='Roof repairs' || strpos($out,'Client navigation')!==false || strpos($out,'Old footer')!==false || strpos($out,'[custom_shortcode]')!==false) throw new Exception('Source boundaries failed');
if (pl_bakery_in_scope('https://old.test/other/','https://old.test/client/') || pl_bakery_in_scope('https://elsewhere.test/client/','https://old.test/client/')) throw new Exception('Discovery exceeded scope');
echo "WPBakery import content and rendering passed\n";
$card='<article><h3>Flat Roofs</h3><p>Service text</p><a href="/roof/">Details</a></article>';
[, $blocks]=pl_bakery_extract('<main><h1>Services</h1><div>'.$card.$card.$card.'</div></main>','https://old.test/');
$out=pl_bakery_render(pl_bakery_sections($blocks,'Services'),['text']);
if (substr_count($out,'[vc_column_inner width="1/3"')!==3 || substr_count($out,'Service text')!==3) throw new Exception('Service card layout failed');
