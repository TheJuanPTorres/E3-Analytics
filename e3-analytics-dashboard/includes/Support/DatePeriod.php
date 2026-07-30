<?php
namespace E3_Analytics\Support;

if ( ! defined( 'ABSPATH' ) ) exit;

final class DatePeriod {
    /**
     * Resolve date ranges for a period (days).
     *
     * @param string|int|null $period_override Optional period override (7/30/90/365/all).
     */
    public static function resolve( $period_override = null ) {
        $period = null;

        if ( null !== $period_override && $period_override !== '' ) {
            $period = sanitize_text_field( (string) $period_override );
        }

        if ( null === $period ) {
            $period = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : '30';
        }
        $valid_periods = array('7','30','90','365','all');
        if ( ! in_array($period, $valid_periods, true) ) $period = '30';

        if ( $period === 'all' ) {
            $now = current_time('timestamp');

            global $wpdb;
            $post_type = apply_filters( 'e3a_enrollment_post_type', 'tutor_enrolled' );

            $min = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT MIN(post_date)
                     FROM {$wpdb->posts}
                     WHERE post_type = %s
                       AND post_status IN ('publish','completed')
                       AND post_parent > 0",
                    $post_type
                )
            );

            $current_end   = date_i18n('Y-m-d H:i:s', $now);
            $current_start = $min ? (string) $min : date_i18n('Y-m-d H:i:s', strtotime('-3650 days', $now));

            return array(
                'period_key'    => 'all',
                'is_all'        => true,
                'period_int'    => 0,
                'current_start' => $current_start,
                'current_end'   => $current_end,
                'prev_start'    => '',
                'prev_end'      => '',
            );
        }


        $period_int = (int) $period;
        $now        = current_time('timestamp');

        $current_end   = date_i18n('Y-m-d H:i:s', $now);
        $current_start = date_i18n('Y-m-d H:i:s', strtotime("-{$period_int} days", $now));

        $prev_end   = date_i18n('Y-m-d H:i:s', strtotime("-{$period_int} days", $now));
        $prev_start = date_i18n('Y-m-d H:i:s', strtotime("-" . (2*$period_int) . " days", $now));

        return array(
            'period_key'    => (string) $period,
            'is_all'        => false,
            'period_int'    => $period_int,
            'current_start' => $current_start,
            'current_end'   => $current_end,
            'prev_start'    => $prev_start,
            'prev_end'      => $prev_end,
        );
    }
}
