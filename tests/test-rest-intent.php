<?php
/** Standalone regression test for selective UTC handoff. Does not replace WordPress REST integration tests. */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error { public $code; public $message; public $data; public function __construct($c,$m,$d=array()){$this->code=$c;$this->message=$m;$this->data=$d;} }
function is_wp_error($v) { return $v instanceof WP_Error; }
function __( $text, $domain = null ) { return $text; }
function wp_timezone() { return new DateTimeZone('America/Toronto'); }
function get_option($key,$default=null) { return $key === 'smart_schedule_settings' ? array('lead_minutes'=>2) : $default; }
function absint($n) { return abs((int)$n); }
function get_post($id) { return $id === 52 ? (object)array('post_status'=>'publish') : ($id === 53 ? (object)array('post_status'=>'private') : null); }
class Fake_Request {private $v;public function __construct($v){$this->v=$v;}public function get_param($k){return $this->v[$k]??null;}}
require dirname(__DIR__) . '/includes/class-smart-schedule-time.php';
require dirname(__DIR__) . '/includes/class-smart-schedule.php';
$source = new stdClass();
$fold1 = Smart_Schedule::apply_utc_intent(clone $source, new Fake_Request(array('date'=>'2027-11-07T01:30:00','smart_schedule_utc_intent'=>'2027-11-07T05:30:00Z')));
$fold2 = Smart_Schedule::apply_utc_intent(clone $source, new Fake_Request(array('date'=>'2027-11-07T01:30:00','smart_schedule_utc_intent'=>'2027-11-07T06:30:00Z')));
if (is_wp_error($fold1) || is_wp_error($fold2) || $fold1->post_date_gmt !== '2027-11-07 05:30:00' || $fold2->post_date_gmt !== '2027-11-07 06:30:00') {fwrite(STDERR, "FAIL: DST fold UTC intent\n");exit(1);}
$wrong = Smart_Schedule::apply_utc_intent(clone $source, new Fake_Request(array('date'=>'2027-11-07T02:30:00','smart_schedule_utc_intent'=>'2027-11-07T06:30:00Z')));
if (!is_wp_error($wrong) || $wrong->code !== 'smart_schedule_date_mismatch') {fwrite(STDERR, "FAIL: site date mismatch guard\n");exit(1);}
$unmarked=Smart_Schedule::apply_utc_intent(clone $source, new Fake_Request(array('date'=>'2027-11-07T01:30:00')));
if (isset($unmarked->post_date)) {fwrite(STDERR, "FAIL: native editor untouched\n");exit(1);}
$past_time='2026-09-04T17:34:00Z';
$past=Smart_Schedule::apply_utc_intent(clone $source, new Fake_Request(array('id'=>52,'date'=>'2026-09-04T13:34:00','smart_schedule_utc_intent'=>$past_time)));
if (is_wp_error($past) || $past->post_date_gmt !== '2026-09-04 17:34:00') { fwrite(STDERR,"FAIL: published past edit rejected\n");exit(1); }
$past_private=Smart_Schedule::apply_utc_intent(clone $source, new Fake_Request(array('id'=>53,'date'=>'2026-09-04T13:34:00','smart_schedule_utc_intent'=>$past_time)));
if (is_wp_error($past_private)) { fwrite(STDERR,"FAIL: private past edit rejected\n");exit(1); }
$past_draft=Smart_Schedule::apply_utc_intent(clone $source, new Fake_Request(array('id'=>54,'date'=>'2026-09-04T13:34:00','smart_schedule_utc_intent'=>$past_time)));
if (!is_wp_error($past_draft) || $past_draft->code !== 'smart_schedule_invalid_intent') { fwrite(STDERR,"FAIL: draft past date allowed\n");exit(1); }
$past_statuschange=Smart_Schedule::apply_utc_intent(clone $source, new Fake_Request(array('id'=>52,'status'=>'draft','date'=>'2026-09-04T13:34:00','smart_schedule_utc_intent'=>$past_time)));
if (!is_wp_error($past_statuschange)) { fwrite(STDERR,"FAIL: published-to-draft bypass allowed\n");exit(1); }
// Server-side published->future safeguard (audit finding: previously only enforced client-side).
$future_time = gmdate('Y-m-d\TH:i:s\Z', time() + 86400);
$future_site = (new DateTimeImmutable($future_time))->setTimezone(wp_timezone())->format('Y-m-d\TH:i:s');
foreach (array(null, 'future', 'publish', 'private') as $status) {
    $params = array('id'=>52,'date'=>$future_site,'smart_schedule_utc_intent'=>$future_time);
    if (null !== $status) { $params['status'] = $status; }
    $r = Smart_Schedule::apply_utc_intent(clone $source, new Fake_Request($params));
    if (!is_wp_error($r) || $r->code !== 'smart_schedule_published_future') { fwrite(STDERR,"FAIL: published post moved to future (status=".var_export($status,true).")\n");exit(1); }
}
$private_future = Smart_Schedule::apply_utc_intent(clone $source, new Fake_Request(array('id'=>53,'date'=>$future_site,'smart_schedule_utc_intent'=>$future_time)));
if (!is_wp_error($private_future) || $private_future->code !== 'smart_schedule_published_future') { fwrite(STDERR,"FAIL: private post moved to future\n");exit(1); }
// An explicit demotion to draft in the same request is a deliberate unpublish-and-reschedule.
$demote = Smart_Schedule::apply_utc_intent(clone $source, new Fake_Request(array('id'=>52,'status'=>'draft','date'=>$future_site,'smart_schedule_utc_intent'=>$future_time)));
if (is_wp_error($demote) || $demote->post_date_gmt !== str_replace(array('T','Z'),array(' ',''),$future_time)) { fwrite(STDERR,"FAIL: explicit draft demotion with future schedule rejected\n");exit(1); }
echo "PASS: DST folds, wrong-date guard, published/private backdating, draft past rejection, published-future safeguard, and native saves.\n";
