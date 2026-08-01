<?php
namespace E3_Analytics\Admin;

use E3_Analytics\Support\CountryHelper;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ############################################################################
 * # TEMPORAL. BORRAR JUNTO CON MetaScan.php.                                  #
 * ############################################################################
 *
 * SIMULACIÓN de la resolución de país: distribución con la lógica ACTUAL y con
 * `_pais` como fallback, lado a lado. NO aplica nada.
 *
 * La lógica actual tiene DOS fuentes, no una: `country_lms` primero y el JSON de
 * `tutor_login_*` después. La simulación agrega `_pais` como TERCERA, con la
 * menor precedencia, y desglosa por fuente.
 *
 * Las dos representaciones se unifican con normalize_country_label(), que ya
 * convierte ISO-2 a nombre cuando el valor tiene 2 caracteres. Así "CO" y
 * "Colombia" caen en el mismo bucket sin necesidad de un mapa nuevo.
 */
final class CountrySim {

	use CountryHelper;

	/** A partir de cuántos usuarios un valor de _pais merece revisarse. */
	const SUSPICIOUS_MIN_USERS = 10;

	/** Ventana de registro, en días, por debajo de la cual el valor huele a default. */
	const SUSPICIOUS_MAX_DAYS = 60;

	public static function render() {
		( new self() )->run();
	}

	private function run() {
		global $wpdb;

		$this->h( 'SIMULACION: distribucion por pais, ACTUAL vs con _pais de fallback' );

		// Qué camino toma iso2_to_name(): cambia el nombre resultante, y por lo
		// tanto el bucket. Con intl da el nombre ICU; sin intl, el mapa fijo de
		// 14 países y para el resto devuelve el código tal cual.
		printf(
			"  iso2_to_name() usa       : %s\n",
			class_exists( '\Locale' ) ? 'intl / ICU' : 'el mapa fijo de 14 paises (sin intl)'
		);
		printf(
			"  mbstring cargada         : %s\n",
			extension_loaded( 'mbstring' ) ? 'SI' : 'NO  (no se aplica Title Case)'
		);
		printf( "  iso2_to_name('CO')       : %s\n", esc_html( $this->iso2_to_name( 'CO' ) ) );
		printf( "  iso2_to_name('AF')       : %s\n", esc_html( $this->iso2_to_name( 'AF' ) ) );
		printf( "  normalize('Colombia')    : %s\n", esc_html( $this->normalize_country_label( 'Colombia' ) ) );
		printf( "  normalize('CO')          : %s\n\n", esc_html( $this->normalize_country_label( 'CO' ) ) );

		$country = $this->fetch_meta( 'country_lms' );
		$pais    = $this->fetch_meta( '_pais' );
		$login   = $this->fetch_login_countries();

		$total_users = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );

		$uids = array_unique(
			array_merge( array_keys( $country ), array_keys( $pais ), array_keys( $login ) )
		);

		$hoy       = array();
		$nuevo     = array();
		$src       = array( 'country_lms' => 0, 'tutor_login' => 0, '_pais' => 0 );
		$sin_hoy   = 0;
		$sin_nuevo = 0;

		foreach ( $uids as $uid ) {
			// ACTUAL: country_lms -> tutor_login_*
			$a      = '';
			$fuente = '';

			if ( isset( $country[ $uid ] ) ) {
				$a      = $this->normalize_country_label( $country[ $uid ] );
				$fuente = 'country_lms';
			} elseif ( isset( $login[ $uid ] ) ) {
				$a      = $this->normalize_country_label( $this->iso2_to_name( $login[ $uid ] ) );
				$fuente = 'tutor_login';
			}

			// NUEVA: lo mismo, y _pais al final.
			$b = $a;
			if ( '' === $b && isset( $pais[ $uid ] ) ) {
				$b      = $this->normalize_country_label( $pais[ $uid ] );
				$fuente = '_pais';
			}

			if ( '' === $a ) {
				$sin_hoy++;
			} else {
				$hoy[ $a ] = ( $hoy[ $a ] ?? 0 ) + 1;
			}

			if ( '' === $b ) {
				$sin_nuevo++;
			} else {
				$nuevo[ $b ] = ( $nuevo[ $b ] ?? 0 ) + 1;
			}

			if ( '' !== $fuente ) {
				$src[ $fuente ]++;
			}
		}

		$con_hoy   = array_sum( $hoy );
		$con_nuevo = array_sum( $nuevo );

		printf( "  usuarios en wp_users             : %s\n", esc_html( $this->n( $total_users ) ) );
		printf( "  con pais HOY                     : %s\n", esc_html( $this->n( $con_hoy ) ) );
		printf( "  con pais CON FALLBACK            : %s\n", esc_html( $this->n( $con_nuevo ) ) );
		printf( "  GANANCIA                         : %s usuarios\n", esc_html( $this->n( $con_nuevo - $con_hoy ) ) );
		printf(
			"  sin pais HOY / CON FALLBACK      : %s / %s\n\n",
			esc_html( $this->n( $sin_hoy ) ),
			esc_html( $this->n( $sin_nuevo ) )
		);

		printf( "  fuente (con fallback) country_lms: %s\n", esc_html( $this->n( $src['country_lms'] ) ) );
		printf( "                        tutor_login: %s\n", esc_html( $this->n( $src['tutor_login'] ) ) );
		printf( "                        _pais      : %s\n\n", esc_html( $this->n( $src['_pais'] ) ) );

		arsort( $nuevo );

