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
 *   ?page=e3-analytics-dashboard&e3a_diag=compare&period=7&mode=legacy
 *       Un solo modo. Es la ÚNICA forma de medir costo (ver abajo).
 *
 * Guarda: manage_options Y (opción e3a_diag_enabled O constante E3A_DIAG).
 * Sin las dos, el parámetro se ignora por completo.
 *
 * ---------------------------------------------------------------------------
 * HALLAZGO: los tiempos y conteos de queries de una corrida multi-modo mienten
 * ---------------------------------------------------------------------------
 * La primera corrida de period=7 con los 3 modos en un mismo request dio:
 *
 *     legacy         0.68 s   169 queries
 *     calendar       0.03 s    16 queries
 *     calendar_utc   0.03 s    16 queries
 *
 * Imposible como diferencia real: el modo solo cambia los límites de fecha, y la
 * diferencia de inscripciones entre modos (69 vs 62) explica un 10%, no un 1.000%.
 *
 * Causa: el object cache NO persistente de WordPress, el que vive en memoria
 * durante el request y respalda get_post_meta() / get_userdata() / get_the_title().
 * El primer modo que corre paga todas las lecturas que hace Tutor LMS dentro de
 * course_progress_percent() y de is_completed_course(); los modos siguientes las
 * encuentran calientes. $wpdb->num_queries solo cuenta SQL que efectivamente sale
 * a MySQL, así que el ahorro no se ve como queries de menos: se ve como si los
 * modos 2 y 3 fueran 20 veces más rápidos.
 *
 * O sea: calendar no es más rápido, corrió segundo.
 *
 * Las ~16 queries del piso son las consultas SQL directas que MetricsService hace
 * por modo pase lo que pase: rows_between x2, count_registered_between x2,
 * first_time_enrollments_count x2, first_enrollment_map_until,
 * cross_course_users_count, las dos de retención y dau_mau. Las ~153 restantes
 * del primer modo son lecturas cacheables que los modos 2 y 3 ya no pagan.
 *
 * Consecuencia práctica: para medir costo, UNA carga por modo con &mode=.
 * La tabla comparativa de KPIs sigue siendo válida en multi-modo: el object cache
 * afecta el COSTO, no los VALORES.
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

		/*
		 * Huérfanos de la clave-por-segundo: hasta B1, current_end llevaba
		 * segundos, así que cada carga de la página de país escribía una fila
		 * nueva en wp_options y el caché no acertaba nunca. Sin object cache
		 * externo, están todos acá. COUNT con LIKE sobre option_name, que el
		 * índice único de la columna cubre.
		 */
		$tr_value = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%e3a_country%'"
		);
		$tr_timeout = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%e3a_country%'"
		);
		self::kv( '   filas _transient_%e3a_country%', self::num( $tr_value ) );
		self::kv( '   filas _transient_timeout_%e3a_country%', self::num( $tr_timeout ) );
		if ( $tr_value > 50 ) {
			echo "   (acumulacion de la clave-por-segundo anterior a B1. WordPress las purga\n";
			echo "    solo cuando alguien las pide y ya vencieron, asi que pueden quedarse.)\n";
		}
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

		$all_modes = array(
			DatePeriod::MODE_LEGACY,
			DatePeriod::MODE_CALENDAR,
			DatePeriod::MODE_CALENDAR_UTC,
		);

		// &mode=<uno de los tres>: calcula UN SOLO modo. Es la unica medicion de
		// costo confiable, porque el request arranca con el object cache frio.
		$single = isset( $_GET['mode'] ) ? sanitize_text_field( wp_unslash( $_GET['mode'] ) ) : '';

		if ( '' !== $single && ! in_array( $single, $all_modes, true ) ) {
			self::h( 'ERROR: PARAMETRO mode NO RECONOCIDO' );
			printf( "  Recibido : %s\n", esc_html( $single ) );
			printf( "  Validos  : %s\n", implode( ', ', $all_modes ) );
			echo "\n  No se aplica ningun fallback: corregi el parametro y volve a cargar.\n";
			return;
		}

		$modes = ( '' !== $single ) ? array( $single ) : $all_modes;

		/*
		 * TEMPORAL — verificacion de la hipotesis del object cache (ver docblock
		 * de la clase). &order=reverse invierte el orden de los tres modos. Si el
		 * patron de costo sigue al ORDEN y no al MODO, la hipotesis queda
		 * confirmada. BORRAR este bloque una vez verificado.
		 */
		$order = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : '';
		if ( 'reverse' === $order && count( $modes ) > 1 ) {
			$modes = array_reverse( $modes );
		}

		// --- Advertencia de medicion, primero de todo. -----------------------
		self::h( 'COMO LEER LOS TIEMPOS Y LOS CONTEOS DE QUERIES' );

		if ( count( $modes ) > 1 ) {
			echo "  ATENCION: se estan corriendo " . count( $modes ) . " modos en UN MISMO request.\n";
			echo "  Los tiempos y conteos de queries del segundo y del tercer modo NO son\n";
			echo "  comparables con los del primero, y NO reflejan su costo real.\n\n";
			echo "  Motivo: el object cache no persistente de WordPress (wp_cache_*, que\n";
			echo "  respaldan get_post_meta / get_userdata / get_the_title) vive en memoria\n";
			echo "  durante todo el request. El PRIMER modo que corre paga todas las lecturas\n";
			echo "  de Tutor LMS; los siguientes las encuentran calientes. \$wpdb->num_queries\n";
			echo "  solo cuenta SQL real, asi que el ahorro no aparece como queries de menos:\n";
			echo "  aparece como si los modos 2 y 3 fueran muchisimo mas rapidos. No lo son.\n\n";
			echo "  Para medir costo hay que usar &mode= en cargas SEPARADAS:\n";
			echo "    &e3a_diag=compare&period=" . esc_html( $period ) . "&mode=legacy\n";
			echo "    &e3a_diag=compare&period=" . esc_html( $period ) . "&mode=calendar\n";
			echo "    &e3a_diag=compare&period=" . esc_html( $period ) . "&mode=calendar_utc\n\n";
			echo "  La tabla comparativa de KPIs de mas abajo SI es valida: el object cache\n";
			echo "  afecta el COSTO, no los VALORES.\n";
		} else {
			echo "  Modo unico (&mode=" . esc_html( $modes[0] ) . "): el request arranca con el\n";
			echo "  object cache frio, asi que el tiempo y el conteo de queries de aca abajo\n";
			echo "  SI son la medicion real del costo de este modo.\n\n";
			echo "  No hay tabla comparativa: para comparar KPIs entre modos, carga sin &mode=.\n";
		}

		if ( 'reverse' === $order ) {
			echo "\n  [TEMPORAL] &order=reverse activo: los modos corren en orden invertido.\n";
		}

		// --- Cabecera: las fechas de cada modo ANTES de calcular nada. -------
		// Si el gateway corta la request por timeout, esto solo ya es util.
		self::h( 'PERIODO PEDIDO' );
		self::kv( 'period', $period );
		self::kv( 'modo persistido', DatePeriod::resolve_mode() );
		self::kv( 'modos a calcular', implode( ' -> ', $modes ) );
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

			// Medicion hipotetica. Va DESPUES de capturar $elapsed y $queries
			// para no contaminar la medicion de costo del modo.
			self::render_hypothetical( $mode, $data, $resolved[ $mode ] );

			self::flush_now();
		}

		// --- Tabla comparativa. Solo tiene sentido con mas de un modo. -------
		if ( count( $modes ) < 2 ) {
			self::h( 'RESUMEN DE COSTO (medicion confiable, cache frio)' );
			printf(
				"  %-14s %6.2f s   %8s queries\n",
				$modes[0],
				$timings[ $modes[0] ]['seconds'],
				self::num( $timings[ $modes[0] ]['queries'] )
			);
			echo "\n  Sin tabla comparativa: hay un solo modo. Carga sin &mode= para comparar KPIs.\n";
			return;
		}

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
		self::h( 'RESUMEN DE COSTO — NO COMPARABLE ENTRE SI' );

		$first = true;
		foreach ( $modes as $mode ) {
			printf(
				"  %-14s %6.2f s   %8s queries   %s\n",
				$mode,
				$timings[ $mode ]['seconds'],
				self::num( $timings[ $mode ]['queries'] ),
				$first ? '<- unico con cache frio' : '<- cache ya caliente, NO usar'
			);
			$first = false;
		}

		echo "\n  Solo la primera fila mide costo real. Las otras heredan el object cache\n";
		echo "  que lleno la primera y por eso salen artificialmente baratas.\n";
		echo "  Para el costo de cada modo: tres cargas separadas con &mode=.\n";
		echo "\n  (El filtro e3a_bypass_cache saltea los transients del reporte de pais,\n";
		echo "  que es otra cosa: eso no afecta al object cache en memoria.)\n";
	}

	// -----------------------------------------------------------------------
	// Bloque HIPOTETICO — medicion, no aplicada al dashboard
	// -----------------------------------------------------------------------

	/**
	 * Calcula activity_rate por la via correcta y lo compara con el que devuelve
	 * el dashboard.
	 *
	 * ESTADO: el fix ya se aplico en la version 1.2.9.3-b2. A partir de ahi
	 * MetricsService usa el mismo criterio, asi que el delta de este bloque
	 * DEBE dar 0.0 pts. Eso es precisamente lo que lo vuelve util ahora: es la
	 * verificacion post-deploy de que el fix hace lo que la medicion predecia.
	 * Un delta distinto de cero es un defecto.
	 *
	 * Historia: hasta 1.2.9.2-b1 el numerador era students_with_enrollments, que
	 * cuenta a CUALQUIER usuario con una inscripcion en la ventana, incluidos los
	 * registrados años atras, mientras el denominador solo contaba los
	 * registrados en la ventana. Dos poblaciones que ni se contenian, y el
	 * cociente pasaba de 100 de forma rutinaria (171,0% en period=30).
	 *
	 * La query de registered_and_enrolled() se mantiene DUPLICADA a proposito:
	 * es una implementacion independiente de la de
	 * UsersRepository::count_registered_and_enrolled_between(). Si este bloque
	 * llamara al repositorio, el delta daria cero por construccion y no
	 * verificaria nada. Se borra en B4 junto con toda la herramienta.
	 *
	 * @param string $mode
	 * @param array  $data  Retorno de MetricsService::get_dashboard_data().
	 * @param array  $dates Retorno de DatePeriod::resolve() para este modo.
	 */
	private static function render_hypothetical( $mode, array $data, array $dates ) {
		$kpis = (array) ( $data['kpis'] ?? array() );
		$ret  = (array) ( $data['retention'] ?? array() );

		$new_users       = (int) ( $kpis['current_new_users'] ?? 0 );
		$activity_actual = (float) ( $kpis['activity_rate'] ?? 0 );
		$completion      = (float) ( $kpis['completion_rate'] ?? 0 );
		$ret_30          = (float) ( $ret['30']['rate'] ?? 0 );

		$overlap = self::registered_and_enrolled( $dates );

		$activity_fixed = ( $new_users > 0 )
			? round( ( $overlap / $new_users ) * 100, 1 )
			: 0.0;

		self::h( 'HIPOTETICO — ' . strtoupper( $mode ) . ' (medicion, NO aplicado)' );

		echo "  activity_rate  (verificacion del fix de 1.2.9.3-b2)\n";
		printf( "    %-38s %s\n", 'active_users (KPI aparte, NO es esto)', self::num( (int) ( $kpis['active_users'] ?? 0 ) ) );
		printf( "    %-38s %s\n", 'numerador correcto (ambas cosas)', self::num( $overlap ) );
		printf( "    %-38s %s\n", 'denominador (current_new_users)', self::num( $new_users ) );
		printf( "    %-38s %s\n", 'activity_rate del dashboard', self::scalar( $activity_actual ) . '%' );
		printf( "    %-38s %s\n", 'activity_rate recalculado aca', self::scalar( $activity_fixed ) . '%' );

		$delta = $activity_fixed - $activity_actual;
		printf( "    %-38s %s\n", 'delta', sprintf( '%+.1f pts', $delta ) );

		if ( abs( $delta ) < 0.05 ) {
			echo "    OK: coinciden. El fix hace lo que la medicion predecia.\n";
		} else {
			echo "    [!] NO COINCIDEN. Con el fix aplicado el delta tiene que ser 0.0 pts:\n";
			echo "        esto es un defecto, no un efecto esperado.\n";
		}

		// --- Impacto en el indice de salud. ----------------------------------
		$completion = (float) ( $kpis['completion_rate'] ?? 0 );

		$a = self::health_score( $activity_actual, $completion, $ret_30 );
		$b = self::health_score( $activity_fixed, $completion, $ret_30 );

		echo "\n  indice de salud  (activity*0.30 + completion*0.40 + retencion30*0.30)\n";
		printf( "    %-38s %s\n", 'completion_rate usado (constante)', self::scalar( $completion ) . '%' );
		printf( "    %-38s %s\n", 'retencion 30d usada (constante)', self::scalar( $ret_30 ) . '%' );
		printf( "    %-38s %-8s %-8s %s\n", '', 'pre', 'post', 'delta' );
		printf( "    %-38s %-8d %-8d %s\n", 'con el activity del dashboard', $a['pre'], $a['post'], '-' );
		printf( "    %-38s %-8d %-8d %+d\n", 'con el activity recalculado', $b['pre'], $b['post'], $b['post'] - $a['post'] );

		printf(
			"\n    Umbrales (admin/views/dashboard.php:106-108): >= 70 Bueno, >= 40 Regular, resto Critico.\n"
		);
		printf( "    Con %d el indice cae en: %s\n", $a['post'], $a['post'] >= 70 ? 'Bueno' : ( $a['post'] >= 40 ? 'Regular' : 'Critico' ) );

		if ( $a['pre'] > 100 ) {
			printf( "\n    [!] El clamp esta tapando %d puntos: sin el daria %d.\n", $a['pre'] - 100, $a['pre'] );
		}
		if ( $activity_actual > 100 ) {
			echo "    [!] activity_rate supera 100%. Con el fix de 1.2.9.3-b2 aplicado esto\n";
			echo "        no deberia pasar: el numerador es subconjunto del denominador.\n";
		}

		echo "\n";
	}

	/**
	 * Indice de salud segun admin/views/dashboard.php:98-104, antes y despues
	 * del clamp a 0-100.
	 *
	 * @return array{pre:int,post:int}
	 */
	private static function health_score( $activity, $completion, $ret_30 ) {
		$raw = ( (float) $activity * 0.30 ) + ( (float) $completion * 0.40 ) + ( (float) $ret_30 * 0.30 );
		$pre = (int) round( $raw );

		return array(
			'pre'  => $pre,
			'post' => max( 0, min( 100, $pre ) ),
		);
	}

	/**
	 * Usuarios que se registraron Y se inscribieron dentro de la misma ventana.
	 *
	 * Una sola query, con el patron mixto de zonas horarias que ya usa
	 * MetricsService.php:275-293:
	 *   - wp_users.user_registered  -> limites UTC del modo
	 *   - wp_posts.post_date        -> limites locales del modo
	 *
	 * @param array $dates
	 * @return int
	 */
	private static function registered_and_enrolled( array $dates ) {
		global $wpdb;

		$start_utc = (string) ( $dates['current_start_utc'] ?? '' );
		$end_utc   = (string) ( $dates['current_end_utc'] ?? '' );
		$start     = (string) ( $dates['current_start'] ?? '' );
		$end       = (string) ( $dates['current_end'] ?? '' );

		if ( '' === $start_utc || '' === $end_utc || '' === $start || '' === $end ) {
			return 0;
		}

		$post_type = apply_filters( 'e3a_enrollment_post_type', 'tutor_enrolled' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT u.ID)
				 FROM {$wpdb->users} u
				 INNER JOIN {$wpdb->posts} p
				   ON p.post_author = u.ID
				 WHERE u.user_registered BETWEEN %s AND %s
				   AND p.post_type   = %s
				   AND p.post_status IN ('publish','completed')
				   AND p.post_parent > 0
				   AND p.post_date BETWEEN %s AND %s",
				$start_utc,
				$end_utc,
				$post_type,
				$start,
				$end
			)
		);
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
