<?php
/**
 * Stubs mínimos de WordPress para ejercitar E3_Analytics\Support\DatePeriod
 * sin WordPress y sin base de datos.
 *
 * DatePeriod.php NO se modifica. La única dependencia "difícil" era la consulta
 * de MIN(post_date) para period=all, y esa se resuelve porque DatePeriod usa
 * `global $wpdb`: alcanza con poner un objeto stub en ese global.
 *
 * Configuración — definí estas constantes ANTES de requerir este archivo:
 *
 *   E3A_TEST_TZ        Timezone del sitio (lo que devuelve wp_timezone()).
 *                      Default 'America/Bogota'.
 *   E3A_TEST_NOW       Instante "ahora" en UTC, formato 'Y-m-d H:i:s'.
 *                      Reloj congelado: sin esto el dump no es reproducible y
 *                      no sirve como línea base para comparar.
 *   E3A_TEST_MIN_POST  Valor que devuelve MIN(post_date) para period=all.
 *                      Cadena vacía => la consulta devuelve null (tabla sin
 *                      inscripciones), que activa el fallback de -3650 días.
 *
 * Fidelidad respecto de WordPress real:
 *  - current_time() y date_i18n() replican la implementación de WP 5.3+ (ver
 *    wp-includes/functions.php). Es lo que hace que el harness valga: el juego
 *    entre el timestamp desplazado por offset y la reinterpretación en la TZ
 *    del sitio es exactamente donde vive el comportamiento sutil.
 *  - date_i18n() real además traduce nombres de mes/día vía $wp_locale. Acá no
 *    se traduce nada. Es irrelevante para DatePeriod, que solo usa
 *    'Y-m-d H:i:s' (sin tokens de idioma). Si algún día se dumpean formatos con
 *    'F' o 'l', esta diferencia sí importaría.
 *  - No se emula ningún filtro real: apply_filters() devuelve el valor intacto.
 */

/*
 * WordPress ejecuta date_default_timezone_set('UTC') en su bootstrap
 * (wp-settings.php). Replicarlo NO es opcional: DatePeriod.php:61/63/64 usa
 * strtotime("-N days", $ts), que se evalúa en la timezone por defecto de PHP.
 * Con una TZ por defecto distinta de UTC los resultados cambian en los saltos
 * de horario de verano y el harness dejaría de reflejar producción.
 */
date_default_timezone_set( 'UTC' );

if ( ! defined( 'E3A_TEST_TZ' ) ) {
	define( 'E3A_TEST_TZ', 'America/Bogota' );
}
if ( ! defined( 'E3A_TEST_NOW' ) ) {
	define( 'E3A_TEST_NOW', '2026-07-29 17:45:00' );
}
if ( ! defined( 'E3A_TEST_MIN_POST' ) ) {
	define( 'E3A_TEST_MIN_POST', '2019-03-14 08:22:31' );
}

// DatePeriod.php:4 hace `exit` si ABSPATH no está definida.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/**
 * Epoch UTC del reloj congelado.
 */
function e3a_test_now_ts() {
	static $ts = null;
	if ( null !== $ts ) {
		return $ts;
	}
	$dt = DateTime::createFromFormat(
		'Y-m-d H:i:s',
		E3A_TEST_NOW,
		new DateTimeZone( 'UTC' )
	);
	if ( false === $dt ) {
		fwrite( STDERR, "E3A_TEST_NOW invalido: '" . E3A_TEST_NOW . "' (se espera 'Y-m-d H:i:s' en UTC)\n" );
		exit( 2 );
	}
	$ts = $dt->getTimestamp();
	return $ts;
}

// ---------------------------------------------------------------------------
// Funciones de WordPress
// ---------------------------------------------------------------------------

/**
 * wp-includes/functions.php
 *
 * Respeta $GLOBALS['e3a_test_tz_override'] para que un mismo proceso pueda
 * recorrer varias timezones (el dump imprime America/Bogota y UTC en una
 * corrida). Sin override, usa la constante E3A_TEST_TZ.
 */
function wp_timezone() {
	$tz = isset( $GLOBALS['e3a_test_tz_override'] ) && '' !== $GLOBALS['e3a_test_tz_override']
		? (string) $GLOBALS['e3a_test_tz_override']
		: E3A_TEST_TZ;

	return new DateTimeZone( $tz );
}

/**
 * Solo las dos opciones que hacen falta.
 * gmt_offset se deriva de la TZ del sitio en el instante congelado, que es lo
 * que hace WP cuando timezone_string está seteada.
 */
function get_option( $name, $default = false ) {
	switch ( $name ) {
		case 'date_format':
			return 'j F, Y';

		case 'gmt_offset':
			$offset = wp_timezone()->getOffset( new DateTime( '@' . e3a_test_now_ts() ) );
			return $offset / HOUR_IN_SECONDS;

		default:
			return $default;
	}
}

/**
 * wp-includes/functions.php — current_time()
 * Devuelve un timestamp DESPLAZADO por el offset (no un epoch real) cuando
 * $type es 'timestamp' y $gmt es falso. DatePeriod depende de esto.
 */
