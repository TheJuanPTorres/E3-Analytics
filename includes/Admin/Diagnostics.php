<?php
namespace E3_Analytics\Admin;

use E3_Analytics\Settings;
use E3_Analytics\Support\DatePeriod;
use E3_Analytics\Services\MetricsService;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ############################################################################
 * # TEMPORAL — ETAPA B1. BORRAR ESTE ARCHIVO COMPLETO AL CERRAR B2.          #
 * #                                                                          #
 * # Junto con este archivo hay que borrar:                                   #
 * #   - el require_once de includes/Plugin.php                               #
 * #   - el dispatch en Admin\Page::render_dashboard()                        #
 * #   - Settings::OPTION_DIAG y sus dos métodos                              #
 * #   - el bloque "Avanzado — temporal" de admin/views/settings.php          #
 * ############################################################################
 *
 * Herramienta de relevamiento y comparación. Existe porque el único entorno con
 * datos reales es producción y no hay acceso a phpMyAdmin ni a WP-CLI.
 *
 * Dos modos deliberadamente separados, porque uno es barato y el otro es caro:
 *
 *   ?page=e3-analytics-dashboard&e3a_diag=info
 *       Solo contadores. No instancia ningún servicio de métricas.
 *
 *   ?page=e3-analytics-dashboard&e3a_diag=compare&period=7
 *       Calcula el dashboard en los 3 modos de fecha y compara KPI por KPI.
 *       UN SOLO period por invocación: con el N+1 de completación sin arreglar,
 *       recorrer todos los presets en producción sería un incidente.
 *
 * Guarda: manage_options Y (opción e3a_diag_enabled O constante E3A_DIAG).
 * Sin las dos, el parámetro se ignora por completo.
 */
final class Diagnostics {

	const PARAM = 'e3a_diag';

	/**
	 * @return bool
	 */
	public static function is_enabled() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( defined( 'E3A_DIAG' ) && E3A_DIAG ) {
			return true;
		}

