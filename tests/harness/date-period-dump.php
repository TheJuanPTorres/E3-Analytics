<?php
/**
 * Dump del contrato de E3_Analytics\Support\DatePeriod::resolve().
 *
 * Sin WordPress, sin base de datos, con reloj congelado. La salida tiene que
 * ser byte-idéntica entre corridas para poder diffear antes/después.
 *
 * Uso:
 *   php tests/harness/date-period-dump.php --only=presets
 *   php tests/harness/date-period-dump.php --only=all --mode=all
 *   php tests/harness/date-period-dump.php --mode=calendar_utc --now='2026-07-30 04:28:00'
 *   php tests/harness/date-period-dump.php --min='2019-03-14 08:22:31'
 *   php tests/harness/date-period-dump.php --min=''        # tabla sin inscripciones
 *   php tests/harness/date-period-dump.php --tz=UTC
 *
 * La invocación de referencia (la que se compara contra
 * tests/harness/baseline-presets.txt) es:
 *   php tests/harness/date-period-dump.php --only=presets
 * o sea --mode=legacy, que es el default. Esa tabla imprime SOLO las 7 claves
 * originales, así que corre igual contra el DatePeriod viejo y el nuevo: es lo
 * que permite demostrar que legacy no se movió.
 *
 * La zona del sitio es America/Bogota, confirmada en Ajustes -> Generales, con
 * timezone_string poblado y sin horario de verano. No se recorren otras zonas
 * por defecto ni se ejercita DST: no aplica a este sitio.
 */

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

$opts = array(
	'only' => 'all',
	'mode' => 'legacy',
	'tz'   => null,
	'now'  => null,
	'min'  => null,
);

/*
 * --frozen imprime SOLO la cabecera y la tabla de las 7 claves originales.
 * Es la invocación de referencia: al no tocar ninguna clave nueva, produce
 * salida byte-idéntica contra el DatePeriod viejo y el nuevo, y por eso su md5
 * sirve como guarda de regresión del modo legacy. El resto de las secciones
 * (claves nuevas, labels, invariantes, prueba de pertenencia) no existen en el
 * código viejo, así que quedan fuera de la comparación a propósito.
 */
$frozen = false;

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--frozen' === $arg ) {
		$frozen = true;
		continue;
	}
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

if ( ! in_array( $opts['only'], array( 'all', 'presets', 'custom', 'calendar' ), true ) ) {
	fwrite( STDERR, "--only debe ser: all | presets | custom | calendar\n" );
	exit( 2 );
}
if ( ! in_array( $opts['mode'], array( 'all', 'legacy', 'calendar', 'calendar_utc' ), true ) ) {
	fwrite( STDERR, "--mode debe ser: all | legacy | calendar | calendar_utc\n" );
	exit( 2 );
}

// Las constantes tienen que definirse antes de cargar los stubs.
if ( null !== $opts['now'] ) {
	define( 'E3A_TEST_NOW', $opts['now'] );
}
if ( null !== $opts['min'] ) {
	define( 'E3A_TEST_MIN_POST', $opts['min'] );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__, 2 ) . '/includes/Support/DatePeriod.php';

use E3_Analytics\Support\DatePeriod;

$GLOBALS['e3a_test_tz_override'] = ( null !== $opts['tz'] && '' !== $opts['tz'] )
	? $opts['tz']
	: 'America/Bogota';

$modes = ( 'all' === $opts['mode'] )
	? array( 'legacy', 'calendar', 'calendar_utc' )
	: array( $opts['mode'] );

// ---------------------------------------------------------------------------
// Casos
// ---------------------------------------------------------------------------

$presets = array(
	array( '7', '7' ),
	array( '30', '30' ),
	array( '90', '90' ),
	array( '365', '365' ),
	array( 'all', 'all' ),
);

