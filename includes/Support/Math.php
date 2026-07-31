<?php
namespace E3_Analytics\Support;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Math {

    /**
     * Variación porcentual entre dos períodos.
     *
     * Devuelve NULL cuando el período previo está en cero y el actual no: no hay
     * base contra la cual calcular un porcentaje. Antes devolvía 100 fijo, así
     * que 0→1 y 0→5000 mostraban lo mismo ("+100%"), que además se leía como un
     * crecimiento medido cuando era un valor inventado. Con rangos cortos —y con
     * el rango personalizado esto pasa a ser frecuente— el período previo vacío
     * es lo normal, no la excepción.
     *
     * Los consumidores deben tratar el null como "sin base de comparación" y
     * mostrar un guion, NO convertirlo a 0 con ?? ni con un cast a float.
     *
     * @param int|float $current
     * @param int|float $previous
     * @return float|int|null
     */
    public static function growth_percent($current, $previous) {
        $current  = (float) $current;
        $previous = (float) $previous;

        if ($previous <= 0) {
            // Ambos en cero: no hubo cambio, y eso sí es un dato.
            return $current > 0 ? null : 0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
