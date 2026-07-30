<?php
namespace E3_Analytics\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

final class UsersRepository {
    public function count_registered_between($start, $end) {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->users}
                 WHERE user_registered BETWEEN %s AND %s",
                $start,
                $end
            )
        );
    }

    public function total_users() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
}
