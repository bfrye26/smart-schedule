<?php
/** Standalone conversion checks. Run: php tests/test-time.php */
define( 'SMART_SCHEDULE_TESTING', true );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error { public $code; public $message; public function __construct( $code, $message ) { $this->code=$code; $this->message=$message; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $text, $domain = null ) { return $text; }
$GLOBALS['test_zone'] = 'America/Toronto';
function wp_timezone() { return new DateTimeZone( $GLOBALS['test_zone'] ); }
function get_option( $key, $fallback = null ) { return $key === 'smart_schedule_settings' ? array('lead_minutes'=>2) : $fallback; }
require dirname(__DIR__) . '/includes/class-smart-schedule-time.php';
$passed = 0;
function check($label,$truth) { global $passed; if (!$truth) { fwrite(STDERR,"FAIL: $label\n"); exit(1); } $passed++; echo "PASS: $label\n"; }
$p = Smart_Schedule_Time::preview('2026-11-20','09:30','America/Los_Angeles');
check('LA->UTC date', $p['utc'] === '2026-11-20T17:30:00Z');
check('LA->Toronto site time', $p['site']['time'] === '12:30' && $p['site']['abbrev'] === 'EST');
check('LA zone abbreviation', $p['selected']['abbrev'] === 'PST');
check('DST missing wall time blocked', Smart_Schedule_Time::preview('2027-03-14','02:30','America/Toronto')['status'] === 'nonexistent');
$a=Smart_Schedule_Time::preview('2027-11-07','01:30','America/Toronto');
check('DST repeated wall time detected', $a['status'] === 'ambiguous' && count($a['choices'])===2);
check('DST first occurrence UTC', Smart_Schedule_Time::preview('2027-11-07','01:30','America/Toronto','first')['utc'] === '2027-11-07T05:30:00Z');
check('DST second occurrence UTC', Smart_Schedule_Time::preview('2027-11-07','01:30','America/Toronto','second')['utc'] === '2027-11-07T06:30:00Z');
check('DST exact occurrence survives reformat', Smart_Schedule_Time::from_utc('2027-11-07T06:30:00Z','America/Toronto')['choice'] === 'second');
check('Nepal 45-minute offset', Smart_Schedule_Time::preview('2027-01-01','12:00','Asia/Kathmandu')['selected']['offset'] === '+05:45');
check('India 30-minute offset', Smart_Schedule_Time::preview('2027-01-01','12:00','Asia/Kolkata')['selected']['offset'] === '+05:30');
check('International date crossing', Smart_Schedule_Time::preview('2027-01-01','00:30','Pacific/Auckland')['gmt']['date'] === '2026-12-31');
check('Reject invalid calendar day', is_wp_error(Smart_Schedule_Time::preview('2027-02-30','09:30','UTC')));
check('Reject invalid hour', is_wp_error(Smart_Schedule_Time::preview('2027-02-28','24:30','UTC')));
check('Reject invalid minute', is_wp_error(Smart_Schedule_Time::preview('2027-02-28','20:60','UTC')));
check('Reject unknown zone', is_wp_error(Smart_Schedule_Time::preview('2027-02-28','09:30','Moon/SeaOfTranquility')));
check('Reject invalid UTC date', is_wp_error(Smart_Schedule_Time::from_utc('2027-02-30T17:30:00Z','UTC')));
check('Reject year 0000 in from_utc', is_wp_error(Smart_Schedule_Time::from_utc('0000-01-01T00:00:00Z','UTC')));
check('Reject year below 1970 in from_utc', is_wp_error(Smart_Schedule_Time::from_utc('1969-12-31T23:59:59Z','UTC')));
check('Reject year above 2100 in from_utc', is_wp_error(Smart_Schedule_Time::from_utc('2101-01-01T00:00:00Z','UTC')));
check('Accept boundary year 2100 in from_utc', !is_wp_error(Smart_Schedule_Time::from_utc('2100-12-31T23:59:59Z','UTC')));
$GLOBALS['test_zone'] = '+05:30';
check('Fixed-offset website supported', Smart_Schedule_Time::preview('2027-02-28','09:30','UTC')['site']['time']==='15:00');
echo "All $passed conversion assertions passed.\n";
