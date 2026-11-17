<?php
/**
 * Regenerate languages/smart-schedule.pot from PHP and JS sources.
 * Run: php tests/make-pot.php
 * Lightweight extractor for __(), esc_html__(), esc_html_e() with the
 * 'smart-schedule' text domain. Replace with wp i18n make-pot if WP-CLI
 * is available.
 */
$root = dirname( __DIR__ );
$entries = array();

function unescape_php( $s, $quote ) {
	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $s );
	}
	return stripcslashes( $s );
}
function pot_escape( $s ) {
	return str_replace( array( '\\', '"', "\n", "\t" ), array( '\\\\', '\\"', '\\n', '\\t' ), $s );
}
function collect( $file, $pattern, &$entries ) {
	$code = file_get_contents( $file );
	$rel = str_replace( '\\', '/', substr( $file, strlen( dirname( __DIR__ ) ) + 1 ) );
	if ( preg_match_all( $pattern, $code, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		foreach ( $m as $match ) {
			$quote = $match[1][0];
			$text = unescape_php( $match[2][0], $quote );
			$line = substr_count( substr( $code, 0, $match[0][1] ), "\n" ) + 1;
			$entries[ $text ][] = "$rel:$line";
		}
	}
}

$php_pattern = '/(?:__|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*(["\'])((?:(?!\1)[^\\\\]|\\\\.)*)\1\s*,\s*\'smart-schedule\'/s';
$js_pattern  = '/__\(\s*([\'"])((?:(?!\1)[^\\\\]|\\\\.)*)\1\s*,\s*[\'"]smart-schedule[\'"]\s*\)/s';

foreach ( array( $root . '/smart-schedule.php', $root . '/includes/class-smart-schedule.php', $root . '/includes/class-smart-schedule-time.php' ) as $file ) {
	collect( $file, $php_pattern, $entries );
}
foreach ( glob( $root . '/assets/*.js' ) as $file ) {
	collect( $file, $js_pattern, $entries );
}
ksort( $entries );

$pot = '# Smart Schedule translation template.
msgid ""
msgstr ""
"Project-Id-Version: Smart Schedule 1.1.0\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Content-Transfer-Encoding: 8bit\n"
"X-Domain: smart-schedule\n"
';
foreach ( $entries as $text => $refs ) {
	$pot .= "\n#: " . implode( ' ', array_unique( $refs ) ) . "\n";
	$pot .= 'msgid "' . pot_escape( $text ) . '"' . "\n";
	$pot .= 'msgstr ""' . "\n";
}
$dir = $root . '/languages';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}
file_put_contents( $dir . '/smart-schedule.pot', $pot );
echo 'Wrote languages/smart-schedule.pot with ' . count( $entries ) . " strings.\n";