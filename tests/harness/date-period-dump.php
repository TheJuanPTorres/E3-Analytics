<?php
/**
 * Dump del contrato de E3_Analytics\Support\DatePeriod::resolve().
 *
 * Imprime las 7 claves que devuelve resolve() para cada valor de period,
 * bajo un reloj congelado y en dos timezones. Sin WordPress, sin base de datos.
 *
 * Uso:
 *   php tests/harness/date-period-dump.php                  # presets + custom, ambas TZ
 *   php tests/harness/date-period-dump.php --only=presets   # solo 7/30/90/365/all
 *   php tests/harness/date-period-dump.php --only=custom    # solo los casos raros
 *   php tests/harness/date-period-dump.php --tz=UTC
 *   php tests/harness/date-period-dump.php --now='2026-07-29 17:45:00'
 *   php tests/harness/date-period-dump.php --min='2019-03-14 08:22:31'
 *   php tests/harness/date-period-dump.php --min=''          # tabla sin inscripciones
 *
 * El reloj está congelado a propósito: la salida tiene que ser byte-idéntica
 * entre corridas para poder diffear el antes/después del cambio a días
 * calendario.
 */

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

$opts = array(
	'only' => 'all',
	'tz'   => null,
	'now'  => null,
	'min'  => null,
);

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( ! preg_match( '/^--([a-z]+)=(.*)$/s', $arg, $m ) ) {
		fwrite( STDERR, "Argumento no reconocido: {$arg}\n" );
		exit( 2 );
	}
	if ( ! array_key_exists( $m[1], $opts ) ) {
		fwrite( STDERR, "Opcion no reconocida: --{$m[1]}\n" );
		exit( 2 );
	}
	$opts[ $m[1] ] = $m[2];
}

if ( ! in_array( $opts['only'], array( 'all', 'presets', 'custom' ), true ) ) {
	fwrite( STDERR, "--only debe ser: all | presets | custom\n" );
	exit( 2 );
}

// Las constantes tienen que definirse antes de cargar los stubs.
if ( null !== $opts['now'] ) {
	define( 'E3A_TEST_NOW', $opts['now'] );
}
if ( null !== $opts['min'] ) {
	define( 'E3A_TEST_MIN_POST', $opts['min'] );
}

// La TZ se recorre en el bucle principal, así que no se fija por constante:
// se controla con una variable global que lee el stub de wp_timezone().
$timezones = ( null !== $opts['tz'] )
	? array( $opts['tz'] )
	: array( 'America/Bogota', 'UTC' );

// ---------------------------------------------------------------------------
// Casos
// ---------------------------------------------------------------------------

/**
 * Cada caso: [ etiqueta legible, valor crudo que recibe resolve() ].
 * El valor se pasa como $period_override Y como $_GET['period'], que es lo que
 * hace la aplicacion real (Page.php lee $_GET y lo pasa como argumento).
 */
$presets = array(
	array( '7', '7' ),
	array( '30', '30' ),
	array( '90', '90' ),
	array( '365', '365' ),
	array( 'all', 'all' ),
);

$customs = array(
	array( '2026-03-01..2026-04-15  (rango normal)',   '2026-03-01..2026-04-15' ),
	array( '2026-07-29..2026-07-29  (un solo dia)',    '2026-07-29..2026-07-29' ),
	array( '2026-04-15..2026-03-01  (invertido)',      '2026-04-15..2026-03-01' ),
	array( '2026-02-30..2026-03-01  (fecha inexist.)', '2026-02-30..2026-03-01' ),
	array( '2019-01-01..2026-07-29  (excede tope)',    '2019-01-01..2026-07-29' ),
	array( '2026-03-01..2099-01-01  (to futuro)',      '2026-03-01..2099-01-01' ),
	array( 'custom  (debe caer al default)',           'custom' ),
	array( '0  (regresion pre-tarea)',                 0 ),
	array( 'basura',                                   'basura' ),
	array( "''  (cadena vacia)",                       '' ),
	array( '30.5',                                     30.5 ),
);

switch ( $opts['only'] ) {
	case 'presets':
		$cases = $presets;
		break;
	case 'custom':
		$cases = $customs;
		break;
	default:
		$cases = array_merge( $presets, $customs );
		break;
}

// ---------------------------------------------------------------------------
// Carga
// ---------------------------------------------------------------------------

$plugin_root = dirname( __DIR__, 2 );

require_once __DIR__ . '/wp-stubs.php';
require_once $plugin_root . '/includes/Support/DatePeriod.php';

use E3_Analytics\Support\DatePeriod;

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

/** Columnas: [ titulo, ancho ] */
$columns = array(
	array( 'input',         38 ),
	array( 'period_key',    10 ),
	array( 'is_all',         6 ),
	array( 'period_int',    10 ),
	array( 'current_start', 19 ),
	array( 'current_end',   19 ),
	array( 'prev_start',    19 ),
	array( 'prev_end',      19 ),
	array( 'span',           9 ),
	array( 'db',             3 ),
);

function e3a_pad( $value, $width ) {
	$value = (string) $value;
	if ( strlen( $value ) > $width ) {
		return substr( $value, 0, $width - 1 ) . '~';
	}
	return str_pad( $value, $width );
}

function e3a_row( array $cells, array $columns ) {
	$out = array();
	foreach ( $columns as $i => $col ) {
		$out[] = e3a_pad( $cells[ $i ] ?? '', $col[1] );
	}
	return '| ' . implode( ' | ', $out ) . ' |';
}

