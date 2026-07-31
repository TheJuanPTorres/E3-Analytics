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
     * Versión enriquecida de is_course_completed().
     *
     * Un alumno se considera completado si:
     *   a) Tutor LMS lo marca como completado formalmente, O
     *   b) Tiene 100% de progreso.
     *
     * El caso (b) existe porque Tutor deja de marcar el curso como completado
     * cuando hay respuestas abiertas pendientes de revisión, aunque el alumno ya
     * haya recorrido todo el contenido.
     *
     * Hasta la versión 1.2.9.1-b1 el caso (b) además exigía que todos los
     * quizzes bloqueantes estuvieran declarados como "de retroalimentación" en
     * una pantalla de configuración. Ese filtro se eliminó: la medición sobre
     * 3.071 pares curso-usuario y 4 años de historia no encontró UNA SOLA
     * inscripción que rechazara (period=30: 123=123, period=365: 628=628,
     * period=all: 1178=1178). Los quizzes son parte del contenido del curso, así
     * que llegar al 100% de progreso ya implicaba haberlos rendido: la condición
     * era tautológica y solo costaba hasta 4 queries por par.
     */
    public function is_effectively_completed( $course_id, $user_id ) {
        $course_id = (int) $course_id;
        $user_id   = (int) $user_id;

        // Señal formal de Tutor LMS.
        if ( $this->is_course_completed( $course_id, $user_id ) ) return true;

        // 100% de progreso cuenta como completado, punto.
        return ( (float) $this->course_progress_percent( $course_id, $user_id ) ) >= 100.0;
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
