<?php
/**
 * Strict, timezone-aware scheduling conversion without dependence on PHP's default timezone.
 *
 * @package SmartSchedule
 */
if ( ! defined( 'ABSPATH' ) && ! defined( 'SMART_SCHEDULE_TESTING' ) ) {
    exit;
}

final class Smart_Schedule_Time {
    /** @return DateTimeZone|WP_Error */
    public static function zone( $name ) {
        if ( ! is_string( $name ) || strlen( $name ) > 100 ) {
            return new WP_Error( 'invalid_timezone', __( 'Choose a valid timezone.', 'smart-schedule' ) );
        }
        $name = trim( $name );
        // Accept canonical geographic identifiers, UTC and the site's own fixed offset only.
        $allowed = DateTimeZone::listIdentifiers( DateTimeZone::ALL_WITH_BC );
        $site    = wp_timezone()->getName();
        if ( 'UTC' !== $name && ! in_array( $name, $allowed, true ) && $name !== $site ) {
            return new WP_Error( 'invalid_timezone', __( 'That timezone is not available on this server.', 'smart-schedule' ) );
        }
        try {
            return new DateTimeZone( $name );
        } catch ( Exception $e ) {
            return new WP_Error( 'invalid_timezone', __( 'That timezone could not be loaded.', 'smart-schedule' ) );
        }
    }