function e3a_rule( array $columns ) {
	$out = array();
	foreach ( $columns as $col ) {
		$out[] = str_repeat( '-', $col[1] );
	}
	return '+-' . implode( '-+-', $out ) . '-+';
}

/**
 * Dias entre current_start y current_end, con 2 decimales.
 * Es informativo: sirve para ver de un vistazo el largo real de la ventana.
 */
function e3a_span_days( $start, $end ) {
	if ( '' === (string) $start || '' === (string) $end ) {
		return '-';
	}
	$a = strtotime( $start );
	$b = strtotime( $end );
	if ( false === $a || false === $b ) {
		return '?';
	}
	return number_format( ( $b - $a ) / 86400, 2, '.', '' );
}

echo "DatePeriod::resolve() — dump de contrato\n";
echo str_repeat( '=', 152 ) . "\n";
echo 'PHP                 : ' . PHP_VERSION . "\n";
echo 'default_timezone    : ' . date_default_timezone_get() . "  (WordPress fija UTC en su bootstrap)\n";
echo 'reloj congelado UTC : ' . E3A_TEST_NOW . "\n";
echo 'MIN(post_date)      : ' . ( '' === E3A_TEST_MIN_POST ? '(null — tabla sin inscripciones)' : E3A_TEST_MIN_POST ) . "\n";
echo 'casos               : ' . $opts['only'] . ' (' . count( $cases ) . ")\n";
echo "\n";
echo "span = dias entre current_start y current_end.  db = consultas a \$wpdb durante resolve().\n";
echo "Todo valor fuera de {7,30,90,365,all} cae a '30' en DatePeriod.php:23, en silencio.\n";
echo "\n";

foreach ( $timezones as $tz ) {

	$GLOBALS['e3a_test_tz_override'] = $tz;

	$offset_hours = ( new DateTimeZone( $tz ) )->getOffset( new DateTime( '@' . e3a_test_now_ts() ) ) / 3600;

	echo str_repeat( '=', 152 ) . "\n";
	printf(
		"TZ del sitio: %s   (gmt_offset = %+g h)   hora local congelada: %s\n",
		$tz,
		$offset_hours,
		gmdate( 'Y-m-d H:i:s', e3a_test_now_ts() + (int) ( $offset_hours * 3600 ) )
	);
	echo str_repeat( '=', 152 ) . "\n";

	$titles = array();
	foreach ( $columns as $col ) {
		$titles[] = $col[0];
	}

	echo e3a_rule( $columns ) . "\n";
	echo e3a_row( $titles, $columns ) . "\n";
	echo e3a_rule( $columns ) . "\n";

	foreach ( $cases as $case ) {
		list( $label, $value ) = $case;

		// Simula un request real: el mismo valor por $_GET y como override.
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) {
			$_GET['period'] = (string) $value;
		} else {
			unset( $_GET['period'] );
		}

		$GLOBALS['wpdb']->reset_queries();
		$dates = DatePeriod::resolve( $value );
		$db    = count( $GLOBALS['wpdb']->queries );

		echo e3a_row(
			array(
				$label,
				$dates['period_key'],
				$dates['is_all'] ? 'true' : 'false',
				$dates['period_int'],
				'' === $dates['current_start'] ? '(vacio)' : $dates['current_start'],
				'' === $dates['current_end'] ? '(vacio)' : $dates['current_end'],
				'' === $dates['prev_start'] ? '(vacio)' : $dates['prev_start'],
				'' === $dates['prev_end'] ? '(vacio)' : $dates['prev_end'],
				e3a_span_days( $dates['current_start'], $dates['current_end'] ),
				$db,
			),
			$columns
		) . "\n";
	}

	echo e3a_rule( $columns ) . "\n\n";
}

// Comprobaciones de invariantes que el cambio a dias calendario va a tocar.
echo str_repeat( '=', 152 ) . "\n";
echo "Invariantes observadas hoy (no son aserciones: son el estado actual, para diffear despues)\n";
echo str_repeat( '=', 152 ) . "\n";

$GLOBALS['e3a_test_tz_override'] = $timezones[0];
unset( $_GET['period'] );

$d30 = DatePeriod::resolve( '30' );
$dal = DatePeriod::resolve( 'all' );

printf(
	"  prev_end === current_start                       : %s   (%s)\n",
	$d30['prev_end'] === $d30['current_start'] ? 'SI' : 'NO',
	$d30['prev_end']
);
printf(
	"  current_end lleva segundos (rompe cache de pais) : %s   (%s)\n",
	preg_match( '/ \d{2}:\d{2}:\d{2}$/', $d30['current_end'] ) ? 'SI' : 'NO',
	$d30['current_end']
);
printf(
	"  ventanas ancladas a 'ahora', no a medianoche      : %s\n",
	substr( $d30['current_end'], 11 ) !== '00:00:00' ? 'SI' : 'NO'
);
printf(
	"  period=all: period_int                           : %d   (0 es el valor que rompia el form de abandono)\n",
	$dal['period_int']
);
printf(
	"  period=all: prev_* vacios (sin comparativa)      : %s\n",
	( '' === $dal['prev_start'] && '' === $dal['prev_end'] ) ? 'SI' : 'NO'
);
printf(
	"  period=all: current_start = MIN(post_date)       : %s\n",
	$dal['current_start']
);
echo "\n";
