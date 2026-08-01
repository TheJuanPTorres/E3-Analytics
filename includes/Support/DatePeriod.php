<?php
namespace E3_Analytics\Support;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Resolución de períodos.
 *
 * Traduce la clave 'period' del request a la ventana de fechas que consumen los
 * servicios. Días calendario completos, con los límites en hora local del sitio
 * y su equivalente en UTC.
 *
 * Claves aceptadas:
 *   '7' | '30' | '90' | '365'                     N días calendario incluyendo hoy
 *   'this_month' | 'last_month'                   unidad de mes
 *   'this_quarter' | 'this_year' | 'last_year'    trimestre y año
 *   'all'                                          desde MIN(post_date) hasta hoy
 *   'YYYY-MM-DD..YYYY-MM-DD'                       rango personalizado
 *
 * Cualquier otro valor cae a '30' e informa el motivo en la clave 'notice'.
 *
 * DOS ZONAS HORARIAS. current_start / current_end y sus prev_* están en hora
 * local del sitio y se comparan contra wp_posts.post_date. Las cuatro claves
 * _utc son los mismos instantes convertidos a UTC y se comparan contra
 * wp_users.user_registered, que WP core escribe en UTC. No son intercambiables:
 * con offset -5, el fin de un día local cae en el día UTC siguiente.
 *
 * No lee $_GET: Admin\Page::read_period() es el único lector del request.
 */
final class DatePeriod {

	/** Presets numéricos + histórico. */
	const NUMERIC_PRESETS = array( '7', '30', '90', '365' );

	/** Presets de unidad calendario. Calculados, pero NO expuestos en el selector (eso es B2). */
	const CALENDAR_PRESETS = array( 'this_month', 'last_month', 'this_quarter', 'this_year', 'last_year' );

	/**
	 * Tope del rango custom, en días. Filtrable con e3a_max_custom_range_days.
	 *
	 * 3650 días (~10 años). Medido en producción: la historia completa del sitio
	 * son 1.437 días, y period=all tarda 2,79 s con 7.064 queries contra un
	 * max_execution_time de 300 s. Con el tope anterior de 730 la clienta no
	 * podía seleccionar a mano su propia historia completa: el tope dejaba de
	 * proteger y pasaba a ser una regresión funcional. El filtro queda por si el
	 * plugin se reutiliza en un sitio más grande.
	 */
	const DEFAULT_MAX_CUSTOM_DAYS = 3650;

	/**
	 * Presets que se ofrecen en el selector de período de las 3 vistas.
	 *
	 * Fuente única: hasta ahora las mismas 5 opciones estaban escritas a mano,
	 * en HTML, en admin/views/dashboard.php, dropout-progress.php y
	 * country-analysis.php. Agregar un preset obligaba a editar tres archivos.
	 *
	 * @return array<string,string> key => etiqueta
	 */
	public static function presets() {
		return array(
			'7'            => __( 'Últimos 7 días', 'e3-analytics' ),
			'30'           => __( 'Últimos 30 días', 'e3-analytics' ),
			'90'           => __( 'Últimos 90 días', 'e3-analytics' ),
			'365'          => __( 'Últimos 365 días', 'e3-analytics' ),
			'this_month'   => __( 'Este mes', 'e3-analytics' ),
			'last_month'   => __( 'Mes pasado', 'e3-analytics' ),
			'this_quarter' => __( 'Este trimestre', 'e3-analytics' ),
			'this_year'    => __( 'Este año', 'e3-analytics' ),
			'last_year'    => __( 'Año pasado', 'e3-analytics' ),
			'all'          => __( 'Histórico completo', 'e3-analytics' ),
		);
	}

