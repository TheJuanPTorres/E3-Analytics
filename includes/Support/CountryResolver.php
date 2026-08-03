<?php
namespace E3_Analytics\Support;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Resolución de país: fuente única para todo el plugin.
 *
 * Antes esta lógica estaba duplicada, casi literal, en
 * CountryAnalyticsService::get_country_map_for_users() y
 * CountryUsersExportService::get_country_details_for_users(). Dos copias de la
 * misma regla divergen apenas alguien toca una sola.
 *
 * PRECEDENCIA
 *   1. country_lms          formulario vigente, guarda nombres ("Colombia")
 *   2. tutor_login_*        JSON del último acceso, guarda ISO-2
 *   3. _pais                formulario anterior, guarda ISO-2 ("CO")
 *   4. sin dato
 *
 * country_lms va primero por ser el campo vigente. NUNCA al revés.
 *
 * CANÓNICO INTERNO: ISO-2.
 * Es un conjunto cerrado y sin ambigüedad. El nombre sirve para mostrar, no para
 * agrupar: sin esto "Colombia" y "CO" quedarían en dos filas distintas del mismo
 * listado, que es exactamente el problema que este resolver viene a arreglar.
 *
 * El mapa nombre→ISO-2 se construye en tiempo de ejecución invirtiendo
 * iso2_to_name() sobre la lista de códigos ISO-3166-1. Con intl (que es el caso
 * en producción) sale de ICU y cubre los 249 países; sin intl queda acotado al
 * mapa fijo de 14 de CountryHelper. Construirlo así evita adivinar la ortografía
 * exacta de cada nombre.
 *
 * Lo que no mapea a ISO-2 conserva su nombre normalizado como bucket propio y se
 * puede contar aparte con unmapped_labels(). Hoy no hay ninguno, pero puede
 * aparecer con registros nuevos o valores escritos a mano.
 */
final class CountryResolver {

	use CountryHelper;

	/** Chunk para los IN(), igual que el resto del plugin. */
	const CHUNK = 2000;

	/** Etiquetas de procedencia, para que se entienda de dónde salió el dato. */
	const SOURCE_LABELS = [
		'country_lms' => 'Formulario actual',
		'tutor_login' => 'Último acceso',
		'_pais'       => 'Formulario anterior',
		''            => 'Sin dato',
	];

	/** ISO-3166-1 alpha-2. Se usa para invertir iso2_to_name(). */
	const ISO2_CODES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW';

	/** @var array<string,string>|null nombre normalizado => ISO-2 */
	private $reverse_map = null;

	/** @var array<string,int> etiquetas que no mapearon a ISO-2 */
	private $unmapped = [];

	/**
	 * Resuelve el país de un conjunto de usuarios.
	 *
	 * UNA consulta por chunk de 2.000 usuarios, que trae las tres fuentes a la
	 * vez. Antes eran dos consultas separadas (country_lms por un lado,
	 * tutor_login_* por otro), así que esto no agrega ninguna: quita.
	 *
	 * @param int[] $user_ids
	 * @return array<int,array{iso2:string,label:string,source:string,raw:string}>
	 */
	public function resolve_for_users( $user_ids ) {
		global $wpdb;

		$user_ids = array_values( array_filter( array_map( 'intval', (array) $user_ids ) ) );

		$out = [];
		foreach ( $user_ids as $uid ) {
			$out[ $uid ] = [
				'iso2'   => '',
				'label'  => '',
				'source' => '',
				'raw'    => '',
			];
		}

		if ( empty( $user_ids ) ) {
			return $out;
		}

		$country = [];
		$pais    = [];
		$login   = [];

		foreach ( array_chunk( $user_ids, self::CHUNK ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			/*
			 * Las tres fuentes en una sola pasada. El ORDER BY umeta_id DESC es
			 * para tutor_login_*: puede haber varias filas por usuario y hay que
			 * quedarse con la más reciente, igual que hacía el código anterior.
			 */
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, meta_key, meta_value
					 FROM {$wpdb->usermeta}
					 WHERE user_id IN ($in)
					   AND ( meta_key IN ('country_lms','_pais') OR meta_key LIKE 'tutor_login_%' )
					 ORDER BY umeta_id DESC",
					$chunk
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $r ) {
				$uid = (int) ( $r['user_id'] ?? 0 );
				$key = (string) ( $r['meta_key'] ?? '' );
				$val = trim( (string) ( $r['meta_value'] ?? '' ) );

				if ( $uid <= 0 || '' === $val ) {
					continue;
				}

				if ( 'country_lms' === $key ) {
					if ( ! isset( $country[ $uid ] ) ) {
						$country[ $uid ] = $val;
					}
					continue;
				}

				if ( '_pais' === $key ) {
					if ( ! isset( $pais[ $uid ] ) ) {
						$pais[ $uid ] = $val;
					}
					continue;
				}

				// tutor_login_*: la primera que llega es la de umeta_id mayor.
				if ( isset( $login[ $uid ] ) ) {
					continue;
				}

				$json = json_decode( $val, true );
				if ( is_array( $json ) && ! empty( $json['country'] ) ) {
					$login[ $uid ] = (string) $json['country'];
				}
			}
		}

		foreach ( $user_ids as $uid ) {
			if ( isset( $country[ $uid ] ) ) {
				$out[ $uid ] = $this->build( $country[ $uid ], 'country_lms' );
			} elseif ( isset( $login[ $uid ] ) ) {
				$out[ $uid ] = $this->build( $login[ $uid ], 'tutor_login' );
			} elseif ( isset( $pais[ $uid ] ) ) {
				$out[ $uid ] = $this->build( $pais[ $uid ], '_pais' );
			}
		}

		return $out;
	}

