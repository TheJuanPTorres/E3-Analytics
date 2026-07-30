<?php
namespace E3_Analytics\Services;

use E3_Analytics\Support\DatePeriod;
use E3_Analytics\Support\Math;
use E3_Analytics\Repositories\UsersRepository;
use E3_Analytics\Repositories\EnrollmentsRepository;
use E3_Analytics\Integrations\TutorLms;

if ( ! defined( 'ABSPATH' ) ) exit;

final class MetricsService {

    public function get_dashboard_data( $period_override = null ) {
        $dates = DatePeriod::resolve( $period_override );

        $usersRepo  = new UsersRepository();
        $enrollRepo = new EnrollmentsRepository();
        $tutor      = new TutorLms();

        $period        = (int) ($dates['period_int'] ?? 30);
        $current_start = $dates['current_start'] ?? '';
        $current_end   = $dates['current_end'] ?? '';
        $prev_start    = $dates['prev_start'] ?? '';
        $prev_end      = $dates['prev_end'] ?? '';

        /*
         * Límites en UTC. wp_users.user_registered lo escribe WP core en UTC,
         * mientras wp_posts.post_date está en hora local del sitio. Estas claves
         * son copia literal de las locales salvo en modo 'calendar_utc', así que
         * acá no hace falta ningún condicional de modo.
         */
        $current_start_utc = $dates['current_start_utc'] ?? $current_start;
        $current_end_utc   = $dates['current_end_utc'] ?? $current_end;
        $prev_start_utc    = $dates['prev_start_utc'] ?? $prev_start;
        $prev_end_utc      = $dates['prev_end_utc'] ?? $prev_end;

        $period_key    = (string) ( $dates['period_key'] ?? '' );
        $is_all        = (bool) ( $dates['is_all'] ?? false );
        $has_compare   = ( $prev_start !== '' && $prev_end !== '' );

        // user_registered: UTC.
        $current_new_users  = $usersRepo->count_registered_between($current_start_utc, $current_end_utc);
        $previous_new_users = $has_compare ? $usersRepo->count_registered_between($prev_start_utc, $prev_end_utc) : null;
        $growth_new_users   = $has_compare ? Math::growth_percent($current_new_users, $previous_new_users) : null;

        $current_rows         = $enrollRepo->rows_between($current_start, $current_end);
        $prev_rows            = $has_compare ? $enrollRepo->rows_between($prev_start, $prev_end) : [];
        $current_enrollments  = count($current_rows);
        $previous_enrollments = $has_compare ? count($prev_rows) : null;
        $growth_enrollments   = $has_compare ? Math::growth_percent($current_enrollments, $previous_enrollments) : null;

        $current_first_time_enrollments  = $enrollRepo->first_time_enrollments_count($current_start, $current_end);
        $previous_first_time_enrollments = $has_compare ? $enrollRepo->first_time_enrollments_count($prev_start, $prev_end) : null;
        $growth_first_time_enrollments   = $has_compare ? Math::growth_percent($current_first_time_enrollments, $previous_first_time_enrollments) : null;

        $first_enroll_map = $enrollRepo->first_enrollment_map_until($current_end);

        $users_with_enroll = [];
        $current_completed = 0;
        $course_map = [];
        $completed_cache = [];

        foreach ($current_rows as $row) {
            $course_id = (int) ($row['course_id'] ?? 0);
            $user_id   = (int) ($row['user_id'] ?? 0);
            if ($course_id <= 0 || $user_id <= 0) continue;

            if (!isset($course_map[$course_id])) {
                $course_map[$course_id] = [
                    'current_enroll'             => 0,
                    'current_completed'          => 0,
                    'previous_enroll'            => 0,
                    'current_first_time_enroll'  => 0,
                    'current_returning_enroll'   => 0,
                    'previous_first_time_enroll' => 0,
                    'previous_returning_enroll'  => 0,
                ];
            }

            $course_map[$course_id]['current_enroll']++;
            $users_with_enroll[$user_id] = true;

            $ck = $course_id . '|' . $user_id;
            if ( ! isset( $completed_cache[ $ck ] ) ) {
                $completed_cache[ $ck ] = $tutor->is_effectively_completed( $course_id, $user_id );
            }
            if ( $completed_cache[ $ck ] ) {
                $course_map[$course_id]['current_completed']++;
                $current_completed++;
            }

            $key        = $course_id . '|' . $user_id;
            $first_date = $first_enroll_map[$key] ?? null;

            if ($first_date && $first_date >= $current_start && $first_date <= $current_end) {
                $course_map[$course_id]['current_first_time_enroll']++;
            } else {
                $course_map[$course_id]['current_returning_enroll']++;
            }
        }

        foreach ($prev_rows as $row) {
            $course_id = (int) ($row['course_id'] ?? 0);
            $user_id   = (int) ($row['user_id'] ?? 0);
            if ($course_id <= 0 || $user_id <= 0) continue;

            if (!isset($course_map[$course_id])) {
                $course_map[$course_id] = [
                    'current_enroll'             => 0,
                    'current_completed'          => 0,
                    'previous_enroll'            => 0,
                    'current_first_time_enroll'  => 0,
                    'current_returning_enroll'   => 0,
                    'previous_first_time_enroll' => 0,
                    'previous_returning_enroll'  => 0,
                ];
            }

            $course_map[$course_id]['previous_enroll']++;

            $key        = $course_id . '|' . $user_id;
            $first_date = $first_enroll_map[$key] ?? null;

            if ($first_date && $first_date >= $prev_start && $first_date <= $prev_end) {
                $course_map[$course_id]['previous_first_time_enroll']++;
            } else {
                $course_map[$course_id]['previous_returning_enroll']++;
            }
        }

        $students_with_enrollments = count($users_with_enroll);
        $active_users = $students_with_enrollments;

        $cross_course_users = $enrollRepo->cross_course_users_count($current_start, $current_end, $is_all);

        $completion_rate = 0;
        if ($current_enrollments > 0) {
            $completion_rate = round(($current_completed / $current_enrollments) * 100, 1);
        }

        $dropout_rate = max(0, 100 - $completion_rate);

        $activity_rate = 0;
        if ($current_new_users > 0) {
            $activity_rate = round(($students_with_enrollments / $current_new_users) * 100, 1);
        }

        $performance = 0;
        if ($students_with_enrollments > 0) {
            $performance = round($current_completed / $students_with_enrollments, 2);
        }

        $courses_stats = [];
        foreach ($course_map as $course_id => $d) {
            $title = get_the_title($course_id);
            if (!$title) continue;

            $cur_enr   = (int) ($d['current_enroll'] ?? 0);
            $prev_enr  = (int) ($d['previous_enroll'] ?? 0);
            $cur_comp  = (int) ($d['current_completed'] ?? 0);
            $cur_first = (int) ($d['current_first_time_enroll'] ?? 0);
            $cur_recur = (int) ($d['current_returning_enroll'] ?? 0);

            $growth_course = $has_compare ? Math::growth_percent($cur_enr, $prev_enr) : null;
            $course_rate   = $cur_enr > 0 ? round(($cur_comp / $cur_enr) * 100, 1) : 0;

            $courses_stats[] = (object) [
                'course_id'                 => (int) $course_id,
                'course_title'              => $title,
                'current_enroll'            => $cur_enr,
                'previous_enroll'           => $has_compare ? $prev_enr : null,
                'current_completed'         => $cur_comp,
                'growth_enrollments'        => $growth_course,
                'completion_rate'           => $course_rate,
                'current_first_time_enroll' => $cur_first,
                'current_returning_enroll'  => $cur_recur,
            ];
        }

        usort($courses_stats, function($a, $b) {
            return $b->current_enroll <=> $a->current_enroll;
        });
        $courses_stats = array_slice($courses_stats, 0, 20);

        $chart_courses = array_slice($courses_stats, 0, 5);
        $chart_labels = [];
        $chart_first  = [];
        $chart_return = [];

        foreach ($chart_courses as $row) {
            $chart_labels[] = $row->course_title;
            $chart_first[]  = (int) $row->current_first_time_enroll;
            $chart_return[] = (int) $row->current_returning_enroll;
        }

        // La cohorte de retención se filtra por user_registered: límites en UTC.
        $retention = $this->get_retention_snapshot($current_start_utc, $current_end_utc, $is_all);
        $dau_mau   = $this->get_dau_mau_snapshot();

        return [
            'period'        => $period,
            'period_key'    => $period_key,
            'is_all'        => $is_all,
            'current_start' => $current_start,
            'current_end'   => $current_end,
            'prev_start'    => $prev_start,
            'prev_end'      => $prev_end,

            'kpis' => [
                'current_new_users'               => $current_new_users,
                'previous_new_users'              => $previous_new_users,
                'growth_new_users'                => $growth_new_users,

                'current_first_time_enrollments'  => $current_first_time_enrollments,
                'previous_first_time_enrollments' => $previous_first_time_enrollments,
                'growth_first_time_enrollments'   => $growth_first_time_enrollments,

                'current_enrollments'             => $current_enrollments,
                'previous_enrollments'            => $previous_enrollments,
                'growth_enrollments'              => $growth_enrollments,

                'active_users'                    => $active_users,
                'cross_course_users'              => $cross_course_users,
                'completion_rate'                 => $completion_rate,
                'current_completed'               => $current_completed,
                'dropout_rate'                    => $dropout_rate,
                'activity_rate'                   => $activity_rate,
                'performance'                     => $performance,
            ],

            'retention' => $retention,
            'dau_mau'   => $dau_mau,
            'courses_stats' => $courses_stats,

            'chart' => [
                'labels'    => $chart_labels,
                'first'     => $chart_first,
                'returning' => $chart_return,
            ],
        ];
    }

