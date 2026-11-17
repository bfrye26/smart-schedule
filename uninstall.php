<?php
/** Remove only plugin preferences, never WordPress's scheduled publishing data. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}
delete_option( 'smart_schedule_settings' );
delete_option( 'smart_schedule_version' );
delete_transient( 'smart_schedule_diag' );
delete_metadata( 'user', 0, 'smart_schedule_preferences', '', true );
