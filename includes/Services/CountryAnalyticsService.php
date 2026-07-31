<?php
namespace E3_Analytics\Services;

use E3_Analytics\Support\DatePeriod;
use E3_Analytics\Support\CountryHelper;
use E3_Analytics\Integrations\TutorLms;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CountryAnalyticsService {
    use CountryHelper;

    public function get_report( $period_override = null ) {
        $dates = DatePeriod::resolve( $period_override );

        $period_key = (string) ( $dates['period_key'] ?? '30' );
        $start      = (string) ( $dates['current_start'] ?? '' );
        $end        = (string) ( $dates['current_end'] ?? '' );
        $is_all     = (bool) ( $dates['is_all'] ?? false );
        $mode       = (string) ( $dates['mode'] ?? DatePeriod::MODE_LEGACY );

        // user_registered está en UTC. En legacy y calendar estas claves son copia
        // literal de las locales, así que no hay condicional de modo acá.
        $start_utc = (string) ( $dates['current_start_utc'] ?? $start );
        $end_utc   = (string) ( $dates['current_end_utc'] ?? $end );

        /*
         * El modo entra en la clave: legacy y calendar producen métricas distintas
         * para el mismo period_key, y sin el modo un cambio de configuración
         * devolvería el payload del modo anterior.
         */
        $cache_key = 'e3a_country_' . md5( $mode . '|' . $period_key . '|' . $start . '|' . $end );

        // Identidad del período con el que se calculan las métricas de este payload.
        $computed_for = [
            'mode'          => $mode,
            'period_key'    => $period_key,
            'current_start' => $start,
            'current_end'   => $end,
        ];

        // El modo compare de la herramienta de diagnóstico saltea el caché para
        // medir cálculo en frío y para no contaminar un modo con otro.
        $bypass_cache = (bool) apply_filters( 'e3a_bypass_cache', false );

        if ( ! $bypass_cache ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached )
                 && isset( $cached['_computed_for'] )
                 && $cached['_computed_for'] === $computed_for ) {
                /*
                 * El payload cacheado NO contiene 'dates': se le adjunta el fresco
                 * al retornar. Motivo: 'notice' no se deriva de la clave de caché.
                 * Un rango recortado y el rango ya recortado comparten clave, así
                 * que cachear 'dates' mostraría un aviso de recorte en un request
                 * que no recortó nada. Se elimina la clase de problema en lugar de
                 * vigilarla.
                 */
                $cached['dates'] = $dates;
                return $cached;
            }
        }

        global $wpdb;
        $post_type = apply_filters( 'e3a_enrollment_post_type', 'tutor_enrolled' );

        // 1) Usuarios registrados en el período (user_registered está en UTC)
        $registered_user_ids = [];
        if ( $start_utc && $end_utc ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->users} WHERE user_registered BETWEEN %s AND %s",
                    $start_utc,
                    $end_utc
                ),
                ARRAY_A
            );
            foreach ( (array) $rows as $r ) {
                $registered_user_ids[] = (int) ( $r['ID'] ?? 0 );
            }
            $registered_user_ids = array_values( array_filter( array_unique( $registered_user_ids ) ) );
        }

        // 2) Inscripciones en el período (incluye status para estimar completados)
        $enroll_rows = [];
        if ( $start && $end ) {
            $enroll_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_parent AS course_id, post_author AS user_id, post_date AS enroll_date, post_status
                     FROM {$wpdb->posts}
                     WHERE post_type = %s
                       AND post_status IN ('publish','completed','private')
                       AND post_parent > 0
                       AND post_date BETWEEN %s AND %s",
                    $post_type,
                    $start,
                    $end
                ),
                ARRAY_A
            );
            $enroll_rows = is_array( $enroll_rows ) ? $enroll_rows : [];
        }

        $enrolled_user_ids = [];
        foreach ( $enroll_rows as $r ) {
            $uid = (int) ( $r['user_id'] ?? 0 );
            if ( $uid > 0 ) $enrolled_user_ids[] = $uid;
        }
        $enrolled_user_ids = array_values( array_filter( array_unique( $enrolled_user_ids ) ) );

        // 3) Cohortes derivadas (primer inscrito y “inscrito a otro curso”)
        $first_time_user_ids = $this->get_first_time_enrolled_user_ids( $start, $end, $post_type );
        $cross_course_user_ids = $is_all
            ? $this->get_cross_course_user_ids_all_time( $post_type )
            : $this->get_cross_course_user_ids_between( $start, $end, $post_type );

        // Universo de usuarios para coverage y asignación de país.
        $universe_ids = array_values( array_unique( array_merge( $registered_user_ids, $enrolled_user_ids, $first_time_user_ids, $cross_course_user_ids ) ) );
        $country_map  = $this->get_country_map_for_users( $universe_ids );

        $unknown_label = 'Desconocido';
        $stats = [];
        $active_sets = [];

        $ensure = function( $label ) use ( &$stats, $unknown_label ) {
            $label = $label ?: $unknown_label;

            // FIX: fallback seguro si mbstring no está habilitado
            $key = function_exists( 'mb_strtolower' )
                ? mb_strtolower( $label, 'UTF-8' )
                : strtolower( $label );

            if ( ! isset( $stats[ $key ] ) ) {
                $stats[ $key ] = [
                    'country'              => $label,
                    'registered'           => 0,
                    'first_time_enrolled'  => 0,
                    'active_users'         => 0,
                    'enrollments'          => 0,
                    'completed_enrollments'=> 0,
                    'completion_rate'      => 0,
                    'cross_course_users'   => 0,
                ];
            }
            return $key;
        };

        // Registered
        foreach ( $registered_user_ids as $uid ) {
            $label = $country_map[ $uid ] ?? $unknown_label;
            $k = $ensure( $label );
            $stats[ $k ]['registered']++;
        }

        // First time enrolled users
        foreach ( $first_time_user_ids as $uid ) {
            $label = $country_map[ $uid ] ?? $unknown_label;
            $k = $ensure( $label );
            $stats[ $k ]['first_time_enrolled']++;
        }

        // Cross course users
        foreach ( $cross_course_user_ids as $uid ) {
            $label = $country_map[ $uid ] ?? $unknown_label;
            $k = $ensure( $label );
            $stats[ $k ]['cross_course_users']++;
        }

        // Enrollments + Active users + Completed enrollments
        $tutor           = new TutorLms();
        $completed_cache = [];
        foreach ( $enroll_rows as $r ) {
            $uid = (int) ( $r['user_id'] ?? 0 );
            $cid = (int) ( $r['course_id'] ?? 0 );
            if ( $uid <= 0 || $cid <= 0 ) continue;

            $label = $country_map[ $uid ] ?? $unknown_label;
            $k = $ensure( $label );

            $stats[ $k ]['enrollments']++;
            if ( ! isset( $active_sets[ $k ] ) ) $active_sets[ $k ] = [];
            $active_sets[ $k ][ $uid ] = true;

            $status = strtolower( (string) ( $r['post_status'] ?? '' ) );
            $completed = ( $status === 'completed' );

            // Si el status no viene como completed, usa el check robusto (evita falsos 99.9% = no completado)
            if ( ! $completed ) {
                $ck = $cid . '|' . $uid;
                if ( ! isset( $completed_cache[ $ck ] ) ) {
                    $completed_cache[ $ck ] = $tutor->is_effectively_completed( $cid, $uid );
                }
                $completed = $completed_cache[ $ck ];
            }

            if ( $completed ) {
                $stats[ $k ]['completed_enrollments']++;
            }
        }

        foreach ( $active_sets as $k => $set ) {
            $stats[ $k ]['active_users'] = count( $set );
        }

        // Rates
        foreach ( $stats as $k => $row ) {
            $en = (int) ( $row['enrollments'] ?? 0 );
            $co = (int) ( $row['completed_enrollments'] ?? 0 );
            $stats[ $k ]['completion_rate'] = $en > 0 ? round( ( $co / $en ) * 100, 1 ) : 0;
        }

        // Sort by enrollments desc
        $rows = array_values( $stats );
        usort( $rows, function( $a, $b ) {
            return (int) ( $b['enrollments'] ?? 0 ) <=> (int) ( $a['enrollments'] ?? 0 );
        } );

        // Totals / coverage
        $total_users = count( $universe_ids );
        $known_users = 0;
        foreach ( $universe_ids as $uid ) {
            $label = $country_map[ $uid ] ?? '';
            if ( $label && $label !== $unknown_label ) $known_users++;
        }
        $coverage = $total_users > 0 ? round( ( $known_users / $total_users ) * 100, 1 ) : 0;

        // Chart payload (Top 10)
        $top = array_slice( $rows, 0, 10 );
        $chart = [
            'labels'     => array_map( function( $r ) { return (string) $r['country']; }, $top ),
            'enrollments'=> array_map( function( $r ) { return (int) $r['enrollments']; }, $top ),
            'completed'  => array_map( function( $r ) { return (int) $r['completed_enrollments']; }, $top ),
        ];

        // Sin 'dates': lo que se cachea son SOLO las métricas.
        $out = [
            '_computed_for' => $computed_for,
            'totals'  => [
                'users_total'     => $total_users,
                'users_known'     => $known_users,
                'coverage_percent'=> $coverage,
            ],
            'countries' => $rows,
            'chart'     => $chart,
        ];

        /*
         * TTL de 60 s. Hasta ahora el caché nunca acertaba porque current_end
         * llevaba segundos y la clave cambiaba a cada request. En modo calendario
         * la clave se estabiliza y el caché empieza a funcionar de verdad.
         *
         * Hoy ninguna opción del plugin altera el cálculo de las métricas, así
         * que no hay nada que invalidar: el caso que preocupaba —guardar la
         * configuración de quizzes de retroalimentación y seguir viendo números
         * viejos— desapareció con esa funcionalidad. Pero el MECANISMO de
         * invalidación sigue sin existir: si mañana se agrega cualquier opción
         * que influya en los números, el problema vuelve. Con 60 s la exposición
         * queda acotada a un minuto. El salt de versión de opciones en la clave
         * sigue pendiente.
         */
        if ( ! $bypass_cache ) {
            set_transient( $cache_key, $out, 60 );
        }

        // 'dates' se adjunta fresco, nunca se cachea.
        $out['dates'] = $dates;

        return $out;
    }

    private function get_first_time_enrolled_user_ids( $start, $end, $post_type ) {
        global $wpdb;
        if ( ! $start || ! $end ) return [];

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_author AS user_id, MIN(post_date) AS first_date
                 FROM {$wpdb->posts}
                 WHERE post_type = %s
                   AND post_status IN ('publish','completed','private')
                   AND post_parent > 0
                 GROUP BY post_author
                 HAVING first_date BETWEEN %s AND %s",
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
        return array_values( array_filter( array_unique( $ids ) ) );
    }

    private function get_cross_course_user_ids_all_time( $post_type ) {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_author AS user_id
                 FROM {$wpdb->posts}
                 WHERE post_type = %s
                   AND post_status IN ('publish','completed','private')
                   AND post_parent > 0
                 GROUP BY post_author
                 HAVING COUNT(DISTINCT post_parent) >= 2",
                $post_type
            ),
            ARRAY_A
        );
        $ids = [];
        foreach ( (array) $rows as $r ) {
            $ids[] = (int) ( $r['user_id'] ?? 0 );
        }
        return array_values( array_filter( array_unique( $ids ) ) );
    }

    private function get_cross_course_user_ids_between( $start, $end, $post_type ) {
        global $wpdb;
        if ( ! $start || ! $end ) return [];

        $sql = $wpdb->prepare(
            "SELECT DISTINCT e.post_author AS user_id
             FROM {$wpdb->posts} e
             WHERE e.post_type = %s
               AND e.post_status IN ('publish','completed','private')
               AND e.post_parent > 0
               AND e.post_date BETWEEN %s AND %s
               AND EXISTS (
                   SELECT 1
                   FROM {$wpdb->posts} p0
                   WHERE p0.post_type = %s
                     AND p0.post_status IN ('publish','completed','private')
                     AND p0.post_parent > 0
                     AND p0.post_author = e.post_author
                     AND p0.post_date < %s
               )
               AND NOT EXISTS (
                   SELECT 1
                   FROM {$wpdb->posts} p1
                   WHERE p1.post_type = %s
                     AND p1.post_status IN ('publish','completed','private')
                     AND p1.post_parent > 0
                     AND p1.post_author = e.post_author
                     AND p1.post_parent = e.post_parent
                     AND p1.post_date < %s
               )",
            $post_type,
            $start,
            $end,
            $post_type,
            $start,
            $post_type,
            $start
        );

        $rows = $wpdb->get_results( $sql, ARRAY_A );
        $ids = [];
        foreach ( (array) $rows as $r ) {
            $ids[] = (int) ( $r['user_id'] ?? 0 );
        }
        return array_values( array_filter( array_unique( $ids ) ) );
    }

    private function get_country_map_for_users( $user_ids ) {
        global $wpdb;

        $user_ids = array_values( array_filter( array_map( 'intval', is_array( $user_ids ) ? $user_ids : [] ) ) );
        if ( empty( $user_ids ) ) return [];

        $map = [];

        // Primary: country_lms
        $in = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
        $sql = "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'country_lms' AND user_id IN ($in)";
        $prepared = $wpdb->prepare( $sql, $user_ids );
        $rows = $wpdb->get_results( $prepared, ARRAY_A );
        foreach ( (array) $rows as $r ) {
            $uid = (int) ( $r['user_id'] ?? 0 );
            $val = isset( $r['meta_value'] ) ? trim( (string) $r['meta_value'] ) : '';
            if ( $uid > 0 && $val !== '' ) {
                $map[ $uid ] = $this->normalize_country_label( $val );
            }
        }

        // Secondary: tutor_login_* JSON (último login). Tomamos el meta más reciente por usuario.
        $missing = [];
        foreach ( $user_ids as $uid ) {
            if ( ! isset( $map[ $uid ] ) ) $missing[] = (int) $uid;
        }

        if ( ! empty( $missing ) ) {
            foreach ( array_chunk( $missing, 2000 ) as $chunk ) {
                $in2 = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
                $sql2 = "SELECT umeta_id, user_id, meta_key, meta_value
                         FROM {$wpdb->usermeta}
                         WHERE user_id IN ($in2)
                           AND meta_key LIKE 'tutor_login_%'
                         ORDER BY umeta_id DESC";
                $rows2 = $wpdb->get_results( $wpdb->prepare( $sql2, $chunk ), ARRAY_A );

                foreach ( (array) $rows2 as $r ) {
                    $uid = (int) ( $r['user_id'] ?? 0 );
                    if ( $uid <= 0 || isset( $map[ $uid ] ) ) continue;

                    $raw = trim( (string) ( $r['meta_value'] ?? '' ) );
                    if ( $raw === '' ) continue;

                    $json = json_decode( $raw, true );
                    if ( ! is_array( $json ) ) continue;

                    $code = isset( $json['country'] ) ? trim( (string) $json['country'] ) : '';
                    if ( $code !== '' ) {
                        $map[ $uid ] = $this->normalize_country_label( $this->iso2_to_name( $code ) );
                    }
                }
            }
        }

        return $map;
    }

}
