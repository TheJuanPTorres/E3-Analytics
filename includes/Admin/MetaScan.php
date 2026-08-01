<?php
namespace E3_Analytics\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ############################################################################
 * # TEMPORAL. BORRAR ESTE ARCHIVO EN EL RELEASE QUE AGREGUE LAS COLUMNAS      #
 * # DEMOGRAFICAS AL EXPORT DE PAIS.                                           #
 * #                                                                           #
 * # Junto con el archivo hay que borrar:                                      #
 * #   - el require_once de includes/Plugin.php                                #
 * #   - el dispatch de Admin\Page::render_dashboard()                         #
 * ############################################################################
 *
 * Lista los meta_key distintos de wp_usermeta con su frecuencia, para decidir
 * cuáles campos del formulario de registro van al export.
 *
 *   ?page=e3-analytics-dashboard&e3a_scan=metakeys
 *
 * Guarda: current_user_can('manage_options').
 *
 * Una sola consulta agregada. Los valores de ejemplo se REDACTAN cuando la
 * clave parece contener un secreto: sin eso, el propio script de descubrimiento
 * imprimiría en pantalla los tokens de sesión y las claves de terceros que
 * justamente queremos dejar de exponer.
 */
final class MetaScan {

	const PARAM = 'e3a_scan';

	/** Claves que WordPress core escribe por su cuenta. */
	const CORE_KEYS = array(
		'nickname', 'first_name', 'last_name', 'description', 'rich_editing',
		'syntax_highlighting', 'comment_shortcuts', 'admin_color', 'use_ssl',
		'show_admin_bar_front', 'locale', 'dismissed_wp_pointers',
		'session_tokens', 'community-events-location', 'wp_user-settings',
		'wp_user-settings-time', 'default_password_nag', 'primary_blog',
		'source_domain', 'closedpostboxes_dashboard', 'metaboxhidden_dashboard',
	);

	/** Sufijos/infijos que delatan un secreto. El valor no se imprime. */
	const SENSITIVE = '/(session_tokens|token|secret|password|passwd|pwd|salt|nonce|_key$|^_transient|2fa|totp|otp|recovery|api)/i';

	/**
	 * @return bool True si renderizó y el llamador NO debe seguir.
	 */
	public static function maybe_render() {
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return false;
		}

		if ( 'metakeys' !== sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) ) {
			return false;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		global $wpdb;

		$prefix = $wpdb->prefix;

		$rows = $wpdb->get_results(
			"SELECT meta_key,
			        COUNT(DISTINCT user_id) AS usuarios,
			        COUNT(*)                AS filas,
			        SUBSTRING(MAX(meta_value), 1, 120) AS ejemplo
			 FROM {$wpdb->usermeta}
			 GROUP BY meta_key
			 ORDER BY usuarios DESC, meta_key ASC",
			ARRAY_A
		);

		$total_users = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );

		echo '<div class="wrap"><h1>E3 Analytics — meta_key de wp_usermeta</h1>';
		echo '<p><em>Herramienta temporal. Se elimina al agregar las columnas demográficas.</em></p>';
		echo '<pre style="white-space:pre-wrap;word-break:break-word;background:#fff;border:1px solid #ccd0d4;padding:14px;font-size:12px;line-height:1.5;">';

		printf( "Usuarios en wp_users : %s\n", esc_html( number_format( $total_users, 0, ',', '.' ) ) );
		printf( "meta_key distintos   : %s\n\n", esc_html( number_format( count( (array) $rows ), 0, ',', '.' ) ) );

		echo "Marca:  [core] lo escribe WordPress   [plugin] prefijo de tabla   [?] candidato\n";
		echo "Los valores de claves sensibles salen como [redactado], a proposito.\n\n";

		printf( "%-4s %-38s %9s %9s  %s\n", 'tipo', 'meta_key', 'usuarios', 'filas', 'ejemplo' );
		echo str_repeat( '-', 130 ) . "\n";

		foreach ( (array) $rows as $r ) {
			$key   = (string) ( $r['meta_key'] ?? '' );
			$users = (int) ( $r['usuarios'] ?? 0 );
			$count = (int) ( $r['filas'] ?? 0 );

			if ( in_array( $key, self::CORE_KEYS, true ) ) {
				$tipo = 'core';
			} elseif ( '' !== $prefix && 0 === strpos( $key, $prefix ) ) {
				$tipo = 'core';
			} elseif ( 0 === strpos( $key, '_' ) ) {
				$tipo = 'plug';
			} else {
				$tipo = '?';
			}

			if ( 1 === preg_match( self::SENSITIVE, $key ) ) {
				$sample = '[redactado]';
			} else {
				$sample = (string) ( $r['ejemplo'] ?? '' );
				$sample = preg_replace( '/\s+/', ' ', $sample );
				if ( strlen( $sample ) > 120 ) {
					$sample = substr( $sample, 0, 117 ) . '...';
				}
			}

			printf(
				"%-4s %-38s %9s %9s  %s\n",
				esc_html( $tipo ),
				esc_html( $key ),
				esc_html( number_format( $users, 0, ',', '.' ) ),
				esc_html( number_format( $count, 0, ',', '.' ) ),
				esc_html( $sample )
			);
		}

		echo "\n";
		printf( "consultas de este scan: %d\n", 2 );

		echo '</pre></div>';

		return true;
	}
}