		return Settings::is_diag_enabled();
	}

	/**
	 * Modo pedido por query string, ya validado. Cadena vacía = ninguno.
	 *
	 * @return string 'info'|'compare'|''
	 */
	public static function requested() {
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return '';
		}

		$value = sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) );

		return in_array( $value, array( 'info', 'compare' ), true ) ? $value : '';
	}

	/**
	 * Renderiza el diagnóstico si corresponde.
	 *
	 * @return bool True si renderizó (y el llamador NO debe renderizar el dashboard).
	 */
	public static function maybe_render() {
		$requested = self::requested();

		if ( '' === $requested || ! self::is_enabled() ) {
			return false;
		}

		echo '<div class="wrap"><h1>E3 Analytics — diagnóstico (' . esc_html( $requested ) . ')</h1>';
		echo '<p><em>Herramienta temporal de la etapa B1. Texto plano, copiable.</em></p>';
		echo '<pre style="white-space:pre-wrap;word-break:break-word;background:#fff;border:1px solid #ccd0d4;padding:14px;font-size:12px;line-height:1.5;">';

		if ( 'info' === $requested ) {
			self::render_info();
		} else {
			self::render_compare();
		}

		echo '</pre></div>';

		return true;
	}

	// -----------------------------------------------------------------------
	// MODO INFO — barato
	// -----------------------------------------------------------------------

	private static function render_info() {
		global $wpdb;

		$post_type = apply_filters( 'e3a_enrollment_post_type', 'tutor_enrolled' );

		self::h( 'ENTORNO PHP' );
		self::kv( 'PHP_VERSION', PHP_VERSION );
		self::kv( 'mbstring cargada', extension_loaded( 'mbstring' ) ? 'SI' : 'NO' );
		self::kv( 'intl cargada', extension_loaded( 'intl' ) ? 'SI' : 'NO' );
		self::kv( 'zip cargada', extension_loaded( 'zip' ) ? 'SI' : 'NO  (rompe los export XLSX)' );
		self::kv( 'memory_limit', (string) ini_get( 'memory_limit' ) );
		self::kv( 'max_execution_time', (string) ini_get( 'max_execution_time' ) );
		self::kv( 'default_timezone PHP', date_default_timezone_get() );

		self::h( 'ZONA HORARIA DEL SITIO' );
		$tz         = wp_timezone();
		$epoch      = (int) current_time( 'timestamp', true );
		$local_now  = ( new \DateTimeImmutable( '@' . $epoch ) )->setTimezone( $tz );
		$utc_now    = ( new \DateTimeImmutable( '@' . $epoch ) )->setTimezone( new \DateTimeZone( 'UTC' ) );

		self::kv( 'option timezone_string', (string) get_option( 'timezone_string', '' ) );
		self::kv( 'option gmt_offset', (string) get_option( 'gmt_offset', '' ) );
		self::kv( 'wp_timezone()->getName()', $tz->getName() );
		self::kv( 'AHORA local', $local_now->format( 'Y-m-d H:i:s' ) );
		self::kv( 'AHORA UTC', $utc_now->format( 'Y-m-d H:i:s' ) );
		self::kv(
			'fecha local vs UTC',
			$local_now->format( 'Y-m-d' ) === $utc_now->format( 'Y-m-d' )
				? 'MISMO DIA'
				: 'DIAS DISTINTOS  <-- los registros de hoy caen en el dia UTC siguiente'
		);

		self::h( 'LOS CINCO PUNTOS QUE HABIAN QUEDADO SIN VERIFICAR' );
		self::kv( '1. mbstring / intl', ( extension_loaded( 'mbstring' ) ? 'mbstring SI' : 'mbstring NO' ) . ' / ' . ( extension_loaded( 'intl' ) ? 'intl SI' : 'intl NO' ) );
		self::kv( '2. option date_format', (string) get_option( 'date_format', '' ) );
		self::kv( '   option time_format', (string) get_option( 'time_format', '' ) );
		self::kv( '   ejemplo de label', date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $epoch + $local_now->getOffset() ) );
		self::kv( '3. get_locale()', function_exists( 'get_locale' ) ? get_locale() : '(n/d)' );
		self::kv( '   determine_locale()', function_exists( 'determine_locale' ) ? determine_locale() : '(n/d)' );
		self::kv( '4. object cache externo', function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ? 'SI' : 'NO (transients en wp_options)' );
		self::kv( '   round-trip de transient', self::probe_transient() );
		self::kv( '5. MySQL version', (string) $wpdb->get_var( 'SELECT VERSION()' ) );
		self::kv( '   @@sql_mode', (string) $wpdb->get_var( 'SELECT @@sql_mode' ) );
		echo "   (sql_mode se agrega por decision propia: si incluye ONLY_FULL_GROUP_BY,\n";
		echo "    el export key=first_time_enrollments esta roto hoy en produccion.)\n";

		self::h( 'BASE DE DATOS' );
		self::kv( 'prefijo de tabla', $wpdb->prefix );
		self::kv( 'modo de fecha activo', DatePeriod::resolve_mode() );
		self::kv( 'post type de inscripcion', (string) $post_type );

		self::h( 'USUARIOS' );
		$users = $wpdb->get_row(
			"SELECT COUNT(*) AS total, MIN(user_registered) AS min_reg, MAX(user_registered) AS max_reg
			 FROM {$wpdb->users}",
			ARRAY_A
		);
		self::kv( 'COUNT wp_users', self::num( $users['total'] ?? 0 ) );
		self::kv( 'MIN user_registered (UTC)', (string) ( $users['min_reg'] ?? '' ) );
		self::kv( 'MAX user_registered (UTC)', (string) ( $users['max_reg'] ?? '' ) );

		self::h( 'INSCRIPCIONES' );
		$enr = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
				        COUNT(DISTINCT post_parent) AS cursos,
				        COUNT(DISTINCT post_author) AS alumnos,
				        MIN(post_date) AS min_date,
				        MAX(post_date) AS max_date
				 FROM {$wpdb->posts}
				 WHERE post_type = %s
				   AND post_status IN ('publish','completed')
				   AND post_parent > 0",
				$post_type
			),
			ARRAY_A
		);
		self::kv( 'COUNT inscripciones', self::num( $enr['total'] ?? 0 ) );
		self::kv( 'cursos distintos (DISTINCT post_parent)', self::num( $enr['cursos'] ?? 0 ) );
		self::kv( 'alumnos distintos (DISTINCT post_author)', self::num( $enr['alumnos'] ?? 0 ) );
		self::kv( 'MIN post_date (local)', (string) ( $enr['min_date'] ?? '' ) );
		self::kv( 'MAX post_date (local)', (string) ( $enr['max_date'] ?? '' ) );

		self::h( 'PARA EL HARNESS — copiar y pegar' );
		$min_date = (string) ( $enr['min_date'] ?? '' );
		if ( '' !== $min_date ) {
			echo "  --min='" . esc_html( $min_date ) . "'\n";
		} else {
			echo "  (sin inscripciones: usar --min='')\n";
		}

		self::h( 'INSCRIPCIONES POR AÑO (GROUP BY YEAR(post_date))' );
		$by_year = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT YEAR(post_date) AS y, COUNT(*) AS c
				 FROM {$wpdb->posts}
				 WHERE post_type = %s
				   AND post_status IN ('publish','completed')
				   AND post_parent > 0
				 GROUP BY y
				 ORDER BY y ASC",
				$post_type
			),
			ARRAY_A
		);
		self::year_table( $by_year );

		self::h( 'USUARIOS POR AÑO DE REGISTRO (GROUP BY YEAR(user_registered))' );
		$users_by_year = $wpdb->get_results(
			"SELECT YEAR(user_registered) AS y, COUNT(*) AS c
			 FROM {$wpdb->users}
			 GROUP BY y
			 ORDER BY y ASC",
			ARRAY_A
		);
		self::year_table( $users_by_year );

		self::h( 'COSTO ESTIMADO POR PRESET (dias calendario, hora local)' );
		echo "  Sirve para decidir el tope de rango custom y si conviene correr\n";
		echo "  el modo compare para un preset dado.\n\n";

		$today = ( new \DateTimeImmutable( '@' . $epoch ) )->setTimezone( $tz );

		foreach ( array( 7, 30, 90, 365 ) as $n ) {
			$start = $today->sub( new \DateInterval( 'P' . ( $n - 1 ) . 'D' ) )->setTime( 0, 0, 0 );
			$end   = $today->setTime( 23, 59, 59 );

			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM {$wpdb->posts}
					 WHERE post_type = %s
					   AND post_status IN ('publish','completed')
					   AND post_parent > 0
					   AND post_date BETWEEN %s AND %s",
					$post_type,
					$start->format( 'Y-m-d H:i:s' ),
					$end->format( 'Y-m-d H:i:s' )
				)
			);

			printf(
				"  ultimos %3d dias : %8s inscripciones   (%s .. %s)\n",
				$n,
				self::num( $count ),
				$start->format( 'Y-m-d H:i:s' ),
				$end->format( 'Y-m-d H:i:s' )
			);
		}

		echo "\n";
		self::kv( 'queries totales de este modo', (string) $wpdb->num_queries );
	}

	/**
	 * Round-trip real de transient, para saber si el caché funciona.
	 *
	 * @return string
	 */
	private static function probe_transient() {
		$key   = 'e3a_diag_probe_' . wp_rand( 100000, 999999 );
		$value = 'ok-' . $key;

		set_transient( $key, $value, 30 );
		$read = get_transient( $key );
		delete_transient( $key );

		if ( $read === $value ) {
			return 'OK (escribe y lee)';
		}

		return 'FALLA (escribio pero no leyo: hay un cache que descarta transients)';
	}

	/**
	 * @param array $rows
	 */
	private static function year_table( $rows ) {
		if ( empty( $rows ) ) {
			echo "  (sin datos)\n";
			return;
		}

		$total = 0;
		foreach ( (array) $rows as $r ) {
			$total += (int) ( $r['c'] ?? 0 );
		}

		foreach ( (array) $rows as $r ) {
			$year  = (string) ( $r['y'] ?? '?' );
			$count = (int) ( $r['c'] ?? 0 );
			$pct   = $total > 0 ? ( $count / $total ) * 100 : 0;
			$bar   = str_repeat( '#', (int) round( $pct / 2 ) );

			printf( "  %-6s %10s  %5.1f%%  %s\n", $year, self::num( $count ), $pct, $bar );
		}

		printf( "  %-6s %10s\n", 'TOTAL', self::num( $total ) );
	}

	// -----------------------------------------------------------------------
	// MODO COMPARE — caro
	// -----------------------------------------------------------------------

	private static function render_compare() {
		global $wpdb;

		$period = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : '30';

		$modes = array(
			DatePeriod::MODE_LEGACY,
			DatePeriod::MODE_CALENDAR,
			DatePeriod::MODE_CALENDAR_UTC,
		);

		// --- Cabecera: las fechas de los 3 modos ANTES de calcular nada. -----
		// Si el gateway corta la request por timeout, esto solo ya es util.
		self::h( 'PERIODO PEDIDO' );
		self::kv( 'period', $period );
		self::kv( 'modo persistido', DatePeriod::resolve_mode() );
		self::kv( 'tope de rango custom', (string) apply_filters( 'e3a_max_custom_range_days', DatePeriod::DEFAULT_MAX_CUSTOM_DAYS ) );

		$resolved = array();

		self::h( 'FECHAS RESUELTAS POR MODO' );
		foreach ( $modes as $mode ) {
			$dates            = self::with_mode( $mode, static function () use ( $period ) {
				return DatePeriod::resolve( $period );
			} );
			$resolved[ $mode ] = $dates;

			echo '  [' . esc_html( $mode ) . "]\n";
			foreach ( array( 'period_key', 'period_int', 'days', 'is_all', 'is_custom', 'preset_key' ) as $k ) {
				$v = $dates[ $k ] ?? '';
				if ( is_bool( $v ) ) {
					$v = $v ? 'true' : 'false';
				}
				printf( "    %-18s %s\n", $k, esc_html( (string) $v ) );
			}
			foreach ( array( 'current_start', 'current_end', 'prev_start', 'prev_end' ) as $k ) {
				printf(
					"    %-18s %-19s   utc: %s\n",
					$k,
					esc_html( (string) ( $dates[ $k ] ?? '' ) ),
					esc_html( (string) ( $dates[ $k . '_utc' ] ?? '' ) )
				);
			}
			printf( "    %-18s %s\n", 'label', esc_html( (string) ( $dates['label'] ?? '' ) ) );
			printf( "    %-18s %s\n", 'prev_label', esc_html( (string) ( $dates['prev_label'] ?? '' ) ) );
			if ( '' !== (string) ( $dates['notice'] ?? '' ) ) {
				printf( "    %-18s %s\n", 'notice', esc_html( (string) $dates['notice'] ) );
			}
			echo "\n";
		}

		self::flush_now();

		// --- Un bloque por modo, flusheado en cuanto termina. ----------------
		$kpis    = array();
		$timings = array();

		foreach ( $modes as $mode ) {
			$q0 = (int) $wpdb->num_queries;
			$t0 = microtime( true );

			$data = self::with_mode( $mode, static function () use ( $period ) {
				$service = new MetricsService();
				return $service->get_dashboard_data( $period );
			} );

			$elapsed = microtime( true ) - $t0;
			$queries = (int) $wpdb->num_queries - $q0;

			$kpis[ $mode ]    = (array) ( $data['kpis'] ?? array() );
			$timings[ $mode ] = array(
				'seconds' => $elapsed,
				'queries' => $queries,
			);

			self::h( 'MODO ' . strtoupper( $mode ) . ' — CALCULADO' );
			printf( "  tiempo de pared : %.2f s\n", $elapsed );
			printf( "  queries         : %s\n", self::num( $queries ) );
			printf( "  KPIs obtenidos  : %d\n\n", count( $kpis[ $mode ] ) );

			foreach ( $kpis[ $mode ] as $k => $v ) {
				printf( "    %-34s %s\n", $k, esc_html( self::scalar( $v ) ) );
			}
			echo "\n";

			self::flush_now();
		}

		// --- Tabla comparativa. ---------------------------------------------
		self::h( 'COMPARATIVA KPI POR KPI' );

		$all_keys = array();
		foreach ( $modes as $mode ) {
			foreach ( array_keys( $kpis[ $mode ] ) as $k ) {
				$all_keys[ $k ] = true;
			}
		}
		$all_keys = array_keys( $all_keys );

		printf(
			"  %-34s %14s %14s %10s %9s %14s %10s %9s\n",
			'KPI',
			'legacy',
			'calendar',
			'delta',
			'delta %',
			'calendar_utc',
			'delta',
			'delta %'
		);
		echo '  ' . str_repeat( '-', 122 ) . "\n";

		foreach ( $all_keys as $k ) {
			$base = $kpis[ DatePeriod::MODE_LEGACY ][ $k ] ?? null;
			$cal  = $kpis[ DatePeriod::MODE_CALENDAR ][ $k ] ?? null;
			$cutc = $kpis[ DatePeriod::MODE_CALENDAR_UTC ][ $k ] ?? null;

			list( $d_cal, $p_cal )   = self::delta( $base, $cal );
			list( $d_cutc, $p_cutc ) = self::delta( $base, $cutc );

			printf(
				"  %-34s %14s %14s %10s %9s %14s %10s %9s\n",
				$k,
				self::scalar( $base ),
				self::scalar( $cal ),
				$d_cal,
				$p_cal,
				self::scalar( $cutc ),
				$d_cutc,
				$p_cutc
			);
		}

		echo "\n";
		self::h( 'RESUMEN DE COSTO' );
		foreach ( $modes as $mode ) {
			printf(
				"  %-14s %6.2f s   %8s queries\n",
				$mode,
				$timings[ $mode ]['seconds'],
				self::num( $timings[ $mode ]['queries'] )
			);
		}

		echo "\n  Nota: el modo compare saltea los transients (filtro e3a_bypass_cache),\n";
		echo "  asi que estos tiempos son de calculo en frio, sin cache.\n";
	}

	/**
	 * Ejecuta $fn forzando el modo de fecha y salteando los transients.
	 *
	 * El filtro e3a_date_mode se aplica en cada resolve(), sin memoizar, por eso
	 * esto funciona tres veces dentro del mismo request.
	 *
	 * @param string   $mode
	 * @param callable $fn
	 * @return mixed
	 */
	private static function with_mode( $mode, $fn ) {
		$force_mode   = static function () use ( $mode ) {
			return $mode;
		};
		$force_bypass = static function () {
			return true;
		};

		add_filter( 'e3a_date_mode', $force_mode, 99 );
		add_filter( 'e3a_bypass_cache', $force_bypass, 99 );

		try {
			return $fn();
		} finally {
			remove_filter( 'e3a_date_mode', $force_mode, 99 );
			remove_filter( 'e3a_bypass_cache', $force_bypass, 99 );
		}
	}

	// -----------------------------------------------------------------------
	// WP-CLI (registro condicional: si no hay WP-CLI, no se ejecuta nada)
	// -----------------------------------------------------------------------

	public static function register_cli() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command(
			'e3a diag',
			static function ( $args, $assoc_args ) {
				$sub = isset( $args[0] ) ? (string) $args[0] : 'info';

				if ( ! in_array( $sub, array( 'info', 'compare' ), true ) ) {
					\WP_CLI::error( 'Uso: wp e3a diag <info|compare> [--period=7]' );
					return;
				}

				// Reusa exactamente las mismas clases que la pagina web.
				$_GET[ self::PARAM ] = $sub;
				if ( isset( $assoc_args['period'] ) ) {
					$_GET['period'] = (string) $assoc_args['period'];
				}

				ob_start();
				if ( 'info' === $sub ) {
					self::render_info();
				} else {
					self::render_compare();
				}
				$out = (string) ob_get_clean();

				\WP_CLI::line( html_entity_decode( wp_strip_all_tags( $out ), ENT_QUOTES, 'UTF-8' ) );
			}
		);
	}

	// -----------------------------------------------------------------------
	// Salida
	// -----------------------------------------------------------------------

	private static function flush_now() {
		if ( ob_get_level() > 0 ) {
			@ob_flush();
		}
		@flush();
	}

	private static function h( $title ) {
		echo "\n" . str_repeat( '=', 78 ) . "\n";
		echo esc_html( (string) $title ) . "\n";
		echo str_repeat( '=', 78 ) . "\n";
	}

	private static function kv( $key, $value ) {
		printf( "  %-38s %s\n", esc_html( (string) $key ), esc_html( (string) $value ) );
	}

	private static function num( $n ) {
		return number_format( (int) $n, 0, ',', '.' );
	}

	/**
	 * @param mixed $v
	 * @return string
	 */
	private static function scalar( $v ) {
		if ( null === $v ) {
			return '-';
		}
		if ( is_bool( $v ) ) {
			return $v ? 'true' : 'false';
		}
		if ( is_float( $v ) ) {
			return rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' );
		}
		if ( is_array( $v ) ) {
			return '(array:' . count( $v ) . ')';
		}

		return (string) $v;
	}

	/**
	 * @param mixed $base
	 * @param mixed $other
	 * @return array{0:string,1:string}
	 */
	private static function delta( $base, $other ) {
		if ( ! is_numeric( $base ) || ! is_numeric( $other ) ) {
			return array( '-', '-' );
		}

		$base  = (float) $base;
		$other = (float) $other;
		$diff  = $other - $base;

		$abs = ( 0.0 === $diff ) ? '0' : sprintf( '%+.2f', $diff );
		$abs = str_replace( '.00', '', $abs );

		if ( 0.0 === $base ) {
			$pct = ( 0.0 === $diff ) ? '0%' : 'n/d';
		} else {
			$pct = sprintf( '%+.1f%%', ( $diff / $base ) * 100 );
		}

		return array( $abs, $pct );
	}
}