$calendar_presets = array(
	array( 'this_month', 'this_month' ),
	array( 'last_month', 'last_month' ),
	array( 'this_quarter', 'this_quarter' ),
	array( 'this_year', 'this_year' ),
	array( 'last_year', 'last_year' ),
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
	case 'calendar':
		$cases = $calendar_presets;
		break;
	case 'custom':
		$cases = $customs;
		break;
	default:
		$cases = array_merge( $presets, $calendar_presets, $customs );
		break;
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

/** Tabla A: las 7 claves originales. Formato congelado — es la referencia. */
$cols_core = array(
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

/** Tabla B: claves nuevas. Solo se imprime en los modos calendario. */
$cols_new = array(
	array( 'input',             38 ),
	array( 'days',               6 ),
	array( 'is_custom',          9 ),
	array( 'preset_key',        12 ),
	array( 'current_start_utc', 19 ),
	array( 'current_end_utc',   19 ),
	array( 'prev_start_utc',    19 ),
	array( 'prev_end_utc',      19 ),
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

function e3a_titles( array $columns ) {
	$t = array();
	foreach ( $columns as $col ) {
		$t[] = $col[0];
	}
	return $t;
}

function e3a_span_days( $start, $end ) {
	if ( '' === (string) $start || '' === (string) $end ) {
		return '-';
	}
	$a = strtotime( (string) $start );
	$b = strtotime( (string) $end );
	if ( false === $a || false === $b ) {
		return '?';
	}
	return number_format( ( $b - $a ) / 86400, 2, '.', '' );
}

function e3a_bool( $v ) {
	return $v ? 'true' : 'false';
}

/**
 * Resuelve un caso simulando un request real: el mismo valor por $_GET y como
 * override, que es lo que hace Page.php.
 */
function e3a_resolve_case( $value ) {
	if ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) {
		$_GET['period'] = (string) $value;
	} else {
		unset( $_GET['period'] );
	}

	$GLOBALS['wpdb']->reset_queries();

	return DatePeriod::resolve( $value );
}

$tz_name      = (string) $GLOBALS['e3a_test_tz_override'];
$offset_hours = ( new DateTimeZone( $tz_name ) )->getOffset( new DateTime( '@' . e3a_test_now_ts() ) ) / 3600;
$local_now    = gmdate( 'Y-m-d H:i:s', e3a_test_now_ts() + (int) ( $offset_hours * 3600 ) );

echo "DatePeriod::resolve() — dump de contrato\n";
echo str_repeat( '=', 152 ) . "\n";
echo 'PHP                 : ' . PHP_VERSION . "\n";
echo 'default_timezone    : ' . date_default_timezone_get() . "  (WordPress fija UTC en su bootstrap)\n";
echo 'TZ del sitio        : ' . $tz_name . sprintf( '  (offset %+g h, sin horario de verano)', $offset_hours ) . "\n";
echo 'reloj congelado UTC : ' . E3A_TEST_NOW . "\n";
echo 'reloj congelado LOCA: ' . $local_now . "\n";
echo 'MIN(post_date)      : ' . ( '' === E3A_TEST_MIN_POST ? '(null — tabla sin inscripciones)' : E3A_TEST_MIN_POST ) . "\n";
echo 'casos               : ' . $opts['only'] . ' (' . count( $cases ) . ")\n";
echo 'modos               : ' . implode( ', ', $modes ) . "\n";
echo "\n";
echo "span = dias entre current_start y current_end.  db = consultas a \$wpdb durante resolve().\n";
echo "La tabla de las 7 claves originales tiene formato congelado: su md5 es el\n";
echo "guard de regresion del contrato de DatePeriod (tests/harness/*.md5).\n";
echo "\n";

foreach ( $modes as $mode ) {

	// El plugin ya no tiene modos: DatePeriod resuelve siempre en calendario con
	// claves UTC. --mode se acepta por compatibilidad de invocación (y para que
	// el md5 del baseline congelado no cambie), pero no altera el resultado.

	echo str_repeat( '=', 152 ) . "\n";
	echo 'MODO: ' . $mode . "\n";
	echo str_repeat( '=', 152 ) . "\n";

	// --- Tabla A: las 7 claves originales -----------------------------------
	echo e3a_rule( $cols_core ) . "\n";
	echo e3a_row( e3a_titles( $cols_core ), $cols_core ) . "\n";
	echo e3a_rule( $cols_core ) . "\n";

	$resolved = array();

	foreach ( $cases as $case ) {
		list( $label, $value ) = $case;

		$d                  = e3a_resolve_case( $value );
		$resolved[ $label ] = $d;

		echo e3a_row(
			array(
				$label,
				$d['period_key'] ?? '',
				e3a_bool( $d['is_all'] ?? false ),
				$d['period_int'] ?? '',
				'' === ( $d['current_start'] ?? '' ) ? '(vacio)' : $d['current_start'],
				'' === ( $d['current_end'] ?? '' ) ? '(vacio)' : $d['current_end'],
				'' === ( $d['prev_start'] ?? '' ) ? '(vacio)' : $d['prev_start'],
				'' === ( $d['prev_end'] ?? '' ) ? '(vacio)' : $d['prev_end'],
				e3a_span_days( $d['current_start'] ?? '', $d['current_end'] ?? '' ),
				count( $GLOBALS['wpdb']->queries ),
			),
			$cols_core
		) . "\n";
	}

	echo e3a_rule( $cols_core ) . "\n";

	if ( $frozen ) {
		echo "\n";
		continue;
	}

	// --- Tabla B: claves nuevas. Solo en modos calendario. ------------------
	if ( 'legacy' !== $mode ) {
		echo "\nClaves nuevas:\n";
		echo e3a_rule( $cols_new ) . "\n";
		echo e3a_row( e3a_titles( $cols_new ), $cols_new ) . "\n";
		echo e3a_rule( $cols_new ) . "\n";

		foreach ( $cases as $case ) {
			$label = $case[0];
			$d     = $resolved[ $label ];

			echo e3a_row(
				array(
					$label,
					$d['days'] ?? '',
					e3a_bool( $d['is_custom'] ?? false ),
					'' === ( $d['preset_key'] ?? '' ) ? '(vacio)' : $d['preset_key'],
					'' === ( $d['current_start_utc'] ?? '' ) ? '(vacio)' : $d['current_start_utc'],
					'' === ( $d['current_end_utc'] ?? '' ) ? '(vacio)' : $d['current_end_utc'],
					'' === ( $d['prev_start_utc'] ?? '' ) ? '(vacio)' : $d['prev_start_utc'],
					'' === ( $d['prev_end_utc'] ?? '' ) ? '(vacio)' : $d['prev_end_utc'],
				),
				$cols_new
			) . "\n";
		}

		echo e3a_rule( $cols_new ) . "\n";
	}

	// --- label / prev_label / notice ---------------------------------------
	echo "\nlabel / prev_label / notice:\n";
	foreach ( $cases as $case ) {
		$label = $case[0];
		$d     = $resolved[ $label ];

		printf( "  %-38s label      : %s\n", $label, (string) ( $d['label'] ?? '' ) );
		printf( "  %-38s prev_label : %s\n", '', (string) ( $d['prev_label'] ?? '' ) );
		if ( '' !== (string) ( $d['notice'] ?? '' ) ) {
			printf( "  %-38s notice     : %s\n", '', (string) $d['notice'] );
		}
	}

	// --- Invariantes -------------------------------------------------------
	echo "\nInvariantes en este modo:\n";

	$d30 = e3a_resolve_case( '30' );

	printf(
		"  prev_end !== current_start (sin doble conteo)  : %s   (prev_end=%s / current_start=%s)\n",
		( ( $d30['prev_end'] ?? '' ) !== ( $d30['current_start'] ?? '' ) ) ? 'SI' : 'NO',
		(string) ( $d30['prev_end'] ?? '' ),
		(string) ( $d30['current_start'] ?? '' )
	);
	printf(
		"  limites en medianoche / fin de dia            : %s\n",
		( substr( (string) ( $d30['current_start'] ?? '' ), 11 ) === '00:00:00'
		  && substr( (string) ( $d30['current_end'] ?? '' ), 11 ) === '23:59:59' ) ? 'SI' : 'NO'
	);
	printf(
		"  current_end_utc con fecha distinta de current_end: %s   (%s vs %s)\n",
		( substr( (string) ( $d30['current_end_utc'] ?? '' ), 0, 10 ) !== substr( (string) ( $d30['current_end'] ?? '' ), 0, 10 ) ) ? 'SI' : 'NO',
		substr( (string) ( $d30['current_end'] ?? '' ), 0, 10 ),
		substr( (string) ( $d30['current_end_utc'] ?? '' ), 0, 10 )
	);

	echo "\n";
}

// ---------------------------------------------------------------------------
// El chequeo que decide si las claves _utc se estan usando de verdad
// ---------------------------------------------------------------------------

if ( $frozen ) {
	exit( 0 );
}

echo str_repeat( '=', 152 ) . "\n";
echo "PRUEBA DE PERTENENCIA: user_registered = '2026-07-30 04:28:31' (UTC)\n";
echo str_repeat( '=', 152 ) . "\n";
echo "Ese instante es el 29 de julio 23:28:31 hora local de Bogota. Es un usuario\n";
echo "que se registro ANOCHE en hora local, pero que WordPress guardo con fecha UTC\n";
echo "del dia siguiente. La pregunta es si el dashboard lo cuenta en 'hoy'.\n";
echo "Se evalua contra la ventana ACTUAL de period=7, con las claves _utc, que son\n";
echo "las que leen las 6 queries de user_registered.\n\n";

$probe = '2026-07-30 04:28:31';

printf( "  %-14s %-21s %-21s %s\n", 'modo', 'current_start_utc', 'current_end_utc', 'el registro cae' );
echo '  ' . str_repeat( '-', 78 ) . "\n";

foreach ( array( 'legacy', 'calendar', 'calendar_utc' ) as $mode ) {
	// El plugin ya no tiene modos: DatePeriod resuelve siempre en calendario con
	// claves UTC. --mode se acepta por compatibilidad de invocación (y para que
	// el md5 del baseline congelado no cambie), pero no altera el resultado.

	$d = e3a_resolve_case( '7' );

	$s = (string) ( $d['current_start_utc'] ?? '' );
	$e = (string) ( $d['current_end_utc'] ?? '' );

	$inside = ( '' !== $s && '' !== $e && $probe >= $s && $probe <= $e );

	printf(
		"  %-14s %-21s %-21s %s\n",
		$mode,
		$s,
		$e,
		$inside ? 'DENTRO' : 'FUERA'
	);
}

echo "\n  Esperado: FUERA en legacy y DENTRO en calendar_utc, con CUALQUIERA de los\n";
echo "  tres relojes. Si no cambia entre modos, las claves _utc no se estan usando.\n";
echo "\n";
echo "  'calendar' depende del reloj, y eso es informativo por si mismo:\n";
echo "    - reloj 23:28 local del 29: FUERA. El dia calendario local termina a las\n";
echo "      23:59:59 locales, que en UTC son las 04:59:59 del 30, pero sin conversion\n";
echo "      el limite se compara como '2026-07-29 23:59:59' contra una columna UTC.\n";
echo "    - reloj 00:01 local del 30: DENTRO, pero por accidente: la ventana de 7 dias\n";
echo "      ya se corrio e incluye todo el 30 local, asi que barre el instante UTC.\n";
echo "  O sea: los dias completos por si solos NO arreglan el desfase de zona.\n";
echo "  Hace falta la conversion, y por eso existe calendar_utc.\n\n";
