<?php
namespace E3_Analytics\Services;

use E3_Analytics\Support\DatePeriod;
use E3_Analytics\Support\BucketHelper;
use E3_Analytics\Repositories\EnrollmentsRepository;
use E3_Analytics\Integrations\TutorLms;

if ( ! defined( 'ABSPATH' ) ) exit;

final class DropoutProgressService {
    use BucketHelper;

    /**
     * Build the dropout progress report.
     *
     * Optional args:
     * - period (7/30/90/365)
     * - course_id (int)
     * - bucket (string bucket key)
     * - q (string search by name/email)
     * - include_users (bool)
     */
    public function get_report( $args = [] ) {
        $args = is_array( $args ) ? $args : [];

        $period_override = $args['period'] ?? null;
        $dates = DatePeriod::resolve( $period_override );

        $enrollRepo = new EnrollmentsRepository();
        $tutor      = new TutorLms();

        $current_start = (string) ($dates['current_start'] ?? '');
        $current_end   = (string) ($dates['current_end'] ?? '');

        // Optional filter to reduce load when requesting a user list for a specific course.
        $filter_course_id = (int) ( $args['course_id'] ?? 0 );
        $rows = $enrollRepo->rows_between( $current_start, $current_end );

        $buckets = [
            '0_10'  => ['label' => '0–10%'],
            '11_25' => ['label' => '11–25%'],
            '26_50' => ['label' => '26–50%'],
            '51_75' => ['label' => '51–75%'],
            '76_99' => ['label' => '76–99%'],
        ];

        $seen            = [];
        $courses         = [];
        $completed_cache = [];
        $progress_cache  = [];

        $include_users = ! empty( $args['include_users'] );
        $filter_bucket = isset( $args['bucket'] ) ? (string) $args['bucket'] : '';
        $filter_q      = isset( $args['q'] ) ? (string) $args['q'] : '';
        $filter_q_norm = '';
        if ( $filter_q !== '' ) {
            $filter_q_norm = function_exists( 'mb_strtolower' )
                ? mb_strtolower( trim( $filter_q ) )
                : strtolower( trim( $filter_q ) );
        }

        $users_list = [];

        $total_dropouts = 0;
        $sum_progress_all = 0.0;

        foreach ($rows as $row) {
            $course_id = (int) ($row['course_id'] ?? 0);
            $user_id   = (int) ($row['user_id'] ?? 0);
            if ($course_id <= 0 || $user_id <= 0) continue;

            $pair = $course_id . '|' . $user_id;
            if (isset($seen[$pair])) continue;
            $seen[$pair] = true;

            $ck = $course_id . '|' . $user_id;
            if ( ! isset( $completed_cache[ $ck ] ) ) {
                $completed_cache[ $ck ] = $tutor->is_effectively_completed( $course_id, $user_id );
            }
            if ( $completed_cache[ $ck ] ) continue;

            if ( ! isset( $progress_cache[ $ck ] ) ) {
                $progress_cache[ $ck ] = (float) $tutor->course_progress_percent( $course_id, $user_id );
            }
            $progress = $progress_cache[ $ck ];
            if ($progress >= 100) continue; // >= 100% = finalizado aunque is_completed sea false
            if ($progress < 0) $progress = 0;

            $bucket_key = $this->bucket_key($progress);

            // Collect user list for the selected course (and optional bucket/search filters).
            if ( $include_users && $filter_course_id > 0 && $course_id === $filter_course_id ) {
                if ( $filter_bucket !== '' && $filter_bucket !== $bucket_key ) {
                    // skip
                } else {
                    $u = get_userdata( $user_id );
                    if ( $u ) {
                        $first = (string) get_user_meta( $user_id, 'first_name', true );
                        $last  = (string) get_user_meta( $user_id, 'last_name', true );
                        $name  = trim( ( $first . ' ' . $last ) );
                        if ( $name === '' ) $name = (string) $u->display_name;

                        $email = (string) $u->user_email;

                        if ( $filter_q_norm !== '' ) {
                            $hay_raw = $name . ' ' . $email . ' ' . (string) $u->user_login;
                            $hay = function_exists( 'mb_strtolower' ) ? mb_strtolower( $hay_raw ) : strtolower( $hay_raw );
                            $pos = function_exists( 'mb_strpos' ) ? mb_strpos( $hay, $filter_q_norm ) : strpos( $hay, $filter_q_norm );
                            if ( false === $pos ) {
                                // Not matching search.
                                goto e3a_continue_user_collect;
                            }
                        }

                        $users_list[] = [
                            'user_id'      => (int) $user_id,
                            'user_login'   => (string) $u->user_login,
                            'display_name' => (string) $name,
                            'user_email'   => (string) $email,
                            'progress'     => (float) round( $progress, 1 ),
                            'bucket'       => (string) $bucket_key,
                            'bucket_label' => (string) ( $buckets[ $bucket_key ]['label'] ?? '' ),
                            'enroll_date'  => (string) ( $row['enroll_date'] ?? '' ),
                            'course_id'    => (int) $course_id,
                            'course_title' => (string) get_the_title( $course_id ),
                        ];
                    }
                }
            }

            e3a_continue_user_collect:

            if (!isset($courses[$course_id])) {
                $courses[$course_id] = [
                    'course_id'    => $course_id,
                    'course_title' => (string) get_the_title($course_id),
                    'dropouts'     => 0,
                    'sum_progress' => 0.0,
                    'avg_progress' => 0.0,
                    'buckets'      => array_fill_keys(array_keys($buckets), 0),
                ];
            }

            $courses[$course_id]['dropouts']++;
            $courses[$course_id]['sum_progress'] += $progress;
            $courses[$course_id]['buckets'][$bucket_key]++;

            $total_dropouts++;
            $sum_progress_all += $progress;
        }

        $courses_list = array_values($courses);

        foreach ($courses_list as &$c) {
            $d = (int) ($c['dropouts'] ?? 0);
            $c['avg_progress'] = $d > 0 ? round(($c['sum_progress'] ?? 0) / $d, 1) : 0.0;
            unset($c['sum_progress']);
        }
        unset($c);

        usort($courses_list, function($a, $b){
            return (int)($b['dropouts'] ?? 0) <=> (int)($a['dropouts'] ?? 0);
        });

        $avg_all = $total_dropouts > 0 ? round($sum_progress_all / $total_dropouts, 1) : 0.0;

        $chart_top = array_slice($courses_list, 0, 10);
        $chart = $this->build_chart($chart_top, $buckets);

        if ( ! empty( $users_list ) ) {
            usort( $users_list, function( $a, $b ) {
                $pa = (float) ( $a['progress'] ?? 0 );
                $pb = (float) ( $b['progress'] ?? 0 );
                if ( $pa === $pb ) {
                    return strcmp( (string) ( $a['display_name'] ?? '' ), (string) ( $b['display_name'] ?? '' ) );
                }
                return $pa <=> $pb;
            } );
        }

        return [
            'dates' => $dates,
            'filters' => [
                'course_id' => (int) $filter_course_id,
                'bucket'    => (string) $filter_bucket,
                'q'         => (string) $filter_q,
            ],
            'summary' => [
                'total_dropouts' => (int) $total_dropouts,
                'avg_progress'   => (float) $avg_all,
                'courses_count'  => (int) count($courses_list),
            ],
            'buckets' => $buckets,
            'courses' => $courses_list,
            'chart'   => $chart,
            'users'   => $users_list,
        ];
    }

    private function build_chart($courses_top, $buckets) {
        $labels = [];
        foreach ($courses_top as $c) {
            $labels[] = (string) ($c['course_title'] ?: ('Curso #' . (int)($c['course_id'] ?? 0)));
        }

        $datasets = [];
        foreach ($buckets as $key => $meta) {
            $data = [];
            foreach ($courses_top as $c) {
                $data[] = (int) (($c['buckets'][$key] ?? 0));
            }
            $datasets[] = [
                'label'       => (string) $meta['label'],
                'data'        => $data,
                'borderWidth' => 1,
            ];
        }

        return [
            'labels'   => $labels,
            'datasets' => $datasets,
        ];
    }
}
