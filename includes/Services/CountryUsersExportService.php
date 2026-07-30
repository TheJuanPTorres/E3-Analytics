<?php
namespace E3_Analytics\Services;

use E3_Analytics\Support\DatePeriod;
use E3_Analytics\Support\CountryHelper;
use E3_Analytics\Integrations\TutorLms;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Exporta datos personales de usuarios (desde la sección País) a Excel (XLSX).
 * Universo: usuarios registrados en el período y/o con inscripciones creadas en el período.
 *
 * Hoja "Usuarios": columnas fijas de perfil + columnas dinámicas de % avance por curso.
 */
final class CountryUsersExportService {
    use CountryHelper;

    /**
     * @param string|int|null $period_override   7/30/90/365/all
     * @param int             $limit             0 = sin límite
     * @param bool            $include_meta_json Agrega columna meta_json con todos los usermeta
     */
    public function build( $period_override = null, $limit = 0, $include_meta_json = false ) {
        global $wpdb;

        $dates = DatePeriod::resolve( $period_override );

        $period_key = (string) ( $dates['period_key'] ?? '30' );
        $start      = (string) ( $dates['current_start'] ?? '' );
        $end        = (string) ( $dates['current_end'] ?? '' );

        // user_registered está en UTC; post_date en hora local. Los dos juegos
        // viajan juntos porque get_user_universe_ids() consulta las dos columnas.
        $start_utc = (string) ( $dates['current_start_utc'] ?? $start );
        $end_utc   = (string) ( $dates['current_end_utc'] ?? $end );

        $post_type = apply_filters( 'e3a_enrollment_post_type', 'tutor_enrolled' );

        // ── 1. Universo de usuarios ───────────────────────────────────────
        $user_ids = $this->get_user_universe_ids( $start, $end, $post_type, $start_utc, $end_utc );
        $total    = count( $user_ids );

        $limit     = (int) $limit;
        $truncated = false;
        if ( $limit > 0 && $total > $limit ) {
            $user_ids  = array_slice( $user_ids, 0, $limit );
            $truncated = true;
        }

        if ( empty( $user_ids ) ) {
            return [
                'filename' => 'e3-pais-usuarios-' . $period_key . '-' . date_i18n( 'Y-m-d' ),
                'sheets'   => [ [ 'name' => 'Usuarios', 'rows' => [ [ 'Sin usuarios en el período.' ] ] ] ],
            ];
        }

        // ── 2. Pre-cargar cache de objetos WP_User en una sola query ──────
        get_users( [ 'include' => $user_ids, 'number' => count( $user_ids ), 'fields' => 'all' ] );

        // ── 3. Batch: datos de país ───────────────────────────────────────
        $country_map = $this->get_country_details_for_users( $user_ids );

        // ── 4. Batch: usermeta relevante (1 query, no N*9) ────────────────
        $meta_map = $this->get_user_meta_batch( $user_ids );

        // ── 5. Inscripciones de los usuarios (todas, no solo del período) ─
        $enrollment_map = $this->get_enrollment_map_for_users( $user_ids, $post_type );

        // ── 6. Cursos únicos y sus títulos ────────────────────────────────
        $all_course_ids = [];
        foreach ( $enrollment_map as $courses ) {
            foreach ( array_keys( $courses ) as $cid ) {
                $all_course_ids[ $cid ] = true;
            }
        }
        $all_course_ids = array_keys( $all_course_ids );
        sort( $all_course_ids );

        $course_titles = [];
        foreach ( $all_course_ids as $cid ) {
            $t = get_the_title( $cid );
            $course_titles[ $cid ] = $t !== '' ? $t : ( 'Curso #' . $cid );
        }

        // ── 7. % de progreso por par usuario-curso ────────────────────────
        $progress_map = $this->build_progress_map( $enrollment_map, $all_course_ids );

        // ── 8. Construir hoja "Usuarios" ──────────────────────────────────
        $fixed_header = [
            'user_id', 'nickname', 'first_name', 'last_name', 'display_name',
            'user_email', 'roles', 'capabilities_json', 'description',
            'user_registered', 'locale',
            'country_resolved', 'country_source', 'country_lms_raw', 'tutor_login_country_iso2',
            'profile_type_lms', 'profile_type_other_lms', 'gender_lms',
            'age_range_lms', 'age_min', 'age_max', 'age_midpoint',
            'phone', 'billing_phone', 'user_url', 'meta_json',
        ];

        $course_header = array_values( $course_titles ); // un encabezado por curso
        $rows          = [ array_merge( $fixed_header, $course_header ) ];

        foreach ( $user_ids as $uid ) {
            $fixed_cols  = $this->user_row(
                $uid,
                $country_map[ $uid ] ?? null,
                $meta_map[ $uid ] ?? [],
                (bool) $include_meta_json
            );

            // Columnas de avance: % si inscrito, vacío si no
            $course_cols = [];
            foreach ( $all_course_ids as $cid ) {
                $course_cols[] = isset( $progress_map[ $uid ][ $cid ] )
                    ? $progress_map[ $uid ][ $cid ]
                    : '';
            }

            $rows[] = array_merge( $fixed_cols, $course_cols );
        }

        $now = current_time( 'mysql' );

        return [
            'filename' => 'e3-pais-usuarios-' . $period_key . '-' . date_i18n( 'Y-m-d' ),
            'sheets'   => [
                [
                    'name' => 'Resumen',
                    'rows' => [
                        [ 'Generado',             $now ],
                        [ 'Período',              $period_key ],
                        [ 'Rango',                $start . ' — ' . $end ],
                        [ 'Usuarios detectados',  (int) $total ],
                        [ 'Usuarios exportados',  (int) count( $user_ids ) ],
                        [ 'Truncado',             $truncated ? 'Sí' : 'No' ],
                        [ 'Límite',               $limit > 0 ? $limit : 'Sin límite' ],
                        [ 'Cursos en el reporte', count( $all_course_ids ) ],
                        [ 'Incluye meta_json',    $include_meta_json ? 'Sí' : 'No' ],
                        [ 'Nota', 'Las columnas de curso muestran el % de avance del usuario. Vacío = no inscrito.' ],
                    ],
                ],
                [
                    'name' => 'Usuarios',
                    'rows' => $rows,
                ],
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Fila de usuario (usa meta pre-cargada, sin get_user_meta individual)
    // ──────────────────────────────────────────────────────────────────────

    private function user_row( $user_id, $country_details = null, array $meta = [], $include_meta_json = false ) {
        global $wpdb;

        $user_id = (int) $user_id;
        $u       = get_userdata( $user_id ); // servido desde cache WP (pre-cargado en build())

        // Campos de perfil desde meta pre-cargado
        $first                  = (string) ( $meta['first_name'] ?? '' );
        $last                   = (string) ( $meta['last_name'] ?? '' );
        $desc                   = (string) ( $meta['description'] ?? '' );
        $profile_type_lms       = (string) ( $meta['profile_type_lms'] ?? '' );
        $profile_type_other_lms = (string) ( $meta['profile_type_other_lms'] ?? '' );
        $gender_lms             = (string) ( $meta['gender_lms'] ?? '' );
        $age_range_lms          = (string) ( $meta['age_range_lms'] ?? '' );
        $phone                  = (string) ( $meta['phone'] ?? '' );
        $billing_phone          = (string) ( $meta['billing_phone'] ?? '' );

        $age_parts = $this->parse_age_range( $age_range_lms );

        // Locale: función WP que tiene su propio fallback interno
        $locale = function_exists( 'get_user_locale' ) ? (string) get_user_locale( $user_id ) : '';

        // Capabilities: el batch trae el valor serializado — desserializar antes de codificar
        $caps_key = $wpdb->prefix . 'capabilities';
        $caps_raw = $meta[ $caps_key ] ?? '';
        $caps_val = maybe_unserialize( $caps_raw );
        $caps_json = '';
        if ( is_array( $caps_val ) || is_object( $caps_val ) ) {
            $caps_json = (string) wp_json_encode( $caps_val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        } elseif ( is_string( $caps_val ) && $caps_val !== '' ) {
            $caps_json = $caps_val;
        }

        // País
        $label  = (string) ( $country_details['label'] ?? 'Desconocido' );
        $source = (string) ( $country_details['source'] ?? 'unknown' );
        $raw    = (string) ( $country_details['country_lms_raw'] ?? '' );
        $iso2   = (string) ( $country_details['tutor_login_iso2'] ?? '' );

        // meta_json completo (opcional, costoso)
        $meta_json = '';
        if ( $include_meta_json ) {
            $meta_json = $this->flatten_user_meta_json( $user_id );
        }

        return [
            $user_id,
            $u ? (string) $u->user_login        : '',
            $first,
            $last,
            $u ? (string) $u->display_name       : '',
            $u ? (string) $u->user_email         : '',
            $u && is_array( $u->roles ) ? implode( ',', $u->roles ) : '',
            $caps_json,
            $desc,
            $u ? (string) $u->user_registered    : '',
            $locale,
            $label,
            $source,
            $raw,
            $iso2,
            $profile_type_lms,
            $profile_type_other_lms,
            $gender_lms,
            $age_range_lms,
            $age_parts['min'],
            $age_parts['max'],
            $age_parts['mid'],
            $phone,
            $billing_phone,
            $u ? (string) $u->user_url : '',
            $meta_json,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Batch: usermeta de todos los usuarios en 1 query (por chunk de 2000)
    // ──────────────────────────────────────────────────────────────────────

    private function get_user_meta_batch( array $user_ids ) {
        global $wpdb;

        if ( empty( $user_ids ) ) return [];

        $meta_keys = [
            'first_name', 'last_name', 'description',
            'profile_type_lms', 'profile_type_other_lms',
            'gender_lms', 'age_range_lms',
            'phone', 'billing_phone',
            $wpdb->prefix . 'capabilities',
        ];

        $key_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
        $map = [];

        foreach ( array_chunk( $user_ids, 2000 ) as $chunk ) {
            $id_placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT user_id, meta_key, meta_value
                     FROM {$wpdb->usermeta}
                     WHERE user_id  IN ($id_placeholders)
                       AND meta_key IN ($key_placeholders)",
                    array_merge( $chunk, $meta_keys )
                ),
                ARRAY_A
            );

            foreach ( (array) $rows as $r ) {
                $uid = (int) ( $r['user_id'] ?? 0 );
                if ( $uid <= 0 ) continue;
                // Guardamos el valor raw; la deserialización ocurre en user_row()
                $map[ $uid ][ $r['meta_key'] ] = $r['meta_value'];
            }
        }

        return $map;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Inscripciones: qué cursos tiene cada usuario (todas, no solo el período)
    // ──────────────────────────────────────────────────────────────────────

    private function get_enrollment_map_for_users( array $user_ids, $post_type ) {
        global $wpdb;

        if ( empty( $user_ids ) ) return [];

        $map = []; // [user_id => [course_id => true]]

        foreach ( array_chunk( $user_ids, 2000 ) as $chunk ) {
            $in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT post_author AS user_id, post_parent AS course_id
                     FROM {$wpdb->posts}
                     WHERE post_type   = %s
                       AND post_status IN ('publish','completed')
                       AND post_parent > 0
                       AND post_author IN ($in)",
                    array_merge( [ $post_type ], $chunk )
                ),
                ARRAY_A
            );

            foreach ( (array) $rows as $r ) {
                $uid = (int) ( $r['user_id'] ?? 0 );
                $cid = (int) ( $r['course_id'] ?? 0 );
                if ( $uid > 0 && $cid > 0 ) {
                    $map[ $uid ][ $cid ] = true;
                }
            }
        }

        return $map;
    }

    // ──────────────────────────────────────────────────────────────────────
    // % de progreso para todos los pares usuario-curso inscritos
    // ──────────────────────────────────────────────────────────────────────

    private function build_progress_map( array $enrollment_map, array $course_ids ) {
        if ( empty( $enrollment_map ) || empty( $course_ids ) ) return [];

        $tutor       = new TutorLms();
        $course_set  = array_flip( $course_ids ); // lookup O(1)
        $map         = [];

        foreach ( $enrollment_map as $uid => $courses ) {
            foreach ( array_keys( $courses ) as $cid ) {
                if ( ! isset( $course_set[ $cid ] ) ) continue;
                $map[ $uid ][ $cid ] = round(
                    (float) $tutor->course_progress_percent( $cid, $uid ),
                    1
                );
            }
        }

        return $map;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers existentes (sin cambios)
    // ──────────────────────────────────────────────────────────────────────

    private function parse_age_range( $raw ) {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) return [ 'min' => '', 'max' => '', 'mid' => '' ];

        if ( preg_match( '/^\s*(\d{1,3})\s*-\s*(\d{1,3})\s*$/', $raw, $m ) ) {
            $min     = (int) $m[1];
            $max     = (int) $m[2];
            $mid     = ( $min + $max ) / 2;
            $mid_str = ( abs( $mid - round( $mid ) ) > 0.0001 )
                ? number_format( $mid, 1, '.', '' )
                : (string) (int) round( $mid );
            return [ 'min' => (string) $min, 'max' => (string) $max, 'mid' => $mid_str ];
        }

        if ( preg_match( '/^\s*(\d{1,3})\s*\+\s*$/', $raw, $m ) ) {
            return [ 'min' => (string) (int) $m[1], 'max' => '', 'mid' => '' ];
        }

        return [ 'min' => '', 'max' => '', 'mid' => '' ];
    }

    /**
     * Universo de usuarios del período.
     *
     * OJO: esta función consulta las DOS columnas de fecha, cada una en su zona.
     *  - wp_users.user_registered  -> UTC   ($start_utc / $end_utc)
     *  - wp_posts.post_date        -> local ($start / $end)
     * No unificar los parámetros.
     *
     * @param string      $start     Límite inicial local (post_date).
     * @param string      $end       Límite final local (post_date).
     * @param string      $post_type Post type de inscripción.
     * @param string|null $start_utc Límite inicial UTC (user_registered).
     * @param string|null $end_utc   Límite final UTC (user_registered).
     * @return int[]
     */
    private function get_user_universe_ids( $start, $end, $post_type, $start_utc = null, $end_utc = null ) {
        global $wpdb;

        // Si no se pasan, se cae a los locales: es el comportamiento de legacy.
        if ( null === $start_utc ) $start_utc = $start;
        if ( null === $end_utc )   $end_utc   = $end;

        $ids = [];

        // Registrados: user_registered, en UTC.
        if ( $start_utc && $end_utc ) {
            $registered = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->users} WHERE user_registered BETWEEN %s AND %s",
                    $start_utc, $end_utc
                ),
                ARRAY_A
            );
            foreach ( (array) $registered as $r ) {
                $ids[] = (int) ( $r['ID'] ?? 0 );
            }
        }

        // Inscriptos: post_date, en hora local.
        if ( $start && $end ) {
            $enrolled = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT post_author AS user_id
                     FROM {$wpdb->posts}
                     WHERE post_type   = %s
                       AND post_status IN ('publish','completed')
                       AND post_parent > 0
                       AND post_date BETWEEN %s AND %s",
                    $post_type, $start, $end
                ),
                ARRAY_A
            );
            foreach ( (array) $enrolled as $r ) {
                $ids[] = (int) ( $r['user_id'] ?? 0 );
            }
        }

        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        sort( $ids );
        return $ids;
    }

    private function get_country_details_for_users( $user_ids ) {
        global $wpdb;

        $user_ids = array_values( array_filter( array_map( 'intval', is_array( $user_ids ) ? $user_ids : [] ) ) );
        if ( empty( $user_ids ) ) return [];

        $out = [];
        foreach ( $user_ids as $uid ) {
            $out[ $uid ] = [
                'label'            => 'Desconocido',
                'source'           => 'unknown',
                'country_lms_raw'  => '',
                'tutor_login_iso2' => '',
            ];
        }

        foreach ( array_chunk( $user_ids, 2000 ) as $chunk ) {
            $in  = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            $sql = "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'country_lms' AND user_id IN ($in)";
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A );

            foreach ( (array) $rows as $r ) {
                $uid = (int) ( $r['user_id'] ?? 0 );
                if ( $uid <= 0 || ! isset( $out[ $uid ] ) ) continue;
                $raw = isset( $r['meta_value'] ) ? trim( (string) $r['meta_value'] ) : '';
                if ( $raw === '' ) continue;
                $out[ $uid ]['country_lms_raw'] = $raw;
                $out[ $uid ]['label']           = $this->normalize_country_label( $raw );
                $out[ $uid ]['source']          = 'country_lms';
            }
        }

        $missing = [];
        foreach ( $user_ids as $uid ) {
            if ( $out[ $uid ]['source'] === 'unknown' ) $missing[] = $uid;
        }

        if ( ! empty( $missing ) ) {
            foreach ( array_chunk( $missing, 2000 ) as $chunk ) {
                $in2  = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
                $sql2 = "SELECT umeta_id, user_id, meta_value
                         FROM {$wpdb->usermeta}
                         WHERE user_id IN ($in2)
                           AND meta_key LIKE 'tutor_login_%'
                         ORDER BY umeta_id DESC";
                $rows2 = $wpdb->get_results( $wpdb->prepare( $sql2, $chunk ), ARRAY_A );

                foreach ( (array) $rows2 as $r ) {
                    $uid = (int) ( $r['user_id'] ?? 0 );
                    if ( $uid <= 0 || ! isset( $out[ $uid ] ) ) continue;
                    if ( $out[ $uid ]['source'] !== 'unknown' ) continue;

                    $raw = trim( (string) ( $r['meta_value'] ?? '' ) );
                    if ( $raw === '' ) continue;

                    $json = json_decode( $raw, true );
                    if ( ! is_array( $json ) ) continue;

                    $iso2 = isset( $json['country'] ) ? strtoupper( trim( (string) $json['country'] ) ) : '';
                    if ( $iso2 === '' ) continue;

                    $out[ $uid ]['tutor_login_iso2'] = $iso2;
                    $out[ $uid ]['label']            = $this->normalize_country_label( $this->iso2_to_name( $iso2 ) );
                    $out[ $uid ]['source']           = 'tutor_login';
                }
            }
        }

        return $out;
    }

    private function flatten_user_meta_json( $user_id ) {
        $flat = [];
        $meta = get_user_meta( (int) $user_id );

        if ( is_array( $meta ) ) {
            foreach ( $meta as $k => $vals ) {
                if ( is_array( $vals ) ) {
                    $v0 = reset( $vals );
                    $flat[ $k ] = ( is_array( $v0 ) || is_object( $v0 ) )
                        ? (string) wp_json_encode( $v0, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
                        : (string) $v0;
                } else {
                    $flat[ $k ] = (string) $vals;
                }
            }
        }

        $meta_json = wp_json_encode( $flat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( is_string( $meta_json ) && mb_strlen( $meta_json, 'UTF-8' ) > 12000 ) {
            $meta_json = mb_substr( $meta_json, 0, 11990, 'UTF-8' ) . '…';
        }
        return (string) $meta_json;
    }
}
