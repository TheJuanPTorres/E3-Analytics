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

    /**
     * Usuarios que se registraron Y se inscribieron a algún curso, ambos dentro
     * de la misma ventana. Es el numerador de activity_rate.
     *
     * OJO: la query cruza dos columnas que están en zonas horarias distintas.
     * No unificar los cuatro parámetros.
     *   - wp_users.user_registered -> UTC   ($start_utc / $end_utc)
     *   - wp_posts.post_date       -> local ($start_local / $end_local)
     *
     * @param string $start_utc   Límite inicial UTC (user_registered).
     * @param string $end_utc     Límite final UTC (user_registered).
     * @param string $post_type   Post type de inscripción.
     * @param string $start_local Límite inicial local (post_date).
     * @param string $end_local   Límite final local (post_date).
     * @return int
     */
    public function count_registered_and_enrolled_between( $start_utc, $end_utc, $post_type, $start_local, $end_local ) {
        global $wpdb;

        if ( ! $start_utc || ! $end_utc || ! $start_local || ! $end_local ) {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT u.ID)
                 FROM {$wpdb->users} u
                 INNER JOIN {$wpdb->posts} p
                   ON p.post_author = u.ID
                 WHERE u.user_registered BETWEEN %s AND %s
                   AND p.post_type   = %s
                   AND p.post_status IN ('publish','completed')
                   AND p.post_parent > 0
                   AND p.post_date BETWEEN %s AND %s",
                $start_utc,
                $end_utc,
                $post_type,
                $start_local,
                $end_local
            )
        );
    }

    public function total_users() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
}
