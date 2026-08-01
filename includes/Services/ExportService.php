<?php
namespace E3_Analytics\Services;

use E3_Analytics\Support\DatePeriod;
use E3_Analytics\Support\BucketHelper;
use E3_Analytics\Repositories\EnrollmentsRepository;
use E3_Analytics\Integrations\TutorLms;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ExportService {
    use BucketHelper;

    public function build( $scope, $key, $args = [] ) {
        $scope = (string) $scope;
        $key   = (string) $key;
        $args  = is_array( $args ) ? $args : [];

        if ( $scope === 'dashboard' ) {
            return $this->build_dashboard( $key, $args );
        }

        if ( $scope === 'dropout' ) {
            return $this->build_dropout( $key, $args );
        }

        if ( $scope === 'country' ) {
            return $this->build_country( $key, $args );
        }

        return null;
    }

    /**
     * Sufijo de período para el nombre del archivo.
     *
     * El sanitizador de Xlsx::download() permite '-' y '.', así que una clave
     * como '2026-03-01..2026-04-15' sobrevive intacta. Que el rango viaje en el
     * nombre es también la mitigación de la carrera de medianoche: si el export
     * se dispara cruzando la medianoche y resuelve una ventana distinta a la de
     * la pantalla, el archivo dice exactamente qué contiene.
     *
     * @param array $dates
     * @return string
     */
    private function filename_suffix( $dates ) {
        $key = (string) ( $dates['period_key'] ?? '' );

        return '' !== $key ? '-' . $key : '';
    }

    private function build_dashboard( $key, $args ) {
        $period = isset( $args['period'] ) ? (string) $args['period'] : null;
        $dates  = DatePeriod::resolve( $period );

        $start = (string) ( $dates['current_start'] ?? '' );
        $end   = (string) ( $dates['current_end'] ?? '' );

        // user_registered está en UTC. Copia literal de las locales salvo en
        // modo 'calendar_utc'.
        $start_utc = (string) ( $dates['current_start_utc'] ?? $start );
        $end_utc   = (string) ( $dates['current_end_utc'] ?? $end );

        $is_all = (bool) ( $dates['is_all'] ?? false );

        $sheets = [];
        $now = current_time( 'mysql' );

        $sheets[] = [
            'name' => 'Resumen',
            'rows' => [
                [ 'Generado', $now ],
                [ 'Período', (string) ( $dates['label'] ?? $dates['period_key'] ?? '' ) ],
                [ 'Rango', $start . ' — ' . $end ],
                [ 'Reporte', 'dashboard:' . $key ],
            ],
        ];

        switch ( $key ) {
            case 'new_users':
                $rows = $this->get_users_registered_between( $start_utc, $end_utc );
                $sheets[] = [ 'name' => 'Usuarios', 'rows' => $rows ];
                $this->maybe_add_user_meta_sheet( $sheets, $rows );
                return [ 'filename' => 'e3-dashboard-nuevos-registros' . $this->filename_suffix( $dates ), 'sheets' => $sheets ];

            case 'first_time_enrollments':
                $rows = $this->get_first_time_enrollments_between( $start, $end, $end );
                $sheets[] = [ 'name' => 'PrimerasInscrip', 'rows' => $rows ];
                $this->maybe_add_user_meta_sheet( $sheets, $rows, 'user_id' );
                return [ 'filename' => 'e3-dashboard-nuevos-inscritos' . $this->filename_suffix( $dates ), 'sheets' => $sheets ];

            case 'enrollments':
                $rows = $this->get_enrollments_between( $start, $end );
                $sheets[] = [ 'name' => 'Inscripciones', 'rows' => $rows ];
                $this->maybe_add_user_meta_sheet( $sheets, $rows, 'user_id' );
                return [ 'filename' => 'e3-dashboard-inscripciones' . $this->filename_suffix( $dates ), 'sheets' => $sheets ];

            case 'active_users':
                $rows = $this->get_active_users_between( $start, $end );
                $sheets[] = [ 'name' => 'ResumenPorUsuario', 'rows' => $rows ];
                $this->maybe_add_user_meta_sheet( $sheets, $rows, 'ID de usuario' );
                return [ 'filename' => 'e3-dashboard-usuarios-activos' . $this->filename_suffix( $dates ), 'sheets' => $sheets ];

            case 'cross_course_users':
                if ( $is_all ) {
                    $rows = $this->get_cross_course_users_all_time();
                } else {
                    $rows = $this->get_cross_course_users_between( $start, $end );
                }
                $sheets[] = [ 'name' => 'UsuariosOtroCurso', 'rows' => $rows ];
                $this->maybe_add_user_meta_sheet( $sheets, $rows, 'user_id' );
                return [ 'filename' => 'e3-dashboard-usuarios-otro-curso' . $this->filename_suffix( $dates ), 'sheets' => $sheets ];


            case 'performance':
            case 'chart_new_vs_returning':
            case 'courses_detail':
            case 'top_courses':
            case 'insights':
            case 'retention':
            default:
                /*
                 * 'courses_detail' es la tarjeta "Detalle por curso": la clienta
                 * pidió exportar UNA tabla, no seis hojas. Para esa key el
                 * archivo queda en Resumen + Cursos + ProgresoPorCurso.
                 * Las otras 5 keys de esta rama no cambian.
                 */
                $only_courses = ( 'courses_detail' === $key );

                $rows = $this->get_dashboard_metrics_snapshot( $dates );

                if ( ! $only_courses ) {
                    $sheets[] = [ 'name' => 'Métricas', 'rows' => $rows['metrics'] ?? [ [ 'Sin datos' ] ] ];
                }

                if ( ! empty( $rows['courses'] ) ) {
                    $sheets[] = [ 'name' => 'Cursos', 'rows' => $rows['courses'] ];
                }

                if ( ! $only_courses && ! empty( $rows['retention'] ) ) {
                    $sheets[] = [ 'name' => 'Retención', 'rows' => $rows['retention'] ];
                }

                if ( ! $only_courses && ! empty( $rows['dau_mau'] ) ) {
                    $sheets[] = [ 'name' => 'DAU_MAU', 'rows' => $rows['dau_mau'] ];
                }

                if ( $only_courses ) {
                    // Una fila por par usuario-curso. Sin consultas adicionales:
                    // reusa lo que el recorrido de inscripciones ya calcula.
                    $by_course = $this->get_active_users_by_course_between( $start, $end );
                    if ( count( $by_course ) > 1 ) {
                        $sheets[] = [ 'name' => 'ProgresoPorCurso', 'rows' => $by_course ];
                    }
                } else {
                    // Para todas las cards, intenta incluir también listados de usuarios/inscripciones del período.
                    $enroll_rows = $this->get_enrollments_between( $start, $end, 10000 );
                    if ( count( $enroll_rows ) > 1 ) {
                        $sheets[] = [ 'name' => 'Inscripciones', 'rows' => $enroll_rows ];
                    }

                    $active_rows = $this->get_active_users_between( $start, $end );
                    if ( count( $active_rows ) > 1 ) {
                        $sheets[] = [ 'name' => 'ResumenPorUsuario', 'rows' => $active_rows ];
                        $this->maybe_add_user_meta_sheet( $sheets, $active_rows, 'ID de usuario' );
                    }
                }

                // For retention export, also include active user lists by windows.
                if ( $key === 'retention' ) {
                    foreach ( [30,60,90] as $days ) {
                        $ul = $this->get_active_users_last_n_days( $days );
                        $sheets[] = [ 'name' => 'Activos' . $days . 'd', 'rows' => $ul ];
                    }
                    $this->maybe_add_user_meta_sheet( $sheets, $sheets[count($sheets)-1]['rows'] ?? [], 'user_id' );
                }

                $fileKey = $key ?: 'dashboard';
                return [ 'filename' => 'e3-dashboard-' . $fileKey . $this->filename_suffix( $dates ), 'sheets' => $sheets ];
        }
    }

    private function build_dropout( $key, $args ) {
        $period = isset( $args['period'] ) ? (string) $args['period'] : null;
        $dates  = DatePeriod::resolve( $period );

        $start = (string) ( $dates['current_start'] ?? '' );
        $end   = (string) ( $dates['current_end'] ?? '' );

        $is_all = (bool) ( $dates['is_all'] ?? false );

        $course_id = isset( $args['course_id'] ) ? (int) $args['course_id'] : 0;
        $bucket    = isset( $args['bucket'] ) ? (string) $args['bucket'] : '';
        $q         = isset( $args['q'] ) ? (string) $args['q'] : '';

        $sheets = [];
        $now = current_time( 'mysql' );

        $sheets[] = [
            'name' => 'Resumen',
            'rows' => [
                [ 'Generado', $now ],
                [ 'Período', (string) ( $dates['label'] ?? $dates['period_key'] ?? '' ) ],
                [ 'Rango', $start . ' — ' . $end ],
                [ 'Reporte', 'dropout:' . $key ],
                [ 'Curso ID', $course_id ],
                [ 'Rango filtro', $bucket ],
                [ 'Búsqueda', $q ],
            ],
        ];

        switch ( $key ) {
            case 'dropout_user_list':
                // Selected course users list.
                if ( $course_id <= 0 ) {
                    $sheets[] = [ 'name' => 'Usuarios', 'rows' => [ [ 'Selecciona un curso para exportar el listado de usuarios.' ] ] ];
                    return [ 'filename' => 'e3-dropout-usuarios' . $this->filename_suffix( $dates ), 'sheets' => $sheets ];
                }
                $rows = $this->get_dropout_users_by_course( $period, $course_id, $bucket, $q );
                $sheets[] = [ 'name' => 'Usuarios', 'rows' => $rows ];
                $this->maybe_add_user_meta_sheet( $sheets, $rows, 'user_id' );
                return [ 'filename' => 'e3-dropout-usuarios-curso-' . $course_id . $this->filename_suffix( $dates ), 'sheets' => $sheets ];

            case 'dropout_distribution':
            case 'dropout_course_detail':
            case 'dropout_total_users':
            default:
                $report = $this->get_dropout_report_snapshot( $start, $end );

                if ( ! empty( $report['courses'] ) ) {
                    $sheets[] = [ 'name' => 'Cursos', 'rows' => $report['courses'] ];
                }

                if ( ! empty( $report['distribution'] ) ) {
                    $sheets[] = [ 'name' => 'Distribución', 'rows' => $report['distribution'] ];
                }

                // Para todas las cards de abandono, incluye también el listado de usuarios (con límite razonable).
                $users = $this->get_all_dropout_users( $start, $end, 15000 );
                if ( count( $users ) > 1 ) {
                    $sheets[] = [ 'name' => 'Usuarios', 'rows' => $users ];
                    $this->maybe_add_user_meta_sheet( $sheets, $users, 'user_id' );
                }

                return [ 'filename' => 'e3-dropout-' . ( $key ?: 'reporte' ) . $this->filename_suffix( $dates ), 'sheets' => $sheets ];
        }
    }

    /** -----------------------------
     * Data builders
     */

    private function get_users_registered_between( $start, $end ) {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID
                 FROM {$wpdb->users}
                 WHERE user_registered BETWEEN %s AND %s
                 ORDER BY user_registered ASC",
                $start,
                $end
            ),
            ARRAY_A
        );

        $ids = [];
        foreach ( (array) $rows as $r ) {
            $ids[] = (int) ( $r['ID'] ?? 0 );
        }

        $out = [ $this->user_header() ];
        foreach ( $ids as $id ) {
            $row = $this->user_full_row( $id );
            if ( $row ) $out[] = $row;
        }
        return $out;
    }

    private function get_first_time_enrollments_between( $start, $end, $until ) {
        global $wpdb;

        $post_type = apply_filters( 'e3a_enrollment_post_type', 'tutor_enrolled' );

        $sql = $wpdb->prepare(
            "SELECT e.post_author AS user_id, e.post_parent AS course_id, e.post_date AS enroll_date
             FROM {$wpdb->posts} e
             INNER JOIN (
               SELECT post_author, MIN(post_date) AS first_date
               FROM {$wpdb->posts}
               WHERE post_type = %s
                 AND post_status IN ('publish','completed')
                 AND post_parent > 0
                 AND post_date <= %s
               GROUP BY post_author
             ) m ON m.post_author = e.post_author AND m.first_date = e.post_date
             WHERE e.post_type = %s
               AND e.post_status IN ('publish','completed')
               AND e.post_parent > 0
               AND e.post_date BETWEEN %s AND %s
             GROUP BY e.post_author
             ORDER BY e.post_date ASC",
            $post_type,
            $until,
            $post_type,
            $start,
            $end
        );

        $rows = $wpdb->get_results( $sql, ARRAY_A );
        $rows = is_array( $rows ) ? $rows : [];

        $out = [ array_merge( [ 'user_id' ], $this->user_header( false ) , [ 'course_id', 'course_title', 'enroll_date' ] ) ];
        foreach ( $rows as $r ) {
            $uid = (int) ( $r['user_id'] ?? 0 );
            $cid = (int) ( $r['course_id'] ?? 0 );
            if ( $uid <= 0 ) continue;

            $urow = $this->user_full_row( $uid );
            if ( ! $urow ) continue;

            $out[] = array_merge(
                [ $uid ],
                $urow,
                [ $cid, (string) get_the_title( $cid ), (string) ( $r['enroll_date'] ?? '' ) ]
            );
        }

        return $out;
    }

    private function get_enrollments_between( $start, $end, $limit = null ) {
        $repo  = new EnrollmentsRepository();
        $tutor = new TutorLms();

        $rows = $repo->rows_between( $start, $end );
        if ( is_int( $limit ) && $limit > 0 && count( $rows ) > $limit ) {
            $rows = array_slice( $rows, 0, $limit );
        }

        $out = [
            [
                'enroll_date',
                'course_id',
                'course_title',
                'user_id',
                'user_login',
                'display_name',
                'email',
                'roles',
                'progress_%',
                'completed',
            ]
        ];

        $cache = [];
        foreach ( $rows as $r ) {
            $cid = (int) ( $r['course_id'] ?? 0 );
            $uid = (int) ( $r['user_id'] ?? 0 );
            if ( $cid <= 0 || $uid <= 0 ) continue;

            $u = get_userdata( $uid );
            if ( ! $u ) continue;

            $cacheKey = $cid . '|' . $uid;
            if ( ! isset( $cache[$cacheKey] ) ) {
                $cache[$cacheKey] = [
                    'progress'  => round( (float) $tutor->course_progress_percent( $cid, $uid ), 1 ),
                    'completed' => $tutor->is_effectively_completed( $cid, $uid ) ? 'yes' : 'no',
                ];
            }

            $out[] = [
                (string) ( $r['enroll_date'] ?? '' ),
                $cid,
                (string) get_the_title( $cid ),
                $uid,
                (string) $u->user_login,
                (string) $u->display_name,
                (string) $u->user_email,
                is_array( $u->roles ) ? implode( ',', $u->roles ) : '',
                (float) $cache[$cacheKey]['progress'],
                (string) $cache[$cacheKey]['completed'],
            ];
        }

        return $out;
    }

    private function get_active_users_between( $start, $end ) {
        $repo  = new EnrollmentsRepository();
        $tutor = new TutorLms();

        $rows = $repo->rows_between( $start, $end );

        $by_user = [];
        $pair_seen = [];

        foreach ( $rows as $r ) {
            $cid = (int) ( $r['course_id'] ?? 0 );
            $uid = (int) ( $r['user_id'] ?? 0 );
            if ( $cid <= 0 || $uid <= 0 ) continue;

            $pair = $cid . '|' . $uid;
            if ( isset( $pair_seen[$pair] ) ) continue;
            $pair_seen[$pair] = true;

            if ( ! isset( $by_user[$uid] ) ) {
                $by_user[$uid] = [ 'enrollments' => 0, 'completed' => 0, 'sum_progress' => 0.0 ];
            }

            $by_user[$uid]['enrollments']++;
            $p = (float) $tutor->course_progress_percent( $cid, $uid );
            $by_user[$uid]['sum_progress'] += $p;
            if ( $tutor->is_effectively_completed( $cid, $uid ) ) {
                $by_user[$uid]['completed']++;
            }
        }

        // Sort by enrollments desc.
        uasort( $by_user, function( $a, $b ) {
            return (int) ( $b['enrollments'] ?? 0 ) <=> (int) ( $a['enrollments'] ?? 0 );
        } );

        $out = [
            [
                'ID de usuario',
                'Usuario',
                'Nombre',
                'Email',
                'Roles',
                'Fecha de registro',
                'Cursos inscritos en el período',
                'Cursos completados (estado actual)',
                'Progreso promedio % (estado actual)',
            ],
        ];

        foreach ( $by_user as $uid => $stats ) {
            $u = get_userdata( $uid );
            if ( ! $u ) continue;
            $enr = (int) ( $stats['enrollments'] ?? 0 );
            $avg = $enr > 0 ? round( (float) ( $stats['sum_progress'] ?? 0 ) / $enr, 1 ) : 0;

            $out[] = [
                (int) $uid,
                (string) $u->user_login,
                (string) $u->display_name,
                (string) $u->user_email,
                is_array( $u->roles ) ? implode( ',', $u->roles ) : '',
                (string) $u->user_registered,
                $enr,
                (int) ( $stats['completed'] ?? 0 ),
                (float) $avg,
            ];
        }

        return $out;
    }

    /**
     * Una fila por par usuario-curso: el detalle que get_active_users_between()
     * calcula y despues descarta al agregar por usuario.
     *
     * NO agrega ni una consulta. El bucle de abajo llama a
     * course_progress_percent() e is_effectively_completed() exactamente una vez
     * por par deduplicado, igual que el metodo agregado. get_userdata() y
     * get_the_title() se sirven del object cache de WordPress tras la primera
     * lectura de cada usuario y de cada curso.
     *
     * OJO con la columna de completacion: is_effectively_completed() informa el
     * estado ACTUAL del alumno en el curso, no si completo dentro de la ventana.
     * El encabezado lo dice.
     *
     * @param string $start
     * @param string $end
     * @return array
     */
    private function get_active_users_by_course_between( $start, $end ) {
        $repo  = new EnrollmentsRepository();
        $tutor = new TutorLms();

        $rows = $repo->rows_between( $start, $end );

        // 1) Deduplicar por par, quedandose con la fecha de inscripcion MINIMA.
        //    rows_between() no tiene ORDER BY, asi que tomar "la primera que
        //    aparezca" haria depender el valor del orden que devuelva MySQL.
        $pairs = [];

        foreach ( $rows as $r ) {
            $cid = (int) ( $r['course_id'] ?? 0 );
            $uid = (int) ( $r['user_id'] ?? 0 );
            if ( $cid <= 0 || $uid <= 0 ) continue;

            $pair        = $cid . '|' . $uid;
            $enroll_date = (string) ( $r['enroll_date'] ?? '' );

            if ( ! isset( $pairs[ $pair ] ) ) {
                $pairs[ $pair ] = [
                    'course_id'   => $cid,
                    'user_id'     => $uid,
                    'enroll_date' => $enroll_date,
                ];
                continue;
            }

            if ( '' !== $enroll_date
                 && ( '' === $pairs[ $pair ]['enroll_date'] || $enroll_date < $pairs[ $pair ]['enroll_date'] ) ) {
                $pairs[ $pair ]['enroll_date'] = $enroll_date;
            }
        }

        // 2) Armar las filas. Una llamada a Tutor por par, como el metodo agregado.
        $data = [];

        foreach ( $pairs as $p ) {
            $uid = (int) $p['user_id'];
            $cid = (int) $p['course_id'];

            $u = get_userdata( $uid );
            if ( ! $u ) continue;

            $title    = (string) get_the_title( $cid );
            $name     = (string) $u->display_name;
            $progress = round( (float) $tutor->course_progress_percent( $cid, $uid ), 1 );

            $data[] = [
                'name'  => $name,
                'title' => $title,
                'row'   => [
                    $uid,
                    (string) $u->user_login,
                    $name,
                    (string) $u->user_email,
                    is_array( $u->roles ) ? implode( ',', $u->roles ) : '',
                    (string) $u->user_registered,
                    $cid,
                    $title,
                    (string) $p['enroll_date'],
                    (float) $progress,
                    $tutor->is_effectively_completed( $cid, $uid ) ? 'si' : 'no',
                ],
            ];
        }

        // 3) Ordenar por alumno y despues por curso: asi las filas de un mismo
        //    alumno quedan juntas, que es como se lee la planilla.
        usort( $data, function( $a, $b ) {
            $c = strnatcasecmp( $a['name'], $b['name'] );

            return 0 !== $c ? $c : strnatcasecmp( $a['title'], $b['title'] );
        } );

        $out = [
            [
                'ID de usuario',
                'Usuario',
                'Nombre',
                'Email',
                'Roles',
                'Fecha de registro',
                'ID del curso',
                'Curso',
                'Fecha de inscripción',
                'Progreso % (estado actual)',
                'Completado (estado actual)',
            ],
        ];

        foreach ( $data as $d ) {
            $out[] = $d['row'];
        }

        return $out;
    }

    private function get_dashboard_metrics_snapshot( $dates ) {
        $svc = new MetricsService();
        $data = $svc->get_dashboard_data( $dates['period_key'] ?? null );

        $kpi = (array) ( $data['kpis'] ?? [] );

        $metrics = [
            [ 'metric', 'value' ],
            [ 'current_new_users', (int) ( $kpi['current_new_users'] ?? 0 ) ],
            [ 'current_first_time_enrollments', (int) ( $kpi['current_first_time_enrollments'] ?? 0 ) ],
            [ 'current_enrollments', (int) ( $kpi['current_enrollments'] ?? 0 ) ],
            [ 'active_users', (int) ( $kpi['active_users'] ?? 0 ) ],
            [ 'completion_rate_%', (float) ( $kpi['completion_rate'] ?? 0 ) ],
            [ 'current_completed', (int) ( $kpi['current_completed'] ?? 0 ) ],
            [ 'dropout_rate_%', (float) ( $kpi['dropout_rate'] ?? 0 ) ],
            [ 'activity_rate_%', (float) ( $kpi['activity_rate'] ?? 0 ) ],
            [ 'performance', (float) ( $kpi['performance'] ?? 0 ) ],
        ];

        $courses = [
            [ 'course_id', 'course_title', 'enroll_period', 'new_course', 'returning', 'prev_period', 'growth_%', 'completed', 'completion_rate_%' ]
        ];

        foreach ( (array) ( $data['courses_stats'] ?? [] ) as $row ) {
            $courses[] = [
                (int) ( $row->course_id ?? 0 ),
                (string) ( $row->course_title ?? '' ),
                (int) ( $row->current_enroll ?? 0 ),
                (int) ( $row->current_first_time_enroll ?? 0 ),
                (int) ( $row->current_returning_enroll ?? 0 ),
                (int) ( $row->previous_enroll ?? 0 ),
                ( null === $row->growth_enrollments ) ? '' : (float) $row->growth_enrollments,
                (int) ( $row->current_completed ?? 0 ),
                (float) ( $row->completion_rate ?? 0 ),
            ];
        }

        $ret = (array) ( $data['retention'] ?? [] );
        $base = (int) ( $ret['base'] ?? 0 );

        $retention = [
            [ 'metric', 'value' ],
            [ 'base_users', $base ],
            [ '', '' ],
            [ 'window', 'active', 'base', 'rate_%' ],
        ];

        foreach ( ['7','14','30','60','90','180','365','all'] as $d ) {
            $r = (array) ( $ret[$d] ?? [] );
            $retention[] = [
                ( $d === 'all' ) ? 'historico' : (int) $d,
                (int) ( $r['active'] ?? 0 ),
                (int) ( $r['total'] ?? 0 ),
                (float) ( $r['rate'] ?? 0 ),
            ];
        }

        $dau_mau = [ [ 'metric', 'value' ] ];
        $dm = (array) ( $data['dau_mau'] ?? [] );
        foreach ( ['dau','mau','ratio'] as $k ) {
            $dau_mau[] = [ $k, ( $k === 'ratio' ) ? (float) ( $dm[$k] ?? 0 ) : (int) ( $dm[$k] ?? 0 ) ];
        }
        if ( ! empty( $dm['window_dau'] ) ) {
            $dau_mau[] = [ 'window_dau', (string) ( ($dm['window_dau']['start'] ?? '') . ' — ' . ($dm['window_dau']['end'] ?? '') ) ];
        }
        if ( ! empty( $dm['window_mau'] ) ) {
            $dau_mau[] = [ 'window_mau', (string) ( ($dm['window_mau']['start'] ?? '') . ' — ' . ($dm['window_mau']['end'] ?? '') ) ];
        }

        return [
            'metrics'   => $metrics,
            'courses'   => $courses,
            'retention' => $retention,
            'dau_mau'   => $dau_mau,
        ];
    }

    private function get_active_users_last_n_days( $days ) {
        global $wpdb;

        $now_ts  = current_time( 'timestamp' );
        $start_ts = strtotime( '-' . (int) $days . ' days', $now_ts );

        $end = date_i18n( 'Y-m-d H:i:s', $now_ts );
        $start = date_i18n( 'Y-m-d H:i:s', $start_ts );

        $post_type = apply_filters( 'e3a_enrollment_post_type', 'tutor_enrolled' );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT post_author AS user_id
                 FROM {$wpdb->posts}
                 WHERE post_type = %s
                   AND post_status IN ('publish','completed')
                   AND post_parent > 0
                   AND post_date BETWEEN %s AND %s",
                $post_type,
                $start,
                $end
            ),
            ARRAY_A
        );

        $ids = [];
        foreach ( (array) $rows as $r ) {
            $ids[] = (int) ( $r['user_id'] ?? 0 );
        }

        $out = [ $this->user_header() ];
        foreach ( $ids as $id ) {
            $row = $this->user_full_row( $id );
            if ( $row ) $out[] = $row;
        }
        return $out;
    }

    private function get_dropout_report_snapshot( $start, $end ) {
        // Rebuild (light) report from enrollments in the window.
        $repo  = new EnrollmentsRepository();
        $tutor = new TutorLms();

        $rows = $repo->rows_between( $start, $end );

        $buckets = [
            '0_10'  => '0–10%',
            '11_25' => '11–25%',
            '26_50' => '26–50%',
            '51_75' => '51–75%',
            '76_99' => '76–99%',
        ];

        $seen = [];
        $courses = [];

        foreach ( $rows as $r ) {
            $cid = (int) ( $r['course_id'] ?? 0 );
            $uid = (int) ( $r['user_id'] ?? 0 );
            if ( $cid <= 0 || $uid <= 0 ) continue;

            $pair = $cid . '|' . $uid;
            if ( isset( $seen[$pair] ) ) continue;
            $seen[$pair] = true;

            if ( $tutor->is_effectively_completed( $cid, $uid ) ) continue;

            $p = (float) $tutor->course_progress_percent( $cid, $uid );
            if ( $p >= 100 ) continue;
            if ( $p < 0 ) $p = 0;

            $bucket_key = $this->bucket_key( $p );

            if ( ! isset( $courses[$cid] ) ) {
                $courses[$cid] = [
                    'course_id'    => $cid,
                    'course_title' => (string) get_the_title( $cid ),
                    'dropouts'     => 0,
                    'avg'          => 0.0,
                    'sum'          => 0.0,
                    'buckets'      => array_fill_keys( array_keys( $buckets ), 0 ),
                ];
            }

            $courses[$cid]['dropouts']++;
            $courses[$cid]['sum'] += $p;
            $courses[$cid]['buckets'][ $bucket_key ]++;
        }

        $courses_list = array_values( $courses );
        foreach ( $courses_list as &$c ) {
            $d = (int) ( $c['dropouts'] ?? 0 );
            $c['avg'] = $d > 0 ? round( (float) ( $c['sum'] ?? 0 ) / $d, 1 ) : 0.0;
            unset( $c['sum'] );
        }
        unset( $c );

        usort( $courses_list, function( $a, $b ) {
            return (int) ( $b['dropouts'] ?? 0 ) <=> (int) ( $a['dropouts'] ?? 0 );
        } );

        // Courses sheet
        $courses_sheet = [
            array_merge( [ 'course_id', 'course_title', 'dropouts', 'avg_progress_%' ], array_values( $buckets ) )
        ];

        foreach ( $courses_list as $c ) {
            $row = [ (int) $c['course_id'], (string) $c['course_title'], (int) $c['dropouts'], (float) $c['avg'] ];
            foreach ( array_keys( $buckets ) as $bk ) {
                $row[] = (int) ( $c['buckets'][$bk] ?? 0 );
            }
            $courses_sheet[] = $row;
        }

        // Distribution sheet (top 10)
        $top = array_slice( $courses_list, 0, 10 );
        $dist = [ [ 'course_title', 'bucket', 'users' ] ];
        foreach ( $top as $c ) {
            foreach ( array_keys( $buckets ) as $bk ) {
                $dist[] = [ (string) $c['course_title'], (string) $buckets[$bk], (int) ( $c['buckets'][$bk] ?? 0 ) ];
            }
        }

        return [
            'courses'       => $courses_sheet,
            'distribution'  => $dist,
        ];
    }

    private function get_all_dropout_users( $start, $end, $limit = null ) {
        $repo  = new EnrollmentsRepository();
        $tutor = new TutorLms();

        $rows = $repo->rows_between( $start, $end );

        $buckets = [
            '0_10'  => '0–10%',
            '11_25' => '11–25%',
            '26_50' => '26–50%',
            '51_75' => '51–75%',
            '76_99' => '76–99%',
        ];

        $seen = [];

        $out = [
            [ 'course_id', 'course_title', 'enroll_date', 'bucket', 'progress_%', 'user_id', 'user_login', 'display_name', 'email', 'roles', 'registered' ]
        ];

        foreach ( $rows as $r ) {
            $cid = (int) ( $r['course_id'] ?? 0 );
            $uid = (int) ( $r['user_id'] ?? 0 );
            if ( $cid <= 0 || $uid <= 0 ) continue;

            $pair = $cid . '|' . $uid;
            if ( isset( $seen[$pair] ) ) continue;
            $seen[$pair] = true;

            if ( $tutor->is_effectively_completed( $cid, $uid ) ) continue;

            $u = get_userdata( $uid );
            if ( ! $u ) continue;

            $p = (float) $tutor->course_progress_percent( $cid, $uid );
            if ( $p >= 100 ) continue;
            if ( $p < 0 ) $p = 0;

            $bk = $this->bucket_key( $p );

            $out[] = [
                $cid,
                (string) get_the_title( $cid ),
                (string) ( $r['enroll_date'] ?? '' ),
                (string) ( $buckets[$bk] ?? $bk ),
                (float) round( $p, 1 ),
                $uid,
                (string) $u->user_login,
                (string) $u->display_name,
                (string) $u->user_email,
                is_array( $u->roles ) ? implode( ',', $u->roles ) : '',
                (string) $u->user_registered,
            ];

            if ( is_int( $limit ) && $limit > 0 && count( $out ) > ( $limit + 1 ) ) {
                break;
            }
        }

        return $out;
    }

    private function get_dropout_users_by_course( $period, $course_id, $bucket, $q ) {
        // Use the existing service which already applies search filters.
        $svc = new DropoutProgressService();
        $report = $svc->get_report([
            'period'        => (string) $period,
            'course_id'     => (int) $course_id,
            'bucket'        => (string) $bucket,
            'q'             => (string) $q,
            'include_users' => true,
        ]);

        $users = (array) ( $report['users'] ?? [] );

        $out = [
            [ 'user_id', 'user_login', 'display_name', 'email', 'roles', 'registered', 'course_id', 'course_title', 'enroll_date', 'progress_%', 'bucket' ]
        ];

        foreach ( $users as $u ) {
            $uid = (int) ( $u['user_id'] ?? 0 );
            $wu = get_userdata( $uid );
            if ( ! $wu ) continue;

            $out[] = [
                $uid,
                (string) ( $wu->user_login ?? '' ),
                (string) ( $u['display_name'] ?? $wu->display_name ),
                (string) ( $wu->user_email ?? '' ),
                is_array( $wu->roles ) ? implode( ',', $wu->roles ) : '',
                (string) ( $wu->user_registered ?? '' ),
                (int) ( $u['course_id'] ?? 0 ),
                (string) ( $u['course_title'] ?? '' ),
                (string) ( $u['enroll_date'] ?? '' ),
                (float) ( $u['progress'] ?? 0 ),
                (string) ( $u['bucket_label'] ?? '' ),
            ];
        }

        return $out;
    }

    /** -----------------------------
     * User helpers
     */

    
    private function get_cross_course_users_between( $start, $end ) {
        global $wpdb;

        if ( ! $start || ! $end ) {
            return [ [ 'Sin datos' ] ];
        }

        $post_type = apply_filters( 'e3a_enrollment_post_type', 'tutor_enrolled' );

        $pairs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT u.ID AS user_id, u.user_login, u.display_name, u.user_email,
                        e.post_parent AS course_id
                 FROM {$wpdb->posts} e
                 INNER JOIN {$wpdb->users} u ON u.ID = e.post_author
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
                   )
                 ORDER BY u.ID ASC",
                $post_type,
                $start,
                $end,
                $post_type,
                $start,
                $post_type,
                $start
            ),
            ARRAY_A
        );

        if ( empty( $pairs ) ) {
            return [ [ 'Sin datos' ] ];
        }

        $by_user = [];
        foreach ( $pairs as $p ) {
            $uid = (int) ( $p['user_id'] ?? 0 );
            $cid = (int) ( $p['course_id'] ?? 0 );
            if ( $uid <= 0 || $cid <= 0 ) continue;

            if ( ! isset( $by_user[ $uid ] ) ) {
                $by_user[ $uid ] = [
                    'user_id'      => $uid,
                    'user_login'   => (string) ( $p['user_login'] ?? '' ),
                    'display_name' => (string) ( $p['display_name'] ?? '' ),
                    'email'        => (string) ( $p['user_email'] ?? '' ),
                    'courses'      => [],
                ];
            }
            $by_user[ $uid ]['courses'][ $cid ] = true;
        }

        $out = [
            [ 'user_id', 'user_login', 'display_name', 'email', 'new_courses_count', 'new_courses' ],
        ];

        foreach ( $by_user as $uid => $row ) {
            $course_ids = array_keys( $row['courses'] );
            $titles = [];
            foreach ( $course_ids as $cid ) {
                $t = get_the_title( $cid );
                if ( $t ) $titles[] = $t . " (#{$cid})";
            }

            $out[] = [
                'user_id'           => $row['user_id'],
                'user_login'        => $row['user_login'],
                'display_name'      => $row['display_name'],
                'email'             => $row['email'],
                'new_courses_count' => count( $course_ids ),
                'new_courses'       => implode( ' | ', $titles ),
            ];
        }

        return $out;
    }

    private function get_cross_course_users_all_time() {
        global $wpdb;

        $post_type = apply_filters( 'e3a_enrollment_post_type', 'tutor_enrolled' );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.ID AS user_id, u.user_login, u.display_name, u.user_email,
                        COUNT(DISTINCT e.post_parent) AS course_count
                 FROM {$wpdb->posts} e
                 INNER JOIN {$wpdb->users} u ON u.ID = e.post_author
                 WHERE e.post_type = %s
                   AND e.post_status IN ('publish','completed')
                   AND e.post_parent > 0
                 GROUP BY u.ID
                 HAVING course_count >= 2
                 ORDER BY course_count DESC",
                $post_type
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [ [ 'Sin datos' ] ];
        }

        $out = [
            [ 'user_id', 'user_login', 'display_name', 'email', 'distinct_courses' ],
        ];

        foreach ( $rows as $r ) {
            $out[] = [
                'user_id'          => (int) ( $r['user_id'] ?? 0 ),
                'user_login'       => (string) ( $r['user_login'] ?? '' ),
                'display_name'     => (string) ( $r['display_name'] ?? '' ),
                'email'            => (string) ( $r['user_email'] ?? '' ),
                'distinct_courses' => (int) ( $r['course_count'] ?? 0 ),
            ];
        }

        return $out;
    }

