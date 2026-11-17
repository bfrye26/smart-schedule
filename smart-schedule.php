<?php
/**
 * Plugin Name: Smart Schedule
 * Description: Timezone-aware post scheduling with 12/24-hour entry, daylight saving validation, and a live WordPress time conversion preview.
 * Version: 1.2.0
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Tested up to: 7.1
 * Author: Smart Schedule
 * License: GPL-2.0-or-later
 * Text Domain: smart-schedule
 * Update URI: https://example.com/smart-schedule
 */

// Update URI is a placeholder: it blocks wordpress.org from offering updates
// for a same-slug plugin. Replace it with your real distribution URL if you
// set up an update server.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SMART_SCHEDULE_VERSION', '1.2.0' );
define( 'SMART_SCHEDULE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMART_SCHEDULE_URL', plugin_dir_url( __FILE__ ) );
require_once SMART_SCHEDULE_DIR . 'includes/class-smart-schedule-time.php';
require_once SMART_SCHEDULE_DIR . 'includes/class-smart-schedule.php';

add_action( 'plugins_loaded', array( 'Smart_Schedule', 'init' ) );
