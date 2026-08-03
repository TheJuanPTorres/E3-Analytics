<?php
namespace E3_Analytics\Services;

use E3_Analytics\Support\DatePeriod;
use E3_Analytics\Support\CountryResolver;
use E3_Analytics\Repositories\UsersRepository;
use E3_Analytics\Integrations\TutorLms;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Exporta datos personales de usuarios (desde la sección País) a Excel (XLSX).
 * Universo: usuarios registrados en el período y/o con inscripciones creadas en el período.
 *
 * Hoja "Usuarios": columnas fijas de perfil + columnas dinámicas de % avance por curso.
 */
final class CountryUsersExportService {
    /**
     * Set de IDs registrados dentro del período, con los IDs como CLAVES.
     * Lo llena get_user_universe_ids() reusando la query que ya hacía.
     *
     * @var array<int,int>
     */
    private $registered_in_period = array();

    /**
     * Campos demográficos del formulario de registro.
     *
     * meta_key => encabezado de la columna. El orden de este array es el orden
     * de las columnas en la hoja.
     *
     * Cobertura parcial a propósito: el formulario cambió con el tiempo, así que
     * de ~3.446 usuarios solo ~748 tienen gender_lms y ~598 department_lms. Una
     * celda vacía significa "este usuario se registró antes de que el campo
     * existiera", que es un dato. NO rellenar con valores por defecto ni con
     * "N/A": eso convertiría un hueco informativo en ruido.
     */
    const DEMOGRAPHIC_FIELDS = [
        'gender_lms'                    => 'Género',
        'age_range_lms'                 => 'Rango de edad',
        'department_lms'                => 'Departamento',
        'organization_lms'              => 'Organización',
        'indigenous_community_lms'      => 'Comunidad indígena (sí/no)',
        'indigenous_community_name_lms' => 'Nombre de la comunidad',
        'role_lms'                      => 'Rol',
        'profile_type_lms'              => 'Tipo de perfil',
        'profile_type_other_lms'        => 'Tipo de perfil (otro)',
        'content_format_lms'            => 'Formato de contenido',
        'referral_source_lms'           => 'Cómo nos conoció',
        'expectations_lms'              => 'Expectativas',
        'purpose_lms'                   => 'Propósito',
    ];

    /**
     * Datos de contacto identificatorios.
     *
     * SON PII SENSIBLE. Un documento de identidad y un teléfono personal
     * identifican a la persona de forma directa, y este archivo SALE DEL
     * SERVIDOR: se manda por correo, por WhatsApp, se sube a un Drive
     * compartido. Cada copia es una fuga potencial que ya no controlamos.
     *
     * El filtro e3a_export_include_contact_pii permite excluirlas sin tocar
     * código:
     *
     *     add_filter( 'e3a_export_include_contact_pii', '__return_false' );
     *
     * Default TRUE por decisión explícita del proyecto, no por descuido.
     * Cuando estas columnas están excluidas, las claves ni siquiera se leen de
     * la base.
     */
    const CONTACT_PII_FIELDS = [
        'id_number_lms' => 'Documento de identidad',
        'phone_lms'     => 'Teléfono',
    ];

    /**
     * ¿Se incluyen las columnas de contacto identificatorio?
     *
     * @return bool
     */
    private function include_contact_pii() {
        return (bool) apply_filters( 'e3a_export_include_contact_pii', true );
    }

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

        $include_pii = $this->include_contact_pii();

        // ── 4. Batch: usermeta relevante (1 query, no N*9) ────────────────
        $meta_map = $this->get_user_meta_batch( $user_ids, $include_pii );

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
        /*
         * Las cuatro columnas demográficas que ya existían (gender_lms,
         * age_range_lms, profile_type_lms, profile_type_other_lms) se MUEVEN al
         * bloque nuevo en vez de duplicarse: hay una sola columna por meta_key.
         * age_min/age_max/age_midpoint son derivadas de "Rango de edad" y quedan
         * pegadas a ella.
         */
        $demographic_header = [];
        foreach ( self::DEMOGRAPHIC_FIELDS as $meta_key => $label ) {
            $demographic_header[] = $label;

            if ( 'age_range_lms' === $meta_key ) {
                $demographic_header[] = 'age_min';
                $demographic_header[] = 'age_max';
                $demographic_header[] = 'age_midpoint';
            }
        }

        $fixed_header = array_merge(
            [
                'user_id', 'nickname', 'first_name', 'last_name', 'display_name',
                'user_email', 'roles', 'capabilities_json', 'description',
                'user_registered', 'Registrado en el período',
            ],
            $demographic_header,
            $include_pii ? array_values( self::CONTACT_PII_FIELDS ) : [],
            [
                'locale',
                'Código de país', 'País', 'Origen del dato', 'Valor original',
                'phone', 'billing_phone', 'user_url', 'meta_json',
            ]
        );

        $course_header = array_values( $course_titles ); // un encabezado por curso
        $rows          = [ array_merge( $fixed_header, $course_header ) ];