		printf( "  %-34s %9s %9s %9s\n", 'pais', 'HOY', 'NUEVO', 'delta' );
		echo '  ' . str_repeat( '-', 64 ) . "\n";

		foreach ( $nuevo as $label => $cnt ) {
			$h     = (int) ( $hoy[ $label ] ?? 0 );
			$delta = $cnt - $h;

			printf(
				"  %-34s %9s %9s %9s\n",
				esc_html( substr( (string) $label, 0, 34 ) ),
				esc_html( $this->n( $h ) ),
				esc_html( $this->n( $cnt ) ),
				esc_html( $delta > 0 ? '+' . $this->n( $delta ) : '0' )
			);
		}

		$solo_nuevos = array_diff_key( $nuevo, $hoy );

		if ( ! empty( $solo_nuevos ) ) {
			echo "\n  Paises que HOY no aparecen y entrarian solo por _pais:\n";
			foreach ( $solo_nuevos as $label => $cnt ) {
				printf(
					"    %-34s %s\n",
					esc_html( substr( (string) $label, 0, 34 ) ),
					esc_html( $this->n( $cnt ) )
				);
			}
		}

		$this->suspicious( $pais, $country );
	}

	/**
	 * Valores de _pais sospechosos de ser el default de un <select>.
	 *
	 * Hipotesis: si un valor tiene muchos usuarios, no tiene contraparte en
	 * country_lms, y todos esos usuarios se registraron en una ventana estrecha,
	 * es el valor por defecto del formulario y no gente real.
	 *
	 * Este bloque NO decide nada: imprime los numeros para que decida una persona.
	 */
	private function suspicious( array $pais, array $country ) {
		global $wpdb;

		$this->h( 'VALORES DE _pais SIN CONTRAPARTE EN country_lms (>= ' . self::SUSPICIOUS_MIN_USERS . ' usuarios)' );

		$nombres_country = array();
		foreach ( $country as $v ) {
			$nombres_country[ $this->normalize_country_label( $v ) ] = true;
		}

		$por_valor = array();
		foreach ( $pais as $uid => $v ) {
			$por_valor[ strtoupper( trim( $v ) ) ][] = (int) $uid;
		}

		uasort(
			$por_valor,
			static function ( $a, $b ) {
				return count( $b ) <=> count( $a );
			}
		);

		$hay = false;

		foreach ( $por_valor as $code => $ids ) {
			if ( count( $ids ) < self::SUSPICIOUS_MIN_USERS ) {
				continue;
			}

			$nombre = $this->normalize_country_label( (string) $code );
			if ( isset( $nombres_country[ $nombre ] ) ) {
				continue;
			}

			$hay = true;

			$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT MIN(user_registered) AS primero, MAX(user_registered) AS ultimo
					 FROM {$wpdb->users}
					 WHERE ID IN ($in)",
					$ids
				),
				ARRAY_A
			);

			$primero = (string) ( $row['primero'] ?? '' );
			$ultimo  = (string) ( $row['ultimo'] ?? '' );
			$dias    = ( $primero && $ultimo )
				? (int) floor( ( strtotime( $ultimo ) - strtotime( $primero ) ) / DAY_IN_SECONDS )
				: 0;

			printf(
				"  %s  (%s) : %s usuarios\n",
				esc_html( (string) $code ),
				esc_html( $nombre ),
				esc_html( $this->n( count( $ids ) ) )
			);
			printf( "    primer registro : %s\n", esc_html( $primero ) );
			printf( "    ultimo registro : %s\n", esc_html( $ultimo ) );
			printf(
				"    ventana         : %s dias%s\n\n",
				esc_html( (string) $dias ),
				$dias <= self::SUSPICIOUS_MAX_DAYS ? '   <-- ventana estrecha: compatible con un default de formulario' : ''
			);
		}

		if ( ! $hay ) {
			echo "  (ninguno)\n";
		}

		echo "\n";
	}

	/**
	 * @param string $meta_key
	 * @return array<int,string> user_id => valor
	 */
	private function fetch_meta( $meta_key ) {
		global $wpdb;

		$out = array();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value
				 FROM {$wpdb->usermeta}
				 WHERE meta_key = %s AND meta_value <> ''",
				$meta_key
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['user_id'] ] = (string) $r['meta_value'];
		}

		return $out;
	}

	/**
	 * País del JSON de tutor_login_*, quedandose con el meta mas reciente por
	 * usuario (umeta_id DESC), igual que hace CountryAnalyticsService.
	 *
	 * @return array<int,string> user_id => ISO-2
	 */
	private function fetch_login_countries() {
		global $wpdb;

		$out = array();

		$rows = $wpdb->get_results(
			"SELECT user_id, meta_value
			 FROM {$wpdb->usermeta}
			 WHERE meta_key LIKE 'tutor_login_%'
			 ORDER BY umeta_id DESC",
			ARRAY_A
		);

		foreach ( (array) $rows as $r ) {
			$uid = (int) $r['user_id'];
			if ( isset( $out[ $uid ] ) ) {
				continue;
			}

			$json = json_decode( (string) $r['meta_value'], true );
			if ( is_array( $json ) && ! empty( $json['country'] ) ) {
				$out[ $uid ] = (string) $json['country'];
			}
		}

		return $out;
	}

	private function h( $title ) {
		echo "\n" . str_repeat( '=', 78 ) . "\n";
		echo esc_html( (string) $title ) . "\n";
		echo str_repeat( '=', 78 ) . "\n\n";
	}

	private function n( $v ) {
		return number_format( (int) $v, 0, ',', '.' );
	}
}