	/**
	 * Etiquetas que no se pudieron llevar a ISO-2, con su frecuencia.
	 *
	 * Se llena durante resolve_for_users(). Vacío es lo esperado.
	 *
	 * @return array<string,int>
	 */
	public function unmapped_labels() {
		return $this->unmapped;
	}

	/**
	 * @param string $source Clave interna de procedencia.
	 * @return string Etiqueta legible.
	 */
	public static function source_label( $source ) {
		return self::SOURCE_LABELS[ (string) $source ] ?? (string) $source;
	}

	/**
	 * Construye la entrada resuelta a partir de un valor crudo.
	 *
	 * El valor puede venir como ISO-2 ("CO") o como nombre ("Colombia"): se
	 * normaliza a ISO-2 en los dos casos.
	 *
	 * @param string $raw
	 * @param string $source
	 * @return array{iso2:string,label:string,source:string,raw:string}
	 */
	private function build( $raw, $source ) {
		$raw  = trim( (string) $raw );
		$iso2 = '';

		if ( 2 === strlen( $raw ) && ctype_alpha( $raw ) ) {
			$iso2 = strtoupper( $raw );
		} else {
			$iso2 = $this->name_to_iso2( $raw );
		}

		if ( '' !== $iso2 ) {
			return [
				'iso2'   => $iso2,
				'label'  => $this->normalize_country_label( $this->iso2_to_name( $iso2 ) ),
				'source' => $source,
				'raw'    => $raw,
			];
		}

		/*
		 * No mapeó a ISO-2. Se conserva el nombre normalizado como bucket propio
		 * en vez de tirarlo: perder un país conocido sería peor que tener una
		 * fila sin código.
		 */
		$label = $this->normalize_country_label( $raw );

		if ( '' !== $label ) {
			$this->unmapped[ $label ] = ( $this->unmapped[ $label ] ?? 0 ) + 1;
		}

		return [
			'iso2'   => '',
			'label'  => $label,
			'source' => $source,
			'raw'    => $raw,
		];
	}

	/**
	 * Nombre de país → ISO-2, invirtiendo iso2_to_name().
	 *
	 * @param string $name
	 * @return string ISO-2 o cadena vacía.
	 */
	private function name_to_iso2( $name ) {
		$key = $this->lookup_key( $name );

		if ( '' === $key ) {
			return '';
		}

		$map = $this->reverse_map();

		return $map[ $key ] ?? '';
	}

	/**
	 * Mapa nombre normalizado → ISO-2, construido una vez por request.
	 *
	 * @return array<string,string>
	 */
	private function reverse_map() {
		if ( null !== $this->reverse_map ) {
			return $this->reverse_map;
		}

		$map = [];

		foreach ( explode( ' ', self::ISO2_CODES ) as $code ) {
			$name = $this->iso2_to_name( $code );

			// Sin intl, iso2_to_name() devuelve el propio código para los que no
			// están en el mapa fijo: eso no sirve como nombre.
			if ( '' === $name || $name === $code ) {
				continue;
			}

			$key = $this->lookup_key( $name );
			if ( '' !== $key && ! isset( $map[ $key ] ) ) {
				$map[ $key ] = $code;
			}
		}

		// Variantes que ICU no produce pero la gente escribe.
		$alias = [
			'estados unidos de america' => 'US',
			'eeuu'                      => 'US',
			'usa'                       => 'US',
			'reino unido de gran bretana e irlanda del norte' => 'GB',
			'inglaterra'                => 'GB',
			'republica dominicana'      => 'DO',
			'corea del sur'             => 'KR',
			'bolivia'                   => 'BO',
			'venezuela'                 => 'VE',
		];

		foreach ( $alias as $k => $code ) {
			if ( ! isset( $map[ $k ] ) ) {
				$map[ $k ] = $code;
			}
		}

		$this->reverse_map = $map;

		return $map;
	}

	/**
	 * Clave de comparación: minúsculas, sin acentos, sin espacios repetidos.
	 *
	 * Sin esto "México" y "Mexico" serían dos países distintos.
	 *
	 * @param string $value
	 * @return string
	 */
	private function lookup_key( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$value = function_exists( 'mb_strtolower' )
			? mb_strtolower( $value, 'UTF-8' )
			: strtolower( $value );

		$value = strtr(
			$value,
			[
				'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
				'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
				'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
				'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
				'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
				'ñ' => 'n', 'ç' => 'c',
			]
		);

		$value = preg_replace( '/[^a-z0-9 ]+/', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		return trim( (string) $value );
	}
}
