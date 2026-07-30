<?php
namespace E3_Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Gestiona la configuración del plugin: quizzes marcados como retroalimentación.
 */
final class Settings {

    const OPTION_KEY = 'e3a_feedback_quiz_ids';

    /**
     * Modo de resolución de fechas. TEMPORAL (etapa B1): este control y su
     * opción se eliminan al cerrar B2, cuando el modo calendario pase a ser el
     * único comportamiento.
     */
    const OPTION_DATE_MODE = 'e3a_date_mode';

    /**
     * Activador de la página de diagnóstico. TEMPORAL (etapa B1): se elimina
     * junto con includes/Admin/Diagnostics.php al cerrar B2.
     */
    const OPTION_DIAG = 'e3a_diag_enabled';

    /**
     * Modo de fechas persistido. Cadena vacía = sin configurar, y entonces
     * DatePeriod cae a 'legacy'.
     *
     * @return string
     */
    public static function get_date_mode() {
        $val = (string) get_option( self::OPTION_DATE_MODE, '' );

        return in_array( $val, \E3_Analytics\Support\DatePeriod::modes(), true ) ? $val : '';
    }

    /**
     * @param string $mode
     * @return bool True si el valor era válido y se guardó.
     */
    public static function save_date_mode( $mode ) {
        $mode = (string) $mode;

        if ( ! in_array( $mode, \E3_Analytics\Support\DatePeriod::modes(), true ) ) {
            return false;
        }

        update_option( self::OPTION_DATE_MODE, $mode, false );

        return true;
    }

    /**
     * @return bool
     */
    public static function is_diag_enabled() {
        return (bool) get_option( self::OPTION_DIAG, false );
    }

    /**
     * @param bool $enabled
     */
    public static function save_diag_enabled( $enabled ) {
        update_option( self::OPTION_DIAG, $enabled ? 1 : 0, false );
    }

    /**
     * Devuelve el array de IDs de quizzes marcados como retroalimentación.
     * @return int[]
     */
    public static function get_feedback_quiz_ids() {
        $val = get_option( self::OPTION_KEY, [] );
        return is_array( $val )
            ? array_values( array_filter( array_map( 'intval', $val ) ) )
            : [];
    }

    /**
     * Guarda la lista de IDs de quizzes de retroalimentación.
     * @param int[] $ids
     */
    public static function save_feedback_quiz_ids( array $ids ) {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        update_option( self::OPTION_KEY, $ids, false );
    }

    /**
     * Indica si un quiz específico está marcado como retroalimentación.
     */
    public static function is_feedback_quiz( $quiz_id ) {
        return in_array( (int) $quiz_id, self::get_feedback_quiz_ids(), true );
    }

    /**
     * Devuelve todos los quizzes publicados agrupados por curso.
     *
     * Estructura:
     * [
     *   course_id => [
     *     'title'   => 'Nombre del curso',
     *     'quizzes' => [ ['id' => 123, 'title' => 'Nombre quiz'], ... ]
     *   ]
     * ]
     */
    public static function get_quizzes_by_course() {
        global $wpdb;

        // Tutor LMS: courses → topics (post_parent = course_id) → quizzes (post_parent = topic_id)
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            "SELECT q.ID         AS quiz_id,
                    q.post_title AS quiz_title,
                    c.ID         AS course_id,
                    c.post_title AS course_title
             FROM {$wpdb->posts} q
             INNER JOIN {$wpdb->posts} t
               ON  t.ID        = q.post_parent
               AND t.post_type = 'topics'
             INNER JOIN {$wpdb->posts} c
               ON  c.ID          = t.post_parent
               AND c.post_type   = 'courses'
               AND c.post_status = 'publish'
             WHERE q.post_type   = 'tutor_quiz'
               AND q.post_status = 'publish'
             ORDER BY c.post_title ASC, q.menu_order ASC",
            ARRAY_A
        );

        $grouped = [];
        foreach ( (array) $rows as $r ) {
            $cid = (int) ( $r['course_id'] ?? 0 );
            if ( $cid <= 0 ) continue;

            if ( ! isset( $grouped[ $cid ] ) ) {
                $grouped[ $cid ] = [
                    'title'   => (string) ( $r['course_title'] ?? '' ),
                    'quizzes' => [],
                ];
            }

            $grouped[ $cid ]['quizzes'][] = [
                'id'    => (int) ( $r['quiz_id'] ?? 0 ),
                'title' => (string) ( $r['quiz_title'] ?? '' ),
            ];
        }

        return $grouped;
    }
}