	/**
	 * ¿Esta clave es un rango personalizado 'YYYY-MM-DD..YYYY-MM-DD'?
	 *
	 * Lo usan las vistas para saber si preseleccionar "Rango personalizado" en el
	 * selector y repoblar los dos inputs de fecha.
	 *
	 * @param string $key
	 * @return bool
	 */
	public static function is_custom_key( $key ) {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}\.\.\d{4}-\d{2}-\d{2}$/', (string) $key );
	}

	/**
	 * Formato de fecha para las etiquetas legibles.
	 *
	 * NO se usa get_option('date_format') a propósito. El sitio lo tiene en
	 * 'm/d/Y' y con eso un rango se vería "03/01/2026 – 04/15/2026", que un
	 * lector de habla hispana interpreta como 3 de enero a 15 de abril. Esa
	 * cadena viaja al nombre del archivo de export y a la hoja Resumen, así que
	 * la ambigüedad no es solo de pantalla.
	 *
	 * 'j M Y' con locale es_ES da "1 mar 2026": inequívoco.
	 *
	 * @return string
	 */
	public static function label_date_format() {
		$format = (string) apply_filters( 'e3a_label_date_format', 'j M Y' );

		return '' !== $format ? $format : 'j M Y';
	}

	/**
	 * @param string|int|null $period_override 7|30|90|365|all|this_month|last_month|
	 *                                         this_quarter|this_year|last_year|
	 *                                         YYYY-MM-DD..YYYY-MM-DD
	 * @return array
	 */
	public static function resolve( $period_override = null ) {
		$raw = null;
		if ( null !== $period_override && $period_override !== '' ) {
			$raw = sanitize_text_field( (string) $period_override );
		}

		if ( null === $raw || '' === $raw ) {
			$raw = '30';
		}

		return self::resolve_calendar( (string) $raw );
	}

	// -----------------------------------------------------------------------
	// Resolución
	// -----------------------------------------------------------------------

	/**
	 * @param string $raw
	 * @return array
	 */
	private static function resolve_calendar( $raw ) {
		$tz    = wp_timezone();
		$today = self::today( $tz );

		// 1) Rango custom explícito.
		if ( false !== strpos( $raw, '..' ) ) {
			return self::resolve_custom( $raw, $tz, $today );
		}

		// 2) Presets de unidad calendario.
		// resolve_calendar_preset() devuelve la ventana cruda (con objetos
		// DateTimeImmutable): tiene que pasar por build() igual que las demás.
		if ( in_array( $raw, self::CALENDAR_PRESETS, true ) ) {
			return self::build( self::resolve_calendar_preset( $raw, $tz, $today ) );
		}

		// 3) Histórico.
		if ( 'all' === $raw ) {
			$min   = self::min_enrollment_date();
			$start = null;

			if ( $min ) {
				$start = self::date_from_mysql( (string) $min, $tz );
			}
			if ( null === $start ) {
				$start = $today->sub( new \DateInterval( 'P3650D' ) );
			}

			return self::build(
				array(
					'period_key' => 'all',
					'is_all'     => true,
					'period_int' => 0,
					'preset_key' => 'all',
					'is_custom'  => false,
					'start'      => $start->setTime( 0, 0, 0 ),
					'end'        => $today->setTime( 23, 59, 59 ),
					'prev_start' => null,
					'prev_end'   => null,
					'notice'     => '',
				)
			);
		}

		// 4) Presets numéricos: N días calendario incluyendo hoy.
		if ( in_array( $raw, self::NUMERIC_PRESETS, true ) ) {
			return self::build( self::numeric_window( (int) $raw, $raw, $today ) );
		}

		// 5) Fallback.
		$notice = sprintf(
			'El período "%s" no es válido. Se usaron los últimos 30 días.',
			$raw
		);

		$window           = self::numeric_window( 30, '30', $today );
		$window['notice'] = $notice;

		return self::build( $window );
	}

	/**
	 * Ventana de N días calendario terminando hoy, más los N días
	 * inmediatamente anteriores sin solape.
	 *
	 * @param int                $n
	 * @param string             $key
	 * @param \DateTimeImmutable $today
	 * @return array
	 */
	private static function numeric_window( $n, $key, \DateTimeImmutable $today ) {
		$n = max( 1, (int) $n );

		$current_start = $today->sub( new \DateInterval( 'P' . ( $n - 1 ) . 'D' ) )->setTime( 0, 0, 0 );
		$current_end   = $today->setTime( 23, 59, 59 );

		// Sin solape: prev_end es el día anterior a current_start, no el mismo instante.
		$prev_end   = $current_start->sub( new \DateInterval( 'P1D' ) )->setTime( 23, 59, 59 );
		$prev_start = $current_start->sub( new \DateInterval( 'P' . $n . 'D' ) )->setTime( 0, 0, 0 );

		return array(
			'period_key' => (string) $key,
			'is_all'     => false,
			'period_int' => $n,
			'preset_key' => (string) $key,
			'is_custom'  => false,
			'start'      => $current_start,
			'end'        => $current_end,
			'prev_start' => $prev_start,
			'prev_end'   => $prev_end,
			'notice'     => '',
		);
	}

	/**
	 * Presets de unidad calendario.
	 *
	 * last_month / last_year: la unidad anterior completa; su ventana previa es
	 * la unidad anterior a esa, también completa.
	 * this_month / this_quarter / this_year: period-to-date. La comparación usa
	 * el mismo número de días transcurridos desde el inicio de la unidad
	 * anterior (si hoy es 12 de julio, 1–12 jul compara contra 1–12 jun).
	 *
	 * @param string             $key
	 * @param \DateTimeZone      $tz
	 * @param \DateTimeImmutable $today
	 * @return array
	 */
	private static function resolve_calendar_preset( $key, \DateTimeZone $tz, \DateTimeImmutable $today ) {
		$year  = (int) $today->format( 'Y' );
		$month = (int) $today->format( 'n' );

		switch ( $key ) {
			case 'this_month':
				$unit_start      = self::ymd( $year, $month, 1, $tz );
				$prev_unit_start = $unit_start->sub( new \DateInterval( 'P1M' ) );
				return self::period_to_date( $key, $unit_start, $today, $prev_unit_start );

			case 'last_month':
				$unit_start = self::ymd( $year, $month, 1, $tz )->sub( new \DateInterval( 'P1M' ) );
				$unit_end   = $unit_start->add( new \DateInterval( 'P1M' ) )->sub( new \DateInterval( 'P1D' ) );
				$prev_start = $unit_start->sub( new \DateInterval( 'P1M' ) );
				$prev_end   = $unit_start->sub( new \DateInterval( 'P1D' ) );
				return self::full_unit( $key, $unit_start, $unit_end, $prev_start, $prev_end );

			case 'this_quarter':
				$q_month         = ( (int) floor( ( $month - 1 ) / 3 ) * 3 ) + 1;
				$unit_start      = self::ymd( $year, $q_month, 1, $tz );
				$prev_unit_start = $unit_start->sub( new \DateInterval( 'P3M' ) );
				return self::period_to_date( $key, $unit_start, $today, $prev_unit_start );

			case 'this_year':
				$unit_start      = self::ymd( $year, 1, 1, $tz );
				$prev_unit_start = self::ymd( $year - 1, 1, 1, $tz );
				return self::period_to_date( $key, $unit_start, $today, $prev_unit_start );

			case 'last_year':
			default:
				$unit_start = self::ymd( $year - 1, 1, 1, $tz );
				$unit_end   = self::ymd( $year - 1, 12, 31, $tz );
				$prev_start = self::ymd( $year - 2, 1, 1, $tz );
				$prev_end   = self::ymd( $year - 2, 12, 31, $tz );
				return self::full_unit( $key, $unit_start, $unit_end, $prev_start, $prev_end );
		}
	}

	/**
	 * Ventana period-to-date: desde el inicio de la unidad hasta hoy, comparada
	 * contra el mismo número de días desde el inicio de la unidad anterior.
	 *
	 * @return array
	 */
	private static function period_to_date( $key, \DateTimeImmutable $unit_start, \DateTimeImmutable $today, \DateTimeImmutable $prev_unit_start ) {
		$start = $unit_start->setTime( 0, 0, 0 );
		$end   = $today->setTime( 23, 59, 59 );

		$elapsed = self::days_between( $start, $end );

		$prev_start = $prev_unit_start->setTime( 0, 0, 0 );
		$prev_end   = $prev_start->add( new \DateInterval( 'P' . ( $elapsed - 1 ) . 'D' ) )->setTime( 23, 59, 59 );

		return array(
			'period_key' => (string) $key,
			'is_all'     => false,
			'period_int' => $elapsed,
			'preset_key' => (string) $key,
			'is_custom'  => false,
			'start'      => $start,
			'end'        => $end,
			'prev_start' => $prev_start,
			'prev_end'   => $prev_end,
			'notice'     => '',
		);
	}

	/**
	 * Unidad calendario completa (last_month / last_year).
	 *
	 * @return array
	 */
	private static function full_unit( $key, \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeImmutable $prev_start, \DateTimeImmutable $prev_end ) {
		$start = $start->setTime( 0, 0, 0 );
		$end   = $end->setTime( 23, 59, 59 );

		return array(
			'period_key' => (string) $key,
			'is_all'     => false,
			'period_int' => self::days_between( $start, $end ),
			'preset_key' => (string) $key,
			'is_custom'  => false,
			'start'      => $start,
			'end'        => $end,
			'prev_start' => $prev_start->setTime( 0, 0, 0 ),
			'prev_end'   => $prev_end->setTime( 23, 59, 59 ),
			'notice'     => '',
		);
	}

	// -----------------------------------------------------------------------
	// Rango custom
	// -----------------------------------------------------------------------

	/**
	 * Parsea y valida 'YYYY-MM-DD..YYYY-MM-DD'.
	 *
	 * Reglas: parseo estricto (nada de strtotime), from > to se intercambia en
	 * silencio, 'to' futuro se recorta a hoy, y si el rango excede el tope se
	 * recorta a los últimos N días DEL RANGO PEDIDO. Formato o fecha inválida
	 * cae a 30 días. Todo recorte o rechazo se informa por 'notice'; nunca se
	 * lanza excepción hacia el render, nunca hay fallback mudo.
	 *
	 * @return array
	 */
	private static function resolve_custom( $raw, \DateTimeZone $tz, \DateTimeImmutable $today ) {
		$parts = explode( '..', $raw );

		if ( 2 !== count( $parts ) ) {
			return self::custom_rejected( $raw, $today, 'el formato debe ser AAAA-MM-DD..AAAA-MM-DD' );
		}

		$from = self::parse_ymd( trim( $parts[0] ), $tz );
		$to   = self::parse_ymd( trim( $parts[1] ), $tz );

		if ( null === $from || null === $to ) {
			$bad = ( null === $from ) ? trim( $parts[0] ) : trim( $parts[1] );
			return self::custom_rejected( $raw, $today, sprintf( 'la fecha "%s" no existe o no tiene el formato AAAA-MM-DD', $bad ) );
		}

		$notices = array();

		// from > to: se intercambian en silencio (sin notice, por especificación).
		if ( $from > $to ) {
			$swap = $from;
			$from = $to;
			$to   = $swap;
		}

		// 'to' futuro: recorte a hoy.
		if ( $to > $today ) {
			$to        = $today;
			$notices[] = 'El fin del rango es futuro: se recortó a hoy.';
		}

		// Si tras el recorte el inicio quedó después del fin, el rango entero es futuro.
		if ( $from > $to ) {
			$from = $to;
		}

		$span = self::days_between( $from->setTime( 0, 0, 0 ), $to->setTime( 23, 59, 59 ) );
		$max  = (int) apply_filters( 'e3a_max_custom_range_days', self::DEFAULT_MAX_CUSTOM_DAYS );
		$max  = max( 1, $max );

		if ( $span > $max ) {
			$from      = $to->sub( new \DateInterval( 'P' . ( $max - 1 ) . 'D' ) );
			$notices[] = sprintf(
				'El rango pedido era de %d días y el tope es %d: se recortó a los últimos %d días del rango.',
				$span,
				$max,
				$max
			);
			$span = $max;
		}

		$start = $from->setTime( 0, 0, 0 );
		$end   = $to->setTime( 23, 59, 59 );

		$prev_end   = $start->sub( new \DateInterval( 'P1D' ) )->setTime( 23, 59, 59 );
		$prev_start = $prev_end->sub( new \DateInterval( 'P' . ( $span - 1 ) . 'D' ) )->setTime( 0, 0, 0 );

		// period_key refleja el rango EFECTIVO, no el pedido: así las URLs que lo
		// reinyecten no vuelven a disparar el recorte ni mienten sobre la ventana.
		$effective_key = $start->format( 'Y-m-d' ) . '..' . $end->format( 'Y-m-d' );

		return self::build(
			array(
				'period_key' => $effective_key,
				'is_all'     => false,
				'period_int' => $span,
				'preset_key' => '',
				'is_custom'  => true,
				'start'      => $start,
				'end'        => $end,
				'prev_start' => $prev_start,
				'prev_end'   => $prev_end,
				'notice'     => implode( ' ', $notices ),
			)
		);
	}

	/**
	 * Rango custom rechazado: cae a 30 días calendario y explica por qué.
	 *
	 * @return array
	 */
	private static function custom_rejected( $raw, \DateTimeImmutable $today, $reason ) {
		$window           = self::numeric_window( 30, '30', $today );
		$window['notice'] = sprintf(
			'El rango "%s" no es válido: %s. Se usaron los últimos 30 días.',
			$raw,
			$reason
		);

		return self::build( $window );
	}

	/**
	 * Parseo estricto de AAAA-MM-DD. Devuelve null si no es una fecha real.
	 *
	 * El '!' del formato resetea todos los campos no especificados, así que la
	 * hora queda en 00:00:00 y no se filtra la hora actual.
	 *
	 * @return \DateTimeImmutable|null
	 */
	private static function parse_ymd( $value, \DateTimeZone $tz ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}

		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $tz );

		if ( false === $dt ) {
			return null;
		}

		/*
		 * getLastErrors() cambió de contrato: hasta PHP 8.1 devuelve siempre un
		 * array (con los contadores en 0 si no hubo problemas); desde 8.2
		 * devuelve false cuando no hubo ninguno. Producción es 8.1, así que hay
		 * que tolerar las dos formas.
		 */
		$errors = \DateTimeImmutable::getLastErrors();
		if ( is_array( $errors ) ) {
			if ( ! empty( $errors['error_count'] ) || ! empty( $errors['warning_count'] ) ) {
				return null;
			}
		}

		// Cinturón y tiradores: 2026-02-30 no sobrevive a checkdate().
		list( $y, $m, $d ) = array_map( 'intval', explode( '-', $value ) );
		if ( ! checkdate( $m, $d, $y ) ) {
			return null;
		}

		return $dt;
	}

	// -----------------------------------------------------------------------
	// Ensamblado
	// -----------------------------------------------------------------------

	/**
	 * Construye el payload desde objetos DateTimeImmutable (ramas calendario).
	 *
	 * @param array $w
	 * @return array
	 */
	private static function build( array $w ) {
		$start = $w['start'];
		$end   = $w['end'];
		$ps    = $w['prev_start'];
		$pe    = $w['prev_end'];

		$fields = array(
			'period_key'        => (string) $w['period_key'],
			'is_all'            => (bool) $w['is_all'],
			'period_int'        => (int) $w['period_int'],
			'current_start'     => self::fmt_local( $start ),
			'current_end'       => self::fmt_local( $end ),
			'prev_start'        => self::fmt_local( $ps ),
			'prev_end'          => self::fmt_local( $pe ),
			// Los mismos instantes en UTC. Los consumen las 6 queries contra
			// wp_users.user_registered. NO son copia de las locales: con offset -5
			// el fin de un día local cae en el día UTC siguiente.
			'current_start_utc' => self::fmt_utc( $start ),
			'current_end_utc'   => self::fmt_utc( $end ),
			'prev_start_utc'    => self::fmt_utc( $ps ),
			'prev_end_utc'      => self::fmt_utc( $pe ),
			// days = duración real siempre. period_int conserva el 0 de 'all'
			// por compatibilidad con los consumidores existentes.
			'days'              => self::days_between( $start, $end ),
			'is_custom'         => (bool) $w['is_custom'],
			'preset_key'        => (string) $w['preset_key'],
			'notice'            => (string) $w['notice'],
			'label'             => self::range_label( $start, $end ),
			'prev_label'        => self::range_label( $ps, $pe ),
		);

		return $fields;
	}

	// -----------------------------------------------------------------------
	// Utilidades
	// -----------------------------------------------------------------------

	/**
	 * "Hoy" en la zona del sitio.
	 *
	 * El epoch se toma con current_time('timestamp', true), que es el epoch
	 * real (no el desplazado por offset). Se usa solo para OBTENER el instante:
	 * los literales SQL de las ramas calendario los produce DateTimeImmutable.
	 * Hacerlo así mantiene el harness determinista con su reloj congelado.
	 *
	 * @return \DateTimeImmutable
	 */
	private static function today( \DateTimeZone $tz ) {
		$epoch = (int) current_time( 'timestamp', true );

		$dt = new \DateTimeImmutable( '@' . $epoch );

		return $dt->setTimezone( $tz );
	}

	/**
	 * @return \DateTimeImmutable
	 */
	private static function ymd( $year, $month, $day, \DateTimeZone $tz ) {
		$value = sprintf( '%04d-%02d-%02d', (int) $year, (int) $month, (int) $day );

		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $tz );

		if ( false === $dt ) {
			// Inalcanzable con entradas generadas internamente; red de seguridad.
			return new \DateTimeImmutable( 'now', $tz );
		}

		return $dt;
	}

	/**
	 * Convierte un literal 'Y-m-d H:i:s' de la base (hora local del sitio) a
	 * DateTimeImmutable. Devuelve null si no parsea.
	 *
	 * @return \DateTimeImmutable|null
	 */
	private static function date_from_mysql( $value, \DateTimeZone $tz ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $value, 0, 10 ), $tz );

		return ( false === $dt ) ? null : $dt;
	}

	/**
	 * MIN(post_date) de las inscripciones. Misma query que siempre.
	 *
	 * @return string|null
	 */
	private static function min_enrollment_date() {
		global $wpdb;

		$post_type = apply_filters( 'e3a_enrollment_post_type', 'tutor_enrolled' );

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(post_date)
				 FROM {$wpdb->posts}
				 WHERE post_type = %s
				   AND post_status IN ('publish','completed')
				   AND post_parent > 0",
				$post_type
			)
		);
	}

	/**
	 * @param \DateTimeImmutable|null $dt
	 * @return string
	 */
	private static function fmt_local( $dt ) {
		return ( $dt instanceof \DateTimeImmutable ) ? $dt->format( 'Y-m-d H:i:s' ) : '';
	}

	/**
	 * @param \DateTimeImmutable|null $dt
	 * @return string
	 */
	private static function fmt_utc( $dt ) {
		if ( ! $dt instanceof \DateTimeImmutable ) {
			return '';
		}

		return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Días calendario que abarca la ventana, ambos extremos incluidos.
	 *
	 * @return int
	 */
	private static function days_between( \DateTimeImmutable $start, \DateTimeImmutable $end ) {
		$a = $start->setTime( 0, 0, 0 );
		$b = $end->setTime( 0, 0, 0 );

		$diff = $a->diff( $b );

		return ( (int) $diff->days ) + 1;
	}

	/**
	 * Etiqueta legible del rango, con el formato explícito de label_date_format().
	 *
	 * date_i18n() espera un "timestamp con offset", que es la convención
	 * histórica de WP: epoch real más el offset de la zona.
	 *
	 * @param \DateTimeImmutable|null $start
	 * @param \DateTimeImmutable|null $end
	 * @return string
	 */
	private static function range_label( $start, $end ) {
		if ( ! $start instanceof \DateTimeImmutable || ! $end instanceof \DateTimeImmutable ) {
			return '';
		}

		$format = self::label_date_format();

		$a = date_i18n( $format, $start->getTimestamp() + $start->getOffset() );
		$b = date_i18n( $format, $end->getTimestamp() + $end->getOffset() );

		return ( $a === $b ) ? (string) $a : $a . ' – ' . $b;
	}

}