function current_time( $type, $gmt = 0 ) {
	$now = e3a_test_now_ts();

	switch ( $type ) {
		case 'mysql':
			return $gmt
				? gmdate( 'Y-m-d H:i:s', $now )
				: gmdate( 'Y-m-d H:i:s', $now + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );

		case 'timestamp':
			return $gmt
				? $now
				: $now + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );

		default:
			return $gmt
				? gmdate( $type, $now )
				: gmdate( $type, $now + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
	}
}

/**
 * wp-includes/functions.php — wp_date() (sin traducción de locale)
 */
function wp_date( $format, $timestamp = null, $timezone = null ) {
	if ( null === $timestamp ) {
		$timestamp = e3a_test_now_ts();
	}
	if ( null === $timezone ) {
		$timezone = wp_timezone();
	}

	$datetime = date_create( '@' . $timestamp );
	if ( false === $datetime ) {
		return false;
	}

	return $datetime->setTimezone( $timezone )->format( $format );
}

/**
 * wp-includes/functions.php — date_i18n()
 *
 * La rama que importa es la última: cuando se pasa un timestamp explícito
 * (siempre, en DatePeriod), WP asume que viene desplazado por offset, lo
 * revierte a una hora de pared con gmdate() y la reinterpreta en la TZ del
 * sitio. Para el formato 'Y-m-d H:i:s' el viaje de ida y vuelta es neutro y el
 * resultado es hora local del sitio.
 */
function date_i18n( $format, $timestamp_with_offset = false, $gmt = false ) {
	$timestamp = $timestamp_with_offset;

	if ( ! is_numeric( $timestamp ) ) {
		$timestamp = current_time( 'timestamp', $gmt );
	}

	if ( 'U' === $format ) {
		return (string) $timestamp;
	}

	if ( false === $timestamp_with_offset ) {
		$tz = $gmt ? new DateTimeZone( 'UTC' ) : wp_timezone();
		return wp_date( $format, null, $tz );
	}

	$local_time = gmdate( 'Y-m-d H:i:s', (int) $timestamp );
	$timezone   = wp_timezone();
	$datetime   = date_create( $local_time, $timezone );

	if ( false === $datetime ) {
		return false;
	}

	return wp_date( $format, $datetime->getTimestamp(), $timezone );
}

/**
 * Pasa el valor sin tocarlo, como pediste.
 */
function apply_filters( $hook_name, $value ) {
	return $value;
}

/**
 * Aproximación suficiente de wp-includes/formatting.php.
 * Lo relevante para DatePeriod es que no altera '7', '30', 'all', 'basura' ni
 * '2026-03-01..2026-04-15', y que colapsa arrays a cadena vacía.
 */
function sanitize_text_field( $str ) {
	if ( is_array( $str ) || is_object( $str ) ) {
		return '';
	}

	$filtered = (string) $str;
	$filtered = strip_tags( $filtered );
	$filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
	$filtered = preg_replace( '/[\x00-\x1F\x7F]/', '', $filtered );

	return trim( $filtered );
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function __( $text, $domain = null ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

// ---------------------------------------------------------------------------
// $wpdb
// ---------------------------------------------------------------------------

/**
 * Stub del global $wpdb. Cubre exactamente lo que toca DatePeriod:
 * ->posts, ->prepare(), ->get_var().
 *
 * get_var() devuelve E3A_TEST_MIN_POST, que es el punto de inyección del
 * MIN(post_date) de period=all. Sin base de datos, sin subclasear DatePeriod
 * (es `final`) y sin cambiar su firma.
 */
class E3A_Test_WPDB {

	public $posts    = 'wp_posts';
	public $users    = 'wp_users';
	public $usermeta = 'wp_usermeta';
	public $prefix   = 'wp_';

	/** @var string[] Consultas vistas, para poder afirmar si se tocó la DB. */
	public $queries = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		// Sustitución posicional simple, suficiente para inspección.
		$out = '';
		$i   = 0;
		$len = strlen( $query );
		for ( $p = 0; $p < $len; $p++ ) {
			if ( '%' === $query[ $p ] && $p + 1 < $len && in_array( $query[ $p + 1 ], array( 's', 'd', 'f' ), true ) ) {
				$type  = $query[ $p + 1 ];
				$value = array_key_exists( $i, $args ) ? $args[ $i ] : null;
				$i++;
				$p++;

				if ( 'd' === $type ) {
					$out .= (string) (int) $value;
				} elseif ( 'f' === $type ) {
					$out .= (string) (float) $value;
				} else {
					$out .= "'" . str_replace( "'", "\\'", (string) $value ) . "'";
				}
				continue;
			}
			$out .= $query[ $p ];
		}

		return $out;
	}

	public function get_var( $query = null ) {
		$this->queries[] = (string) $query;
		return ( '' === E3A_TEST_MIN_POST ) ? null : E3A_TEST_MIN_POST;
	}

	public function get_results( $query = null, $output = null ) {
		$this->queries[] = (string) $query;
		return array();
	}

	public function get_col( $query = null ) {
		$this->queries[] = (string) $query;
		return array();
	}

	public function reset_queries() {
		$this->queries = array();
	}
}

$GLOBALS['wpdb'] = new E3A_Test_WPDB();
