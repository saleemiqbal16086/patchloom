<?php
define('ABSPATH',__DIR__);
function add_action(...$args){}
require dirname(__DIR__).'/patchloom/services.php';
function patchloom_file_source($j,$file){return $GLOBALS['src'][$file];}
function pl_target($f){return $GLOBALS['root'].'/'.$f;}
function post_type_exists($t){return !empty($GLOBALS['registered']);}
function sanitize_text_field($v){return trim($v);}
function sanitize_textarea_field($v){return trim($v);}
function wp_strip_all_tags($v){return strip_tags($v);}
function sanitize_title($v){return strtolower($v);}
function esc_url_raw($v,$schemes){return $v;}
function arv_ask($prompt,$data){return $GLOBALS['answer'];}
if (!pl_services_intent('services ki postype bnao') || !pl_services_intent('services post type bnao')) throw new Exception('Routing failed');
$root=sys_get_temp_dir().'/pl-services-'.uniqid(); mkdir($root);
$card='<article><a href="detail.html">Heating</a></article>';
$src=['listing.php'=>'<main>'.$card.str_replace('Heating','Cooling',$card).'</main>','detail.php'=>'<h1>Sample</h1><div>Placeholder</div>'];
$answer=['cards'=>[],'card_bindings'=>[['field'=>'link','old'=>'detail.html'],['field'=>'title','old'=>'Heating']],'detail_bindings'=>[['field'=>'title','old'=>'Sample'],['field'=>'content','old'=>'Placeholder']]];
foreach (['Heating','Cooling'] as $name) $answer['cards'][]=['html'=>str_replace('Heating',$name,$card),'title'=>$name,'excerpt'=>'Summary','content'=>'Draft content','image'=>''];
$j=['listing'=>'listing.php','detail'=>'detail.php','type'=>'service','request'=>'Services migration'];
pl_services_inventory($j);
pl_services_prepare($j);
if ($j['stage']!=='copy' || count($j['rows'])!==2 || $j['writes']['listing.php']['before']!==$src['listing.php'] || strpos($j['writes']['single-service.php']['after'],'get_post_field')===false || strpos($j['writes']['listing.php']['after'],$card)===false) throw new Exception('Incomplete preview');
// Exercise compact locators against actual formatted source with comments between cards.
$baseline=$src['listing.php'];
$src['listing.php']=str_replace('</article><article>',"</article>\n<!-- second card -->\n<article>",$baseline);
foreach ($answer['cards'] as &$item) {unset($item['html']); $item['opening_tag']='<article>';}
unset($item);
$fixture=$j; pl_services_inventory($fixture); pl_services_prepare($fixture);
if (count($fixture['rows'])!==2 || strpos($fixture['writes']['listing.php']['after'],'<!-- second card -->')===false) throw new Exception('Comment-separated cards lost');
$src['listing.php']=str_replace('<!-- second card -->','<aside>Keep this</aside>',$src['listing.php']);
try {pl_services_inventory($fixture); pl_services_prepare($fixture); throw new LogicException('Skipped an intervening section');} catch (LogicException $e){throw $e;} catch (Exception $e){if (strpos($e->getMessage(),'Cooling')===false) throw $e;}
$src['listing.php']=$baseline;
$answer['cards'][0]['opening_tag']='<missing>';
$answer['cards'][0]['html']='wrong fragment';
try {$invalid=$j; pl_services_inventory($invalid); pl_services_prepare($invalid); throw new LogicException('Invalid card accepted');} catch (LogicException $e){throw $e;} catch (RuntimeException $e){} catch (Exception $e){}
$answer=['content'=>'Prepared detail copy'];
pl_services_copy_step($j);
if ($j['stage']!=='copy' || count(pl_services_pending_copy($j))!==1) throw new Exception('Copy checkpoint missing');
$answer=[];
try {pl_services_copy_step($j);throw new LogicException('Accepted missing copy');} catch (LogicException $e){throw $e;} catch (Exception $e){}
if (count(pl_services_pending_copy($j))!==1) throw new Exception('Failed copy lost progress');
$answer=['content'=>'Second service detail'];
pl_services_copy_step($j);
if ($j['stage']!=='preview') throw new Exception('Final approval preview missing');
echo "Service migration preview passed\n";
function current_user_can(...$args){return true;}
function get_post_type($id){return 'page';}
function get_page_template_slug($id){return 'listing.php';}
function get_page_by_path(...$args){return null;}
function taxonomy_exists($t){return false;}
function get_option($k,$d=false){return $GLOBALS['opts'][$k]??$d;}
function add_option($k,$v,...$unused){$GLOBALS['opts'][$k]=$v;return true;}
function delete_option($k){unset($GLOBALS['opts'][$k]);}
function register_post_type($t,$args){$GLOBALS['registered']=true;}
function unregister_post_type($t){$GLOBALS['registered']=false;}
function get_post_type_object($t){return (object)['cap'=>(object)['create_posts'=>'edit_posts']];}
function wp_slash($v){return $v;}
function wp_insert_post($data,$errors){$id=count($GLOBALS['drafts'])+1;$GLOBALS['drafts'][$id]=$data;return $id;}
function wp_delete_post($id,$force){unset($GLOBALS['drafts'][$id]);}
function is_wp_error($v){return false;}
function pl_write_bytes($file,$bytes){if (!empty($GLOBALS['fail']) && basename($file)==='single-service.php') throw new Exception('Simulated write failure');file_put_contents($file,$bytes);}
function wp_clean_themes_cache(){}
function flush_rewrite_rules($hard){}
$j+=['page'=>8,'assigned'=>'listing.php','create'=>true,'assign'=>false];
file_put_contents(pl_target('listing.php'),$src['listing.php']);
$drafts=[];$fail=true;
try {pl_services_apply($j);throw new LogicException('Expected rollback');} catch (LogicException $e){throw $e;} catch (Exception $e){if ($e->getMessage()!=='Simulated write failure') throw $e;}
if ($drafts || get_option('pl_services_owned') || file_get_contents(pl_target('listing.php'))!==$src['listing.php']) throw new Exception('Migration rollback failed');
$fail=false;
if (pl_services_apply($j)!==2 || $drafts[2]['post_status']!=='draft' || $drafts[2]['menu_order']!==1 || !is_file(pl_target('single-service.php'))) throw new Exception('Approved migration failed');
unlink(pl_target('listing.php'));unlink(pl_target('single-service.php'));rmdir($root);
echo "Approved migration and rollback passed\n";
