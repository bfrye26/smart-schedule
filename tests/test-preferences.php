<?php
/** Settings precedence and unconfigured-editor defaults without a WordPress runtime. */
define('ABSPATH', __DIR__);
$settings = array('default_clock'=>'24','default_zone'=>'site','remember_editor'=>1);
$user_preferences = array('clock'=>'12', 'timezone'=>'America/Los_Angeles');
function get_option($key, $default=null) { global $settings; if ($key==='smart_schedule_settings') return $settings; return $default; }
function get_user_meta($user_id, $key, $single=false) { global $user_preferences; return $user_preferences; }
function get_current_user_id() { return 1; }
function wp_timezone() { return new DateTimeZone('America/Toronto'); }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function rest_url($path) { return '/wp-json/'.$path; }
function wp_create_nonce($action) { return 'test'; }
function get_the_ID() { return 1; }
function gmdate_mock() { return gmdate('Y-m-d\TH:i:s\Z'); }
class WP_Error { private $code; function __construct($code, $message) {$this->code=$code;} }
function is_wp_error($value) { return $value instanceof WP_Error; }
function __( $text, $domain = null ) { return $text; }
require dirname(__DIR__) . '/includes/class-smart-schedule-time.php';
require dirname(__DIR__) . '/includes/class-smart-schedule.php';
$conf = Smart_Schedule::config();
if ($conf['clock'] !== '12') { fwrite(STDERR,"FAIL: 12-hour editor preference lost to 24-hour site setting\n");exit(1); }
if ($conf['zone'] !== 'America/Toronto') { fwrite(STDERR,"FAIL: remembered timezone overrode the website timezone default\n");exit(1); }
$user_preferences = array();
$conf = Smart_Schedule::config();
if ($conf['clock'] !== '24' || $conf['zone'] !== 'America/Toronto') { fwrite(STDERR,"FAIL: new editor did not use configured clock and website timezone\n");exit(1); }
$settings['remember_editor']=0;
$user_preferences = array('clock'=>'12','timezone'=>'Europe/London');
$conf = Smart_Schedule::config();
if ($conf['clock'] !== '24' || $conf['zone'] !== 'America/Toronto') { fwrite(STDERR,"FAIL: disabled per-editor preferences still applied\n");exit(1); }
// A legacy default_zone setting must not change the initial timezone either.
$settings['remember_editor']=1;
$settings['default_zone']='Europe/London';
$user_preferences = array();
$conf = Smart_Schedule::config();
if ($conf['zone'] !== 'America/Toronto') { fwrite(STDERR,"FAIL: legacy default_zone setting overrode the website timezone\n");exit(1); }
echo "PASS: website-timezone default (remembered zone and legacy default_zone ignored), clock preference, and disabled-preference support.\n";
