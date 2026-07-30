<?php
namespace E3_Analytics\Integrations;

if ( ! defined( 'ABSPATH' ) ) exit;

final class TutorLms {

    public function is_course_completed($course_id, $user_id) {
        $course_id = (int) $course_id;
        $user_id   = (int) $user_id;

        if ($course_id <= 0 || $user_id <= 0) return false;

        if (function_exists('tutor_utils')) {
            $utils = tutor_utils();
            if (is_object($utils) && method_exists($utils, 'is_completed_course')) {
                try {
                    return (bool) $utils->is_completed_course($course_id, $user_id);
                } catch (\Throwable $e) {
                    return false;
                }
            }
        }
        return false;
    }

    /**
     * Versión enriquecida de is_course_completed() que tiene en cuenta los
     * quizzes de retroalimentación configurados en E3 Analytics.
     *
     * Un alumno se considera completado si:
     *   a) Tutor LMS lo marca como completado formalmente, O
     *   b) Tiene 100% de progreso Y todos los quizzes que bloquean la
     *      completación están marcados como "retroalimentación".
     */
    public function is_effectively_completed( $course_id, $user_id ) {
        $course_id = (int) $course_id;
        $user_id   = (int) $user_id;

        // Señal formal de Tutor LMS.
        if ( $this->is_course_completed( $course_id, $user_id ) ) return true;

        // Sin progreso completo no hay nada más que evaluar.
        $progress = (float) $this->course_progress_percent( $course_id, $user_id );
        if ( $progress < 100.0 ) return false;

        // Progreso = 100% pero sin marca formal.
        // Verificar si todos los quizzes bloqueantes son de retroalimentación.
        $feedback_ids = \E3_Analytics\Settings::get_feedback_quiz_ids();
        if ( empty( $feedback_ids ) ) return false;

        global $wpdb;

        // Obtener topics del curso.
        $topic_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type   = 'topics'
                   AND post_parent = %d
                   AND post_status = 'publish'",
                $course_id
            )
        );

        if ( empty( $topic_ids ) ) return true; // sin topics → sin quizzes → puede completar

        $in = implode( ',', array_fill( 0, count( $topic_ids ), '%d' ) );
        $all_quiz_ids = array_map( 'intval', (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type   = 'tutor_quiz'
                   AND post_status = 'publish'
                   AND post_parent IN ($in)",
                $topic_ids
            )
        ) );

        if ( empty( $all_quiz_ids ) ) return true; // sin quizzes → puede completar

        // Verificar tabla de intentos de Tutor LMS.
        $attempts_table = $wpdb->prefix . 'tutor_quiz_attempts';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $attempts_table ) ) !== $attempts_table ) {
            return false; // tabla no existe → conservador
        }

        // Quizzes con al menos un intento calificado (earned_marks asignado = revisado).
        $graded_ids = array_map( 'intval', (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT quiz_id FROM {$attempts_table}
                 WHERE course_id    = %d
                   AND user_id      = %d
                   AND earned_marks IS NOT NULL",
                $course_id,
                $user_id
            )
        ) );

        // Quizzes del curso sin calificación asignada (pendientes de revisión).
        $ungraded = array_diff( $all_quiz_ids, $graded_ids );

        if ( empty( $ungraded ) ) return true; // todo calificado

        // Todos los no-calificados deben estar en la lista de retroalimentación.
        foreach ( $ungraded as $qid ) {
            if ( ! in_array( $qid, $feedback_ids, true ) ) return false;
        }

        return true;
    }

    public function course_progress_percent($course_id, $user_id) {
        $course_id = (int) $course_id;
        $user_id   = (int) $user_id;

        if ($course_id <= 0 || $user_id <= 0) return 0.0;
        if (!function_exists('tutor_utils')) return 0.0;

        $utils = tutor_utils();
        if (!is_object($utils)) return 0.0;

        $candidates = array(
            'get_course_completed_percent',
            'get_course_completed_percentage',
        );

        foreach ($candidates as $method) {
            if (!method_exists($utils, $method)) continue;

            try {
                $v = $utils->{$method}($course_id, $user_id);
                return (float) $v;
            } catch (\Throwable $e) {}

            try {
                $v = $utils->{$method}($course_id);
                return (float) $v;
            } catch (\Throwable $e) {}
        }

        return 0.0;
    }
}
