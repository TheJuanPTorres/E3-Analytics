<?php
/**
 * Partial: selector de período + rango personalizado.
 *
 * Lo incluyen las 3 vistas. Fuente única: antes cada una tenía su propia copia
 * de las <option> escritas a mano.
 *
 * Los dos <input type="date"> se renderizan SIEMPRE visibles. Es JS quien los
 * oculta cuando el preset elegido no es "Rango personalizado" (ver
 * admin/assets/admin.js). Si el JS no carga, quedan a la vista y el flujo sigue
 * funcionando igual: el submit es el botón "Actualizar" del <form method="get">
 * y no hay ningún listener del que dependa el envío.
 *
 * @var string $period_key Clave del período efectivo que devolvió DatePeriod.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$e3a_is_custom = \E3_Analytics\Support\DatePeriod::is_custom_key( $period_key );

// Los inputs se repueblan con el rango EFECTIVO, no con lo que mandó el usuario:
// si DatePeriod lo recortó (fin futuro, o tope superado), se ve el recortado.
$e3a_from = $e3a_is_custom ? substr( $period_key, 0, 10 ) : '';
$e3a_to   = $e3a_is_custom ? substr( $period_key, 12, 10 ) : '';

$e3a_today = ( new \DateTimeImmutable( '@' . (int) current_time( 'timestamp', true ) ) )
	->setTimezone( wp_timezone() )
	->format( 'Y-m-d' );
?>
        <div class="e3-field">
          <label class="e3-field-label" for="e3-period">Período</label>
          <select id="e3-period" name="period" class="e3-select" data-e3-period>
<?php foreach ( \E3_Analytics\Support\DatePeriod::presets() as $preset_key => $preset_label ) : ?>
            <option value="<?php echo esc_attr( $preset_key ); ?>" <?php selected( $period_key, $preset_key ); ?>><?php echo esc_html( $preset_label ); ?></option>
<?php endforeach; ?>
            <option value="custom" <?php selected( $e3a_is_custom, true ); ?>><?php echo esc_html__( 'Rango personalizado…', 'e3-analytics' ); ?></option>
          </select>
        </div>

        <div class="e3-field e3-custom-range" data-e3-custom-range>
          <label class="e3-field-label" for="e3a-from"><?php echo esc_html__( 'Desde', 'e3-analytics' ); ?></label>
          <input type="date" id="e3a-from" name="e3a_from" class="e3-input e3-input--date"
                 value="<?php echo esc_attr( $e3a_from ); ?>"
                 max="<?php echo esc_attr( $e3a_today ); ?>" />
        </div>

        <div class="e3-field e3-custom-range" data-e3-custom-range>
          <label class="e3-field-label" for="e3a-to"><?php echo esc_html__( 'Hasta', 'e3-analytics' ); ?></label>
          <input type="date" id="e3a-to" name="e3a_to" class="e3-input e3-input--date"
                 value="<?php echo esc_attr( $e3a_to ); ?>"
                 max="<?php echo esc_attr( $e3a_today ); ?>" />
        </div>
