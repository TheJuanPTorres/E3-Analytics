<?php
namespace E3_Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Gestiona la configuración del plugin.
 *
 * Hoy solo quedan las dos opciones temporales de la etapa B1 (modo de fecha y
 * activador del diagnóstico); las dos se eliminan al cerrar B2.
 *
 * La opción 'e3a_feedback_quiz_ids' ya no se lee ni se escribe desde ninguna
 * parte: la funcionalidad de quizzes de retroalimentación se eliminó en la
 * versión 1.2.9.2-b1. La fila se deja a propósito en wp_options para poder
 * revertir el código sin perder la configuración anterior. Se limpia en el
 * uninstall.php pendiente.
 */
final class Settings {

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
}
