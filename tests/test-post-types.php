<?php
/** Verifies existing RC4 settings support custom post types by default in RC5. */
define('ABSPATH', __DIR__);
$legacy_settings = array('enabled'=>1, 'post_types'=>array('post','page'));
$current_type = 'articles';
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function get_option($name, $default=array()) { global $legacy_settings; return $name === 'smart_schedule_settings' ? $legacy_settings : $default; }
function get_post_types($query=array(),$output='names') {
    $make=function($name){ return (object)array('cap'=>(object)array('edit_posts'=>'edit_'.$name)); };
    return array('post'=>$make('post'), 'page'=>$make('page'), 'articles'=>$make('articles'), 'reviews'=>$make('reviews'));
}
function get_current_screen() { global $current_type; return (object)array('post_type'=>$current_type); }
function current_user_can($cap) { return in_array($cap,array('edit_articles','edit_reviews','edit_post','edit_page'),true); }
function __( $text, $domain = null ) { return $text; }
require dirname(__DIR__).'/includes/class-smart-schedule.php';
$method = (new ReflectionClass('Smart_Schedule'))->getMethod('eligible_screen');
$method->setAccessible(true);
if (!$method->invoke(null)) { fwrite(STDERR,"FAIL: legacy RC4 settings exclude custom Articles in RC5\n");exit(1); }
$current_type='reviews';
if (!$method->invoke(null)) { fwrite(STDERR,"FAIL: custom Reviews excluded\n");exit(1); }
$legacy_settings['all_post_types']=0;
if ($method->invoke(null)) { fwrite(STDERR,"FAIL: custom type still allowed when all-post-types disabled\n");exit(1); }
$legacy_settings['post_types'][]='reviews';
if (!$method->invoke(null)) { fwrite(STDERR,"FAIL: explicit Reviews selection ignored\n");exit(1); }
$legacy_settings['enabled']=0;
if ($method->invoke(null)) { fwrite(STDERR,"FAIL: global disabled flag ignored\n");exit(1); }
echo "PASS: legacy defaults enable custom Articles/Reviews, explicit allowlist and global-disable settings work.\n";