    /**
     * IMPORTANTE — acá conviven dos zonas horarias:
     *
     *  - $cohort_start / $cohort_end llegan en UTC y se usan SOLO para filtrar
     *    wp_users.user_registered (líneas de la cohorte y base de retención).
     *  - Los límites de actividad ($min_start_str / $now_str) se calculan acá
     *    dentro, en hora local, porque comparan contra wp_posts.post_date.
     *
     * Las dos queries de más abajo filtran las dos columnas a la vez: no
     * unificar estos dos juegos de límites.
     */
    private function get_retention_snapshot( $cohort_start, $cohort_end, $is_all = false ) {
        global $wpdb;

        $now_ts  = current_time('timestamp');
        $now_str = date_i18n('Y-m-d H:i:s', $now_ts);

        $post_type = apply_filters( 'e3a_enrollment_post_type', 'tutor_enrolled' );

        $windows = [7, 14, 30, 60, 90, 180, 365];
        $max_days = 365;

        $threshold_ts = [];
        foreach ( $windows as $days ) {
            $threshold_ts[$days] = strtotime( "-{$days} days", $now_ts );
        }

        $min_start_ts  = strtotime( "-{$max_days} days", $now_ts );
        $min_start_str = date_i18n( 'Y-m-d H:i:s', $min_start_ts );

        $active = [];
        foreach ( $windows as $days ) {
            $active[$days] = [];
        }

        if ( $is_all ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT post_author AS user_id, post_date
                     FROM {$wpdb->posts}
                     WHERE post_type = %s
                       AND post_status IN ('publish','completed')
                       AND post_parent > 0
                       AND post_date BETWEEN %s AND %s",
                    $post_type,
                    $min_start_str,
                    $now_str
                ),
                ARRAY_A
            );
        } else {
            if ( ! $cohort_start || ! $cohort_end ) {
                return [ 'base' => 0 ];
            }

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT p.post_author AS user_id, p.post_date
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->users} u
                       ON u.ID = p.post_author
                     WHERE p.post_type = %s
                       AND p.post_status IN ('publish','completed')
                       AND p.post_parent > 0
                       AND p.post_date BETWEEN %s AND %s
                       AND u.user_registered BETWEEN %s AND %s",
                    $post_type,
                    $min_start_str,
                    $now_str,
                    $cohort_start,
                    $cohort_end
                ),
                ARRAY_A
            );
        }

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $user_id = (int) ( $row['user_id'] ?? 0 );
                $date    = $row['post_date'] ?? null;

                if ( $user_id <= 0 || ! $date ) continue;

                $ts = strtotime( $date );
                if ( ! $ts ) continue;

                foreach ( $windows as $days ) {
                    if ( $ts >= $threshold_ts[$days] ) $active[$days][$user_id] = true;
                }
            }
        }

        $usersRepo = new UsersRepository();
        $base_users = $is_all ? $usersRepo->total_users() : $usersRepo->count_registered_between( $cohort_start, $cohort_end );

        // Histórico: cualquier actividad en cualquier momento (solo cohortes del período seleccionado, o todo si is_all).
        if ( $is_all ) {
            $historical_active = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT post_author)
                     FROM {$wpdb->posts}
                     WHERE post_type = %s
                       AND post_status IN ('publish','completed')
                       AND post_parent > 0",
                    $post_type
                )
            );
        } else {
            $historical_active = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT p.post_author)
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->users} u
                       ON u.ID = p.post_author
                     WHERE p.post_type = %s
                       AND p.post_status IN ('publish','completed')
                       AND p.post_parent > 0
                       AND u.user_registered BETWEEN %s AND %s",
                    $post_type,
                    $cohort_start,
                    $cohort_end
                )
            );
        }

        $result = [ 'base' => (int) $base_users ];

        foreach ( $windows as $days ) {
            $active_count = count( $active[$days] );
            $rate = $base_users > 0 ? round( ( $active_count / $base_users ) * 100, 1 ) : 0;

            $result[(string) $days] = [
                'active' => $active_count,
                'total'  => (int) $base_users,
                'rate'   => $rate,
            ];
        }

        $hist_rate = $base_users > 0 ? round( ( $historical_active / $base_users ) * 100, 1 ) : 0;
        $result['all'] = [
            'active' => (int) $historical_active,
            'total'  => (int) $base_users,
            'rate'   => $hist_rate,
        ];

        return $result;
    }

    private function get_dau_mau_snapshot() {
        global $wpdb;

        $now_ts  = current_time('timestamp');
        $now_str = date_i18n('Y-m-d H:i:s', $now_ts);

        $start_30_ts  = strtotime('-30 days', $now_ts);
        $start_30_str = date_i18n('Y-m-d H:i:s', $start_30_ts);

        $start_1_ts  = strtotime('-1 day', $now_ts);
        $start_1_str = date_i18n('Y-m-d H:i:s', $start_1_ts);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT post_author AS user_id, post_date
                 FROM {$wpdb->posts}
                 WHERE post_type = %s
                   AND post_status IN ('publish','completed')
                   AND post_parent > 0
                   AND post_date BETWEEN %s AND %s",
                apply_filters( 'e3a_enrollment_post_type', 'tutor_enrolled' ),
                $start_30_str,
                $now_str
            ),
            ARRAY_A
        );

        $dau_users = [];
        $mau_users = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $user_id = (int) ($row['user_id'] ?? 0);
                $date    = $row['post_date'] ?? null;

                if ($user_id <= 0 || !$date) continue;

                $ts = strtotime($date);
                if (!$ts) continue;

                $mau_users[$user_id] = true;
                if ($ts >= $start_1_ts) $dau_users[$user_id] = true;
            }
        }

        $dau = count($dau_users);
        $mau = count($mau_users);
        $ratio = $mau > 0 ? round(($dau / $mau) * 100, 1) : 0;

        return [
            'dau'   => $dau,
            'mau'   => $mau,
            'ratio' => $ratio,
            'window_dau' => ['start' => $start_1_str, 'end' => $now_str],
            'window_mau' => ['start' => $start_30_str, 'end' => $now_str],
        ];
    }
}
