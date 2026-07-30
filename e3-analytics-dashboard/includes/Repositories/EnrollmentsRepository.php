<?php
namespace E3_Analytics\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

final class EnrollmentsRepository {
    private $post_type = 'tutor_enrolled';

    public function rows_between($start, $end) {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_parent AS course_id, post_author AS user_id, post_date AS enroll_date
                 FROM {$wpdb->posts}
                 WHERE post_type = %s
                   AND post_status IN ('publish','completed')
                   AND post_parent > 0
                   AND post_date BETWEEN %s AND %s",
                $this->post_type,
                $start,
                $end
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function first_enrollment_map_until($until_date) {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_parent AS course_id, post_author AS user_id, MIN(post_date) AS first_date
                 FROM {$wpdb->posts}
                 WHERE post_type = %s
                   AND post_status IN ('publish','completed')
                   AND post_parent > 0
                   AND post_date <= %s
                 GROUP BY post_parent, post_author",
                $this->post_type,
                $until_date
            ),
            ARRAY_A
        );

        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $c = (int) ($row['course_id'] ?? 0);
                $u = (int) ($row['user_id'] ?? 0);
                $d = $row['first_date'] ?? null;
                if ($c && $u && $d) $map["{$c}|{$u}"] = $d;
            }
        }
        return $map;
    }

    public function first_time_enrollments_count($start, $end) {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT e.post_author)
             FROM {$wpdb->posts} e
             WHERE e.post_type   = %s
               AND e.post_status IN ('publish','completed')
               AND e.post_parent > 0
               AND e.post_date BETWEEN %s AND %s
               AND NOT EXISTS (
                   SELECT 1
                   FROM {$wpdb->posts} e2
                   WHERE e2.post_type   = %s
                     AND e2.post_status IN ('publish','completed')
                     AND e2.post_parent > 0
                     AND e2.post_author = e.post_author
                     AND e2.post_date   < %s
               )",
            $this->post_type,
            $start,
            $end,
            $this->post_type,
            $start
        );

        return (int) $wpdb->get_var($sql);
    }

    
    /**
     * Count users who were already enrolled before the cohort start and enrolled into a NEW course during the period.
     * For 'all' historical view, counts users with 2+ distinct courses overall.
     */
    public function cross_course_users_count($start, $end, $is_all = false) {
        global $wpdb;

        if ($is_all) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT e.post_author
                    FROM {$wpdb->posts} e
                    WHERE e.post_type = %s
                      AND e.post_status IN ('publish','completed')
                      AND e.post_parent > 0
                    GROUP BY e.post_author
                    HAVING COUNT(DISTINCT e.post_parent) >= 2
                ) t",
                $this->post_type
            );

            return (int) $wpdb->get_var($sql);
        }

        if ( ! $start || ! $end ) return 0;

        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT e.post_author)
             FROM {$wpdb->posts} e
             WHERE e.post_type = %s
               AND e.post_status IN ('publish','completed')
               AND e.post_parent > 0
               AND e.post_date BETWEEN %s AND %s
               AND EXISTS (
                   SELECT 1
                   FROM {$wpdb->posts} p0
                   WHERE p0.post_type = %s
                     AND p0.post_status IN ('publish','completed')
                     AND p0.post_parent > 0
                     AND p0.post_author = e.post_author
                     AND p0.post_date < %s
               )
               AND NOT EXISTS (
                   SELECT 1
                   FROM {$wpdb->posts} p1
                   WHERE p1.post_type = %s
                     AND p1.post_status IN ('publish','completed')
                     AND p1.post_parent > 0
                     AND p1.post_author = e.post_author
                     AND p1.post_parent = e.post_parent
                     AND p1.post_date < %s
               )",
            $this->post_type,
            $start,
            $end,
            $this->post_type,
            $start,
            $this->post_type,
            $start
        );

        return (int) $wpdb->get_var($sql);
    }

public function post_type() {
        return $this->post_type;
    }
}