private function user_header( $include_id = true ) {
        $base = [
            'user_login',
            'first_name',
            'last_name',
            'display_name',
            'email',
            'roles',
            'registered',
            'user_url',
            'locale',
            'phone',
            'billing_phone',
            'meta_json',
        ];
        return $include_id ? array_merge( [ 'user_id' ], $base ) : $base;
    }

    private function user_full_row( $user_id ) {
        $u = get_userdata( $user_id );
        if ( ! $u ) return null;

        $first = (string) get_user_meta( $user_id, 'first_name', true );
        $last  = (string) get_user_meta( $user_id, 'last_name', true );
        $phone = (string) get_user_meta( $user_id, 'phone', true );
        $billing_phone = (string) get_user_meta( $user_id, 'billing_phone', true );

        $locale = function_exists( 'get_user_locale' ) ? (string) get_user_locale( $user_id ) : '';

        // Meta JSON (flattened + truncated)
        $flat = [];
        $meta = get_user_meta( $user_id );
        if ( is_array( $meta ) ) {
            foreach ( $meta as $k => $vals ) {
                if ( is_array( $vals ) ) {
                    $v0 = reset( $vals );
                    if ( is_array( $v0 ) || is_object( $v0 ) ) {
                        $flat[$k] = wp_json_encode( $v0, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                    } else {
                        $flat[$k] = (string) $v0;
                    }
                } else {
                    $flat[$k] = (string) $vals;
                }
            }
        }
        $meta_json = wp_json_encode( $flat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( is_string( $meta_json ) && strlen( $meta_json ) > 15000 ) {
            $meta_json = substr( $meta_json, 0, 14990 ) . '…';
        }

        return [
            (int) $user_id,
            (string) $u->user_login,
            $first,
            $last,
            (string) $u->display_name,
            (string) $u->user_email,
            is_array( $u->roles ) ? implode( ',', $u->roles ) : '',
            (string) $u->user_registered,
            (string) $u->user_url,
            $locale,
            $phone,
            $billing_phone,
            (string) $meta_json,
        ];
    }

    private function maybe_add_user_meta_sheet( &$sheets, $rows, $user_id_col_name = null ) {
        // Try to collect user IDs from a "Usuarios"-like table.
        $user_ids = [];

        if ( ! is_array( $rows ) || empty( $rows ) ) return;

        $header = $rows[0] ?? [];
        if ( ! is_array( $header ) ) return;

        $idx = null;
        if ( $user_id_col_name && is_string( $user_id_col_name ) ) {
            foreach ( $header as $i => $h ) {
                if ( (string) $h === $user_id_col_name ) { $idx = $i; break; }
            }
        }
        if ( null === $idx ) {
            foreach ( $header as $i => $h ) {
                if ( (string) $h === 'user_id' ) { $idx = $i; break; }
            }
        }

        if ( null === $idx ) return;

        for ( $i = 1; $i < count( $rows ); $i++ ) {
            $r = $rows[$i];
            if ( ! is_array( $r ) ) continue;
            $user_ids[] = (int) ( $r[$idx] ?? 0 );
        }

        $user_ids = array_values( array_unique( array_filter( $user_ids ) ) );
        if ( empty( $user_ids ) ) return;

        // Safety limit.
        if ( count( $user_ids ) > 200 ) return;

        $meta_rows = [ [ 'user_id', 'meta_key', 'meta_value' ] ];
        $max_rows = 5000;

        foreach ( $user_ids as $uid ) {
            $meta = get_user_meta( $uid );
            if ( ! is_array( $meta ) ) continue;

            foreach ( $meta as $k => $vals ) {
                if ( count( $meta_rows ) >= $max_rows ) break 2;
                if ( ! is_array( $vals ) ) $vals = [ $vals ];
                $v0 = reset( $vals );

                if ( is_array( $v0 ) || is_object( $v0 ) ) {
                    $v0 = wp_json_encode( $v0, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                }

                $meta_rows[] = [ (int) $uid, (string) $k, (string) $v0 ];
            }
        }

        if ( count( $meta_rows ) > 1 ) {
            $sheets[] = [ 'name' => 'UserMeta', 'rows' => $meta_rows ];
        }
    }



    private function build_country( $key, $args ) {
        $period = isset( $args['period'] ) ? (string) $args['period'] : null;

        $service = new CountryAnalyticsService();
        $report  = $service->get_report( $period );

        $dates = $report['dates'] ?? DatePeriod::resolve( $period );
        $rows  = $report['countries'] ?? [];

        $now    = current_time( 'mysql' );
        $sheets = [];

        $sheets[] = [
            'name' => 'Resumen',
            'rows' => [
                [ 'Generado', $now ],
                [ 'Período', (string) ( $dates['label'] ?? $dates['period_key'] ?? '' ) ],
                [ 'Rango', (string) ( $dates['current_start'] ?? '' ) . ' — ' . (string) ( $dates['current_end'] ?? '' ) ],
                [ 'Reporte', 'country:' . (string) $key ],
                [ 'Cobertura país', (string) ( ( $report['totals']['coverage_percent'] ?? 0 ) . '%' ) ],
            ],
        ];

        $table = [
            [ 'País', 'Registros', 'Nuevos inscritos', 'Activos', 'Inscripciones', 'Completados', '% Compleción', 'Recurrentes' ],
        ];

        foreach ( (array) $rows as $r ) {
            $table[] = [
                (string) ( $r['country'] ?? '' ),
                (int) ( $r['registered'] ?? 0 ),
                (int) ( $r['first_time_enrolled'] ?? 0 ),
                (int) ( $r['active_users'] ?? 0 ),
                (int) ( $r['enrollments'] ?? 0 ),
                (int) ( $r['completed_enrollments'] ?? 0 ),
                (float) ( $r['completion_rate'] ?? 0 ),
                (int) ( $r['cross_course_users'] ?? 0 ),
            ];
        }

        $sheets[] = [ 'name' => 'Países', 'rows' => $table ];

        return [
            'filename' => 'e3-analytics-paises' . $this->filename_suffix( $dates ),
            'sheets'   => $sheets,
        ];
    }
}
