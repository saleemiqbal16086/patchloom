<?php
define('ABSPATH',__DIR__); define('MINUTE_IN_SECONDS',60);
function add_action(...$args){}
function get_current_user_id(){return 1;}
function current_user_can($cap){return true;}
function get_stylesheet(){return 'test';}
function get_stylesheet_directory(){return $GLOBALS['dir'];}
function wp_get_theme(){return new class {function get_files($ext){return $ext==='php'?['Home.php'=>get_stylesheet_directory().'/Home.php']:[];}};}
function get_transient($k){return $GLOBALS['job'];}
function set_transient($k,$v,$ttl){$GLOBALS['job']=$v;}
function sanitize_text_field($s){return $s;}
function sanitize_textarea_field($s){return $s;}
function wp_generate_uuid4(){return uniqid();}
function arv_ask($prompt,$context){
    if (empty($context['clarifications'])) return ['question'=>'Keep missing books?'];
    if ($context['clarifications'][0]['answer']!=='Use all published books') throw new Exception('Lost reply');
    return ['old'=>'Old cards','new'=>'New cards'];
}
require dirname(__DIR__).'/patchloom/theme-chat.php';
$dir=sys_get_temp_dir().'/patchloom-'.uniqid();mkdir($dir);file_put_contents($dir.'/Home.php','Old cards');
$job=['theme'=>'test','stage'=>'read','token'=>'first','file'=>'Home.php','request'=>'Update cards','editing'=>true];
$_POST['file_token']='first';
patchloom_file_action('file_read','Home.php');
if ($job['stage']!=='clarify') throw new Exception('Question was not persisted');
$_POST['file_token']=$job['token'];
patchloom_file_action('file_answer',null,'Use all published books');
if ($job['stage']!=='write'||$job['new']!=='New cards'||file_get_contents($dir.'/Home.php')!=='Old cards') throw new Exception('Reply did not produce a read-only preview');
unlink($dir.'/Home.php');rmdir($dir);echo "Clarification to preview passed\n";
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/service-copy.php'),$copy_status);
if ($copy_status !== 0) throw new Exception('Service copy regression failed');