        foreach ( $user_ids as $uid ) {
            $fixed_cols  = $this->user_row(
                $uid,
                $country_map[ $uid ] ?? null,
                $meta_map[ $uid ] ?? [],
                (bool) $include_meta_json,
                $include_pii
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
                        [ 'Período',              (string) ( $dates['label'] ?? $period_key ) ],
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

    private function user_row( $user_id, $country_details = null, array $meta = [], $include_meta_json = false, $include_pii = true ) {
        global $wpdb;

        $user_id = (int) $user_id;
        $u       = get_userdata( $user_id ); // servido desde cache WP (pre-cargado en build())

        // Campos de perfil desde meta pre-cargado
        $first                  = (string) ( $meta['first_name'] ?? '' );
        $last                   = (string) ( $meta['last_name'] ?? '' );
        $desc                   = (string) ( $meta['description'] ?? '' );
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

        /*
         * País, resuelto por CountryResolver. 'Origen del dato' es lo que le
         * permite a la clienta saber de qué formulario salió cada valor: hoy
         * conviven tres.
         */
        $iso2   = (string) ( $country_details['iso2'] ?? '' );
        $label  = (string) ( $country_details['label'] ?? '' );
        $source = CountryResolver::source_label( (string) ( $country_details['source'] ?? '' ) );
        $raw    = (string) ( $country_details['raw'] ?? '' );

        if ( '' === $label ) {
            $label = 'Desconocido';
        }

        // meta_json completo (opcional, costoso)
        $meta_json = '';
        if ( $include_meta_json ) {
            $meta_json = $this->flatten_user_meta_json( $user_id );
        }

        /*
         * El bloque demográfico se arma recorriendo la MISMA constante que
         * genera el encabezado, así el orden no puede desincronizarse. Una
         * celda vacía significa que ese usuario se registró antes de que el
         * campo existiera en el formulario: no se rellena con nada.
         */
        $demographic = [];
        foreach ( self::DEMOGRAPHIC_FIELDS as $meta_key => $unused_label ) {
            $demographic[] = (string) ( $meta[ $meta_key ] ?? '' );

            if ( 'age_range_lms' === $meta_key ) {
                $demographic[] = $age_parts['min'];
                $demographic[] = $age_parts['max'];
                $demographic[] = $age_parts['mid'];
            }
        }

        $contact_pii = [];
        if ( $include_pii ) {
            foreach ( self::CONTACT_PII_FIELDS as $meta_key => $unused_label ) {
                $contact_pii[] = (string) ( $meta[ $meta_key ] ?? '' );
            }
        }

        return array_merge(
            [
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
                // Un usuario borrado no está en el set: cae en 'no'. Correcto, se
                // registró en algún momento del pasado, no dentro del período.
                isset( $this->registered_in_period[ $user_id ] ) ? 'sí' : 'no',
            ],
            $demographic,
            $contact_pii,
            [
                $locale,
                $iso2,
                $label,
                $source,
                $raw,
                $phone,
                $billing_phone,
                $u ? (string) $u->user_url : '',
                $meta_json,
            ]
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Batch: usermeta de todos los usuarios en 1 query (por chunk de 2000)
    // ──────────────────────────────────────────────────────────────────────

    private function get_user_meta_batch( array $user_ids, $include_pii = true ) {
        global $wpdb;

        if ( empty( $user_ids ) ) return [];

        /*
         * Se alarga el IN, no se agregan consultas: sigue siendo UNA query por
         * cada 2.000 usuarios. Las claves de PII solo entran si el filtro las
         * habilita, así que con el filtro apagado ni se leen de la base.
         */
        $meta_keys = array_merge(
            [
                'first_name', 'last_name', 'description',
                'phone', 'billing_phone',
                $wpdb->prefix . 'capabilities',
            ],
            array_keys( self::DEMOGRAPHIC_FIELDS ),
            $include_pii ? array_keys( self::CONTACT_PII_FIELDS ) : []
        );

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

        /*
         * Registrados en el período. Se delega en la definición compartida en
         * lugar de repetir la query acá: si los dos lugares la escriben por su
         * cuenta, terminan divergiendo. El set se CONSERVA en
         * $this->registered_in_period para la columna "Registrado en el
         * período"; antes se descartaba después de armar el universo, y la
         * columna habría costado una query de más.
         */
        $users_repo = new UsersRepository();
        $registered = $users_repo->ids_registered_between( $start_utc, $end_utc );

        $this->registered_in_period = array_flip( $registered );

        foreach ( $registered as $rid ) {
            $ids[] = (int) $rid;
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

    /**
     * uid => ['iso2','label','source','raw'], delegando en el resolver unificado.
     *
     * La lógica vivía duplicada acá y en CountryAnalyticsService. Ahora las dos
     * consumen CountryResolver, que agrega _pais como tercera fuente y
     * canonicaliza a ISO-2 antes de agrupar.
     *
     * @param int[] $user_ids
     * @return array<int,array{iso2:string,label:string,source:string,raw:string}>
     */
    private function get_country_details_for_users( $user_ids ) {
        $user_ids = array_values( array_filter( array_map( 'intval', is_array( $user_ids ) ? $user_ids : [] ) ) );
        if ( empty( $user_ids ) ) return [];

        $resolver = new CountryResolver();

        return $resolver->resolve_for_users( $user_ids );
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
