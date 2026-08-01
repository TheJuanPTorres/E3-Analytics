<?php
/**
 * Desinstalación de E3 Analytics Dashboard.
 *
 * WordPress ejecuta este archivo al BORRAR el plugin desde el admin, no al
 * desactivarlo. La guarda de WP_UNINSTALL_PLUGIN es obligatoria: sin ella el
 * archivo sería ejecutable por petición directa.
 *
 * El plugin no crea tablas ni post types propios: solo lee. Lo único que deja
 * en la base son unas pocas opciones y los transients del reporte de país.
 * Nada de esto toca datos de WordPress ni de Tutor LMS.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Opciones huérfanas de etapas anteriores.
 *
 *   e3a_feedback_quiz_ids  Lista de quizzes de retroalimentación. La
 *                          funcionalidad se eliminó en 1.2.9.2-b1 y la fila se
 *                          dejó a propósito para poder revertir el código sin
 *                          perder la configuración. Acá sí se borra.
 *   e3a_date_mode          Selector de modo de fecha de la transición B1-B3.
 *   e3a_diag_enabled       Activador de la herramienta de diagnóstico.
 */
$e3a_options = array(
	'e3a_feedback_quiz_ids',
	'e3a_date_mode',
	'e3a_diag_enabled',
);

foreach ( $e3a_options as $e3a_option ) {
	delete_option( $e3a_option );

	// En multisitio, la opción puede estar además a nivel de red.
	if ( is_multisite() ) {
		delete_site_option( $e3a_option );
	}
}

/*
 * Transients del reporte de país. Se borran por SQL directo porque son claves
 * dinámicas (un md5 por combinación de período), así que no hay una lista de
 * nombres que recorrer. Cada transient son dos filas: el valor y su timeout.
 */
global $wpdb;

$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_e3a\_country\_%'
	    OR option_name LIKE '\_transient\_timeout\_e3a\_country\_%'"
);

if ( is_multisite() ) {
	$wpdb->query(
		"DELETE FROM {$wpdb->sitemeta}
		 WHERE meta_key LIKE '\_site\_transient\_e3a\_country\_%'
		    OR meta_key LIKE '\_site\_transient\_timeout\_e3a\_country\_%'"
	);
}

/*
 * Si hay un object cache externo, los transients viven ahí y el DELETE de arriba
 * no los alcanza. wp_cache_flush() es demasiado agresivo para un uninstall
 * (tiraría el caché de todo el sitio), así que se dejan expirar solos: el TTL
 * es de 60 segundos.
 */
