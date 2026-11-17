<?php
/**
 * Smart Schedule WordPress integration.
 * @package SmartSchedule
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Smart_Schedule {
    const OPTION = 'smart_schedule_settings';
    const PREFS = 'smart_schedule_preferences';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_utc_intent' ) );
        add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_assets' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( SMART_SCHEDULE_DIR . 'smart-schedule.php' ), array( __CLASS__, 'action_links' ) );
    }

    public static function load_textdomain() {
        load_plugin_textdomain( 'smart-schedule', false, dirname( plugin_basename( SMART_SCHEDULE_DIR . 'smart-schedule.php' ) ) . '/languages' );
    }

    /**
     * Versioned upgrade routine. Runs once per version bump so future setting
     * changes have a migration path instead of relying on defaults merging.
     */
    public static function maybe_upgrade() {
        $stored = get_option( 'smart_schedule_version', '' );
        if ( SMART_SCHEDULE_VERSION === $stored ) {
            return;
        }
        // 1.1.0: no data migration needed; stored settings merge with defaults.
        // 1.2.0: the picker always opens in the website timezone; drop the
        // obsolete default_zone setting. Stored per-editor timezone prefs are
        // left in place but ignored (clock format and favourites still apply).
        $opts = get_option( self::OPTION, array() );
        if ( is_array( $opts ) && array_key_exists( 'default_zone', $opts ) ) {
            unset( $opts['default_zone'] );
            update_option( self::OPTION, $opts );
        }
        update_option( 'smart_schedule_version', SMART_SCHEDULE_VERSION, false );
    }

    public static function defaults() {
        return array(
            'enabled'             => 1,
            'default_clock'       => '12',
            'remember_editor'     => 1,
            'show_utc'            => 1,
            'classic_enabled'     => 1,
            'all_post_types'      => 1,
            'lead_minutes'        => 2,
            'favorites'           => array( 'America/Toronto', 'America/Los_Angeles', 'Europe/London', 'Asia/Tokyo' ),
            'post_types'          => array( 'post', 'page' ),
        );
    }

    public static function options() {
        $stored = get_option( self::OPTION, array() );
        return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
    }

    public static function available_post_types() {
        $objects = get_post_types( array( 'show_ui' => true ), 'objects' );
        $result = array();
        foreach ( $objects as $key => $type ) {
            if ( in_array( $key, array( 'attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'custom_css', 'customize_changeset' ), true ) || 0 === strpos( $key, 'wp_' ) ) {
                continue;
            }
            if ( empty( $type->cap->edit_posts ) ) {
                continue;
            }
            $result[ $key ] = $type;
        }
        return $result;
    }

    public static function allowed_zone( $name ) {
        return ! is_wp_error( Smart_Schedule_Time::zone( $name ) );
    }

    public static function sanitize_settings( $input ) {
        $old = self::options();
        if ( ! is_array( $input ) ) {
            return $old;
        }
        $clean = $old;
        foreach ( array( 'enabled', 'remember_editor', 'show_utc', 'classic_enabled', 'all_post_types' ) as $key ) {
            $clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
        }
        $clean['default_clock'] = isset( $input['default_clock'] ) && '24' === (string) $input['default_clock'] ? '24' : '12';
        unset( $clean['default_zone'] ); // Removed in 1.2.0: the picker always opens in the website timezone.
        $clean['lead_minutes'] = max( 0, min( 60, isset( $input['lead_minutes'] ) ? (int) $input['lead_minutes'] : 2 ) );
        $favinput = isset( $input['favorites'] ) ? $input['favorites'] : array();
        if ( is_string( $favinput ) ) {
            $favinput = preg_split( '/[,\r\n]+/', $favinput );
        }
        $clean['favorites'] = array_slice( array_values( array_unique( array_filter( array_map( 'trim', (array) $favinput ), array( __CLASS__, 'allowed_zone' ) ) ) ), 0, 12 );
        $types = isset( $input['post_types'] ) ? (array) $input['post_types'] : array();
        $clean['post_types'] = array_values( array_intersect( array_map( 'sanitize_key', $types ), array_keys( self::available_post_types() ) ) );
        return $clean;
    }

    public static function register_settings() {
        register_setting( 'smart_schedule_group', self::OPTION, array(
            'type' => 'array',
            'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
            'default' => self::defaults(),
        ) );
    }

    public static function menu() {
        add_options_page( 'Smart Schedule', 'Smart Schedule', 'manage_options', 'smart-schedule', array( __CLASS__, 'settings_page' ) );
    }

    public static function action_links( $links ) {
        array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=smart-schedule' ) ) . '">' . esc_html__( 'Settings', 'smart-schedule' ) . '</a>' );
        return $links;
    }

    public static function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $s = self::options();
        $site = wp_timezone();
        $zone_now = ( new DateTimeImmutable( 'now', $site ) )->format( 'Y-m-d H:i:s T P' );
        $cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? __( 'Disabled internally (external cron recommended)', 'smart-schedule' ) : __( 'WordPress page-load cron enabled', 'smart-schedule' );
        // Cache the scheduled-post lookup briefly so the settings page does not
        // run a get_posts query on every load; current-time rows stay live.
        $diag = get_transient( 'smart_schedule_diag' );
        if ( ! is_array( $diag ) ) {
            $future_ids = get_posts( array( 'post_status' => 'future', 'post_type' => 'any', 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'ASC', 'fields' => 'ids', 'suppress_filters' => true ) );
            $diag = array(
                'post_id'      => $future_ids ? (int) $future_ids[0] : 0,
                'next_publish' => $future_ids ? wp_next_scheduled( 'publish_future_post', array( (int) $future_ids[0] ) ) : false,
            );
            set_transient( 'smart_schedule_diag', $diag, MINUTE_IN_SECONDS );
        }
        $future_ids = $diag['post_id'] ? array( $diag['post_id'] ) : array();
        $next_publish = $diag['next_publish'];
        ?>
        <div class="wrap ss-admin">
            <h1><?php esc_html_e( 'Smart Schedule', 'smart-schedule' ); ?></h1>
            <p><?php esc_html_e( 'Timezone-aware scheduling through WordPress. Existing scheduled posts continue to use WordPress core.', 'smart-schedule' ); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields( 'smart_schedule_group' ); ?>
                <div class="ss-admin-card">
                    <h2><?php esc_html_e( 'Scheduling defaults', 'smart-schedule' ); ?></h2>
                    <label class="ss-admin-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Enable Smart Schedule', 'smart-schedule' ); ?></label>
                    <div class="ss-admin-field"><label for="ss-clock"><?php esc_html_e( 'Default clock format', 'smart-schedule' ); ?></label>
                        <select id="ss-clock" name="<?php echo esc_attr( self::OPTION ); ?>[default_clock]">
                            <option value="12" <?php selected( $s['default_clock'], '12' ); ?>><?php esc_html_e( '12-hour (AM/PM)', 'smart-schedule' ); ?></option>
                            <option value="24" <?php selected( $s['default_clock'], '24' ); ?>><?php esc_html_e( '24-hour', 'smart-schedule' ); ?></option>
                        </select>
                    </div>
                    <p class="description"><?php echo esc_html( sprintf( __( 'The scheduler always opens in the website timezone (%s). Editors can choose another timezone from the picker when needed.', 'smart-schedule' ), $site->getName() ) ); ?></p>
                    <label class="ss-admin-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[remember_editor]" value="1" <?php checked( $s['remember_editor'] ); ?> /> <?php esc_html_e( "Remember each editor's clock format and favourite timezones", 'smart-schedule' ); ?></label>
                    <label class="ss-admin-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[show_utc]" value="1" <?php checked( $s['show_utc'] ); ?> /> <?php esc_html_e( 'Show UTC conversion', 'smart-schedule' ); ?></label>
                    <label class="ss-admin-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[classic_enabled]" value="1" <?php checked( $s['classic_enabled'] ); ?> /> <?php esc_html_e( 'Enable Classic Editor integration', 'smart-schedule' ); ?></label>
                    <div class="ss-admin-field"><label for="ss-lead"><?php esc_html_e( 'Minimum scheduling lead time (minutes)', 'smart-schedule' ); ?></label>
                        <input type="number" id="ss-lead" min="0" max="60" name="<?php echo esc_attr( self::OPTION ); ?>[lead_minutes]" value="<?php echo esc_attr( $s['lead_minutes'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'Defaults to two minutes. This avoids accidentally choosing a timestamp at or immediately before the current moment.', 'smart-schedule' ); ?></p>
                    </div>
                    <div class="ss-admin-field"><label for="ss-favorites"><?php esc_html_e( 'Favourite timezones', 'smart-schedule' ); ?></label>
                        <textarea id="ss-favorites" rows="3" name="<?php echo esc_attr( self::OPTION ); ?>[favorites]" placeholder="America/Toronto, Europe/London"><?php echo esc_textarea( implode( ', ', $s['favorites'] ) ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'IANA timezone IDs, separated by commas. Up to 12 favourites.', 'smart-schedule' ); ?></p>
                    </div>
                </div>
                <div class="ss-admin-card">
                    <h2><?php esc_html_e( 'Supported post types', 'smart-schedule' ); ?></h2>
                    <label class="ss-admin-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[all_post_types]" value="1" <?php checked( $s['all_post_types'] ); ?> /> <?php esc_html_e( 'Enable on all compatible post types (including custom News, Reviews and Articles)', 'smart-schedule' ); ?></label>
                    <p><?php esc_html_e( 'When this is enabled, compatible post types are automatically supported. Disable it to use only the checked post types below. Gutenberg post types must also support REST editing.', 'smart-schedule' ); ?></p>
                    <div class="ss-types">
                        <?php foreach ( self::available_post_types() as $key => $type ) : ?>
                            <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[post_types][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $s['post_types'], true ) ); ?> /> <?php echo esc_html( $type->labels->name ); ?> <code><?php echo esc_html( $key ); ?></code></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php submit_button( __( 'Save settings', 'smart-schedule' ) ); ?>
            </form>
            <div class="ss-admin-card">
                <h2><?php esc_html_e( 'Scheduling diagnostics', 'smart-schedule' ); ?></h2>
                <table class="widefat striped"><tbody>
                    <tr><th><?php esc_html_e( 'WordPress website timezone', 'smart-schedule' ); ?></th><td><code><?php echo esc_html( $site->getName() ); ?></code></td></tr>
                    <tr><th><?php esc_html_e( 'Current website time', 'smart-schedule' ); ?></th><td><?php echo esc_html( $zone_now ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'PHP/server default timezone', 'smart-schedule' ); ?></th><td><code><?php echo esc_html( date_default_timezone_get() ); ?></code></td></tr>
                    <tr><th><?php esc_html_e( 'Current UTC', 'smart-schedule' ); ?></th><td><?php echo esc_html( gmdate( 'Y-m-d H:i:s' ) ); ?> UTC</td></tr>
                    <tr><th><?php esc_html_e( 'WP-Cron', 'smart-schedule' ); ?></th><td><?php echo esc_html( $cron ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Example scheduled post cron event', 'smart-schedule' ); ?></th><td><?php echo $next_publish ? esc_html( gmdate( 'Y-m-d H:i:s', $next_publish ) . ' UTC for post #' . $future_ids[0] ) : esc_html( $future_ids ? __( 'No publish_future_post cron event found for the earliest scheduled post. Check WP-Cron.', 'smart-schedule' ) : __( 'No scheduled posts found.', 'smart-schedule' ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Timezone database', 'smart-schedule' ); ?></th><td><?php echo esc_html( function_exists( 'timezone_version_get' ) ? timezone_version_get() : __( 'Unavailable', 'smart-schedule' ) ); ?></td></tr>
                </tbody></table>
                <p class="description"><?php esc_html_e( 'WordPress owns the publishing cron. If WP-Cron is disabled, confirm your server calls wp-cron.php or WP-CLI on a schedule.', 'smart-schedule' ); ?></p>
            </div>
        </div>
        <?php
    }

    public static function prefs() {
        $prefs = get_user_meta( get_current_user_id(), self::PREFS, true );
        return is_array( $prefs ) ? $prefs : array();
    }

    public static function config() {
        $s = self::options();
        $p = $s['remember_editor'] ? self::prefs() : array();
        return array(
            'rest'        => rest_url( 'smart-schedule/v1/' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'siteZone'    => wp_timezone()->getName(),
            // The picker always opens in the website timezone. Another zone is
            // only shown when the editor explicitly selects one; remembered
            // timezone preferences no longer override the initial display.
            'zone'        => wp_timezone()->getName(),
            'clock'       => isset( $p['clock'] ) && in_array( (string) $p['clock'], array( '12', '24' ), true ) ? (string) $p['clock'] : $s['default_clock'],
            'siteFixed'   => (bool) preg_match( '/^[+-][0-9]{2}:[0-9]{2}$/', wp_timezone()->getName() ),
            'zones'       => DateTimeZone::listIdentifiers(),
            'favorites'   => isset( $p['favorites'] ) && is_array( $p['favorites'] ) ? $p['favorites'] : $s['favorites'],
            'showUtc'     => (bool) $s['show_utc'],
            'remember'    => (bool) $s['remember_editor'],
            'minLead'     => (int) $s['lead_minutes'],
            'weekStart'   => (int) get_option( 'start_of_week', 0 ),
            'utcNow'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'postId'      => get_the_ID() ? (int) get_the_ID() : 0,
        );
    }

    private static function eligible_screen() {
        $s = self::options();
        $screen = get_current_screen();
        if ( ! $screen || ! $s['enabled'] ) {
            return false;
        }
        $types = self::available_post_types();
        if ( ! isset( $types[ $screen->post_type ] ) || ( ! $s['all_post_types'] && ! in_array( $screen->post_type, $s['post_types'], true ) ) ) {
            return false;
        }
        $type = $types[ $screen->post_type ];
        return current_user_can( $type->cap->edit_posts );
    }

    private static function shared_assets() {
        wp_enqueue_style( 'smart-schedule-ui', SMART_SCHEDULE_URL . 'assets/scheduler.css', array(), SMART_SCHEDULE_VERSION );
        wp_enqueue_script( 'smart-schedule-ui', SMART_SCHEDULE_URL . 'assets/scheduler.js', array( 'wp-api-fetch', 'wp-i18n' ), SMART_SCHEDULE_VERSION, true );
        wp_set_script_translations( 'smart-schedule-ui', 'smart-schedule', SMART_SCHEDULE_DIR . 'languages' );
        wp_localize_script( 'smart-schedule-ui', 'SmartScheduleData', self::config() );
    }

    public static function block_assets() {
        if ( ! self::eligible_screen() ) {
            return;
        }
        self::shared_assets();
        wp_enqueue_script( 'smart-schedule-editor', SMART_SCHEDULE_URL . 'assets/editor.js', array( 'smart-schedule-ui', 'wp-element', 'wp-data', 'wp-editor', 'wp-components', 'wp-i18n' ), SMART_SCHEDULE_VERSION, true );
        wp_set_script_translations( 'smart-schedule-editor', 'smart-schedule', SMART_SCHEDULE_DIR . 'languages' );
    }

    public static function admin_assets( $hook ) {
        if ( 'settings_page_smart-schedule' === $hook ) {
            wp_enqueue_style( 'smart-schedule-admin', SMART_SCHEDULE_URL . 'assets/admin.css', array(), SMART_SCHEDULE_VERSION );
            return;
        }
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! self::eligible_screen() ) {
            return;
        }
        $screen = get_current_screen();
        if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
            return;
        }
        if ( ! self::options()['classic_enabled'] ) {
            return;
        }
        self::shared_assets();
        wp_enqueue_script( 'smart-schedule-classic', SMART_SCHEDULE_URL . 'assets/classic.js', array( 'smart-schedule-ui', 'jquery', 'wp-i18n' ), SMART_SCHEDULE_VERSION, true );
        wp_set_script_translations( 'smart-schedule-classic', 'smart-schedule', SMART_SCHEDULE_DIR . 'languages' );
        global $post;
        $post_id = $post instanceof WP_Post ? (int) $post->ID : 0;
        wp_add_inline_script( 'smart-schedule-classic', 'window.SmartScheduleClassic = ' . wp_json_encode( array(
            'postId'   => $post_id,
            'status'   => $post instanceof WP_Post ? $post->post_status : 'draft',
            'dateGmt'  => $post instanceof WP_Post && '0000-00-00 00:00:00' !== $post->post_date_gmt ? str_replace( ' ', 'T', $post->post_date_gmt ) . 'Z' : '',
        ) ) . ';', 'before' );
    }

	/**
	 * REST field intentionally used only when an editor stages a schedule through this plugin.
	 * It is not persistent post meta. Core's date handling would otherwise recompute GMT
	 * from a site-local wall time and lose the chosen DST fold.
	 *
	 * Note for REST clients: the intent must be accompanied by a site-local `date`
	 * parameter matching the converted instant. Requests that send only `date_gmt`
	 * plus the intent are rejected with 409 (smart_schedule_date_mismatch) by design.
	 */
	public static function register_utc_intent() {
		$s = self::options();
		if ( ! $s['enabled'] ) {
			return;
		}
		foreach ( self::available_post_types() as $type => $obj ) {
			if ( empty( $obj->show_in_rest ) ) {
				continue;
			}
			if ( ! $s['all_post_types'] && ! in_array( $type, $s['post_types'], true ) ) {
				continue;
			}
            register_rest_field( $type, 'smart_schedule_utc_intent', array(
                'get_callback'    => function () { return ''; },
                'update_callback' => function () { return true; },
                'schema'          => array(
                    'description' => 'One-save UTC timestamp specified by Smart Schedule.',
                    'type'        => 'string',
                    'context'     => array( 'edit' ),
                    'readonly'    => false,
                ),
            ) );
            add_filter( 'rest_pre_insert_' . $type, array( __CLASS__, 'apply_utc_intent' ), 10, 2 );
        }
    }

    public static function apply_utc_intent( $prepared, $request ) {
        $raw = $request->get_param( 'smart_schedule_utc_intent' );
        if ( ! $raw ) {
            return $prepared;
        }
        if ( is_wp_error( $prepared ) ) {
            return $prepared;
        }
		$converted = Smart_Schedule_Time::from_utc( $raw, wp_timezone()->getName() );
		if ( is_wp_error( $converted ) ) {
			return new WP_Error( 'smart_schedule_invalid_intent', __( 'The UTC publication date is invalid.', 'smart-schedule' ), array( 'status' => 400 ) );
		}
		$post_id = absint( $request->get_param( 'id' ) );
		$saved_post = $post_id ? get_post( $post_id ) : null;
		$was_published = $saved_post && in_array( $saved_post->post_status, array( 'publish', 'private' ), true );
		$requested_status = $request->get_param( 'status' );
		// A live post must not be silently unpublished by moving it to a future
		// date. Enforce this server-side too: the editor checks alone are
		// bypassable by any REST client that sets smart_schedule_utc_intent.
		// An explicit demotion to draft/pending in the same request is allowed,
		// because that is a deliberate unpublish-and-reschedule action.
		$explicit_demotion = is_string( $requested_status ) && in_array( $requested_status, array( 'draft', 'pending' ), true );
		if ( $was_published && $converted['future'] && ! $explicit_demotion ) {
			return new WP_Error( 'smart_schedule_published_future', __( 'A published post cannot be moved to a future date. Return it to draft first, then schedule it.', 'smart-schedule' ), array( 'status' => 400 ) );
		}
		$editing_existing_publication = $was_published && ! $converted['future'] && ( null === $requested_status || in_array( $requested_status, array( 'publish', 'private' ), true ) );
		if ( ! $converted['eligible'] && ! $editing_existing_publication ) {
			return new WP_Error( 'smart_schedule_invalid_intent', __( 'The UTC time must be in the future for scheduling. Past dates can only be used when editing an already published or private post.', 'smart-schedule' ), array( 'status' => 400 ) );
		}
		$posted_date = $request->get_param( 'date' );
		if ( ! is_string( $posted_date ) || substr( $posted_date, 0, 19 ) !== $converted['site']['iso_local'] ) {
			return new WP_Error( 'smart_schedule_date_mismatch', __( 'The website timezone or date changed since Smart Schedule was applied. Reopen the scheduler and confirm.', 'smart-schedule' ), array( 'status' => 409 ) );
		}
        $prepared->post_date = str_replace( 'T', ' ', $converted['site']['iso_local'] );
        $prepared->post_date_gmt = str_replace( array( 'T', 'Z' ), array( ' ', '' ), $converted['utc'] );
        return $prepared;
    }

    public static function routes() {
        register_rest_route( 'smart-schedule/v1', '/convert', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'convert' ),
            'permission_callback' => array( __CLASS__, 'can_convert' ),
        ) );
        register_rest_route( 'smart-schedule/v1', '/format', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'format' ),
            'permission_callback' => array( __CLASS__, 'can_convert' ),
        ) );
        register_rest_route( 'smart-schedule/v1', '/preferences', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'save_preferences' ),
            'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
        ) );
    }

    public static function can_convert( $request ) {
        $id = absint( $request->get_param( 'post_id' ) );
        if ( $id ) {
            if ( ! current_user_can( 'edit_post', $id ) ) {
                return new WP_Error( 'rest_forbidden', __( 'You cannot edit this post.', 'smart-schedule' ), array( 'status' => 403 ) );
            }
            return true;
        }
        // Before the initial autosave there may be no post ID. Only permit
        // editors with a configured post type capability.
        $settings = self::options();
        $permitted_types = $settings['all_post_types'] ? array_keys( self::available_post_types() ) : $settings['post_types'];
        foreach ( $permitted_types as $post_type ) {
            $obj = get_post_type_object( $post_type );
            if ( $obj && current_user_can( $obj->cap->edit_posts ) ) {
                return true;
            }
        }
        return new WP_Error( 'rest_forbidden', __( 'You cannot edit posts.', 'smart-schedule' ), array( 'status' => 403 ) );
    }

    public static function convert( $request ) {
        $res = Smart_Schedule_Time::preview(
            $request->get_param( 'date' ),
            $request->get_param( 'time' ),
            $request->get_param( 'timezone' ),
            $request->get_param( 'choice' )
        );
        if ( is_wp_error( $res ) ) {
            $res->add_data( array( 'status' => 400 ) );
        }
        return $res;
    }

    public static function format( $request ) {
        $res = Smart_Schedule_Time::from_utc( $request->get_param( 'utc' ), $request->get_param( 'timezone' ) );
        if ( is_wp_error( $res ) ) {
            $res->add_data( array( 'status' => 400 ) );
        }
        return $res;
    }

    public static function save_preferences( $request ) {
        if ( ! self::options()['remember_editor'] ) {
            return new WP_Error( 'preferences_disabled', __( 'Editor preferences are disabled.', 'smart-schedule' ), array( 'status' => 403 ) );
        }
        $prefs = self::prefs();
        $zone = $request->get_param( 'timezone' );
        $clock = $request->get_param( 'clock' );
        if ( null !== $zone ) {
            if ( ! self::allowed_zone( $zone ) ) {
                return new WP_Error( 'invalid_timezone', __( 'Invalid timezone.', 'smart-schedule' ), array( 'status' => 400 ) );
            }
            $prefs['timezone'] = $zone;
        }
        if ( null !== $clock ) {
            if ( ! in_array( (string) $clock, array( '12', '24' ), true ) ) {
                return new WP_Error( 'invalid_clock', __( 'Clock format must be 12 or 24.', 'smart-schedule' ), array( 'status' => 400 ) );
            }
            $prefs['clock'] = (string) $clock;
        }
        $favourites = $request->get_param( 'favorites' );
        if ( null !== $favourites ) {
            if ( ! is_array( $favourites ) || count( $favourites ) > 12 ) {
                return new WP_Error( 'invalid_favorites', __( 'Select up to 12 favourite timezones.', 'smart-schedule' ), array( 'status' => 400 ) );
            }
            foreach ( $favourites as $item ) {
                if ( ! is_string( $item ) || ! self::allowed_zone( $item ) ) {
                    return new WP_Error( 'invalid_favorites', __( 'One or more favourite timezones are invalid.', 'smart-schedule' ), array( 'status' => 400 ) );
                }
            }
            $filtered = array_values( array_unique( $favourites ) );
            if ( count( $filtered ) > 12 ) {
                return new WP_Error( 'invalid_favorites', __( 'Select up to 12 favourite timezones.', 'smart-schedule' ), array( 'status' => 400 ) );
            }
            $prefs['favorites'] = $filtered;
        }
        update_user_meta( get_current_user_id(), self::PREFS, $prefs );
        return array( 'saved' => true );
    }
}