    /**
     * Return every matching UTC instant for a local wall clock time. Zero = DST gap,
     * two = DST overlap. Uses transition offsets rather than permissive PHP parsing.
     *
     * @return array|WP_Error Array of DateTimeImmutable instances, ordered earliest first.
     */
    public static function candidates( $date, $time, $zone ) {
        if ( ! is_string( $date ) || ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $d ) ||
             ! checkdate( (int) $d[2], (int) $d[3], (int) $d[1] ) ||
             (int) $d[1] < 1970 || (int) $d[1] > 2100 ||
             ! is_string( $time ) || ! preg_match( '/^([0-9]{2}):([0-9]{2})$/D', $time, $t ) ||
             (int) $t[1] > 23 || (int) $t[2] > 59 ) {
            return new WP_Error( 'invalid_datetime', __( 'Enter a valid date (1970–2100) and time (00:00–23:59).', 'smart-schedule' ) );
        }
        $tz = $zone instanceof DateTimeZone ? $zone : self::zone( $zone );
        if ( is_wp_error( $tz ) ) {
            return $tz;
        }
        $wall = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date . ' ' . $time . ':00', new DateTimeZone( 'UTC' ) );
        if ( ! $wall ) {
            return new WP_Error( 'invalid_datetime', __( 'Unable to read the selected date and time.', 'smart-schedule' ) );
        }
        $wall_ts = $wall->getTimestamp();
        $offsets = array( $tz->getOffset( $wall ) );
        $transitions = $tz->getTransitions( $wall_ts - 3 * DAY_IN_SECONDS, $wall_ts + 3 * DAY_IN_SECONDS );
        if ( is_array( $transitions ) ) {
            foreach ( $transitions as $transition ) {
                $offsets[] = (int) $transition['offset'];
            }
        }
        $matches = array();
        foreach ( array_unique( $offsets ) as $offset ) {
            $candidate = ( new DateTimeImmutable( '@' . ( $wall_ts - $offset ) ) )->setTimezone( $tz );
            if ( $candidate->format( 'Y-m-d H:i' ) === $date . ' ' . $time ) {
                $matches[ $candidate->getTimestamp() ] = $candidate->setTimezone( new DateTimeZone( 'UTC' ) );
            }
        }
        ksort( $matches, SORT_NUMERIC );
        return array_values( $matches );
    }

    public static function format_instant( DateTimeImmutable $utc, DateTimeZone $zone ) {
        $local = $utc->setTimezone( $zone );
        $offset = $local->getOffset();
        $sign = $offset < 0 ? '-' : '+';
        $abs = abs( $offset );
        return array(
            'date'        => $local->format( 'Y-m-d' ),
            'time'        => $local->format( 'H:i' ),
            'iso_local'   => $local->format( 'Y-m-d\TH:i:s' ),
            'weekday'     => $local->format( 'l' ),
            'abbrev'      => $local->format( 'T' ),
            'zone'        => $zone->getName(),
            'offset'      => $sign . sprintf( '%02d:%02d', floor( $abs / 3600 ), floor( ( $abs % 3600 ) / 60 ) ),
            'offset_secs' => $offset,
        );
    }

    public static function preview( $date, $time, $timezone, $choice = '' ) {
        $zone = self::zone( $timezone );
        if ( is_wp_error( $zone ) ) {
            return $zone;
        }
        $matches = self::candidates( $date, $time, $zone );
        if ( is_wp_error( $matches ) ) {
            return $matches;
        }
        if ( ! count( $matches ) ) {
            return array( 'status' => 'nonexistent', 'message' => __( 'This local time does not exist in the selected timezone because of a clock change. Choose another time.', 'smart-schedule' ) );
        }
        if ( count( $matches ) > 1 && ! in_array( $choice, array( 'first', 'second' ), true ) ) {
            $choices = array();
            foreach ( $matches as $i => $instant ) {
                $representation = self::format_instant( $instant, $zone );
                $choices[] = array(
                    'value'  => 0 === $i ? 'first' : 'second',
                    'label'  => sprintf( 0 === $i ? __( 'First occurrence: %1$s %2$s (UTC%3$s)', 'smart-schedule' ) : __( 'Second occurrence: %1$s %2$s (UTC%3$s)', 'smart-schedule' ), $representation['time'], $representation['abbrev'], $representation['offset'] ),
                    'utc'    => $instant->format( 'Y-m-d\TH:i:s\Z' ),
                );
            }
            return array( 'status' => 'ambiguous', 'message' => __( 'This local time happens twice because of a clock change. Choose which occurrence you mean.', 'smart-schedule' ), 'choices' => $choices );
        }
        $instant = count( $matches ) > 1 && 'second' === $choice ? $matches[1] : $matches[0];
        return self::build( $instant, $zone );
    }

    public static function lead_seconds() {
        if ( function_exists( 'get_option' ) ) {
            $opts = get_option( 'smart_schedule_settings', array() );
            $minutes = is_array( $opts ) && isset( $opts['lead_minutes'] ) ? (int) $opts['lead_minutes'] : 2;
            return max( 0, min( 60, $minutes ) ) * 60;
        }
        return 120;
    }

    public static function build( DateTimeImmutable $instant, DateTimeZone $selected ) {
        $utc = $instant->setTimezone( new DateTimeZone( 'UTC' ) );
        $site_local = $utc->setTimezone( wp_timezone() );
        $site_matches = self::candidates( $site_local->format( 'Y-m-d' ), $site_local->format( 'H:i' ), wp_timezone() );
        $same_wall = self::candidates( $utc->setTimezone( $selected )->format( 'Y-m-d' ), $utc->setTimezone( $selected )->format( 'H:i' ), $selected );
        $fold = is_array( $same_wall ) && count( $same_wall ) > 1 ? ( $same_wall[0]->getTimestamp() === $utc->getTimestamp() ? 'first' : 'second' ) : '';
        return array(
            'status'   => 'valid',
            'choice'   => $fold,
            'siteAmbiguous' => is_array( $site_matches ) && count( $site_matches ) > 1,
            'eligible' => $utc->getTimestamp() > time() + (int) Smart_Schedule_Time::lead_seconds(),
            'utc'      => $utc->format( 'Y-m-d\TH:i:s\Z' ),
            'selected' => self::format_instant( $utc, $selected ),
            'site'     => self::format_instant( $utc, wp_timezone() ),
            'gmt'      => self::format_instant( $utc, new DateTimeZone( 'UTC' ) ),
            'future'   => $utc->getTimestamp() > time(),
        );
    }

    public static function from_utc( $utc_string, $timezone ) {
        $zone = self::zone( $timezone );
        if ( is_wp_error( $zone ) ) {
            return $zone;
        }
        if ( ! is_string( $utc_string ) || ! preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $utc_string ) ) {
            return new WP_Error( 'invalid_utc', __( 'Enter a valid UTC timestamp.', 'smart-schedule' ) );
        }
        $utc = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $utc_string, new DateTimeZone( 'UTC' ) );
        $errors = DateTimeImmutable::getLastErrors();
        if ( ! $utc || ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || $utc->format( 'Y-m-d\TH:i:s\Z' ) !== $utc_string ) {
            return new WP_Error( 'invalid_utc', __( 'The UTC timestamp is invalid.', 'smart-schedule' ) );
        }
        // Mirror the year bounds enforced by candidates() so both entry points
        // reject the same range (no year-0000 or year-9999 publication dates).
        $year = (int) $utc->format( 'Y' );
        if ( $year < 1970 || $year > 2100 ) {
            return new WP_Error( 'invalid_utc', __( 'The UTC timestamp must be between 1970 and 2100.', 'smart-schedule' ) );
        }
        return self::build( $utc, $zone );
    }
}
