<?php
namespace E3_Analytics\Support;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Métodos compartidos para normalización de etiquetas de país.
 * Usado por CountryAnalyticsService y CountryUsersExportService.
 */
trait CountryHelper {

    private function normalize_country_label( $label ) {
        $label = trim( (string) $label );
        if ( $label === '' ) return '';

        if ( strlen( $label ) === 2 ) {
            $label = $this->iso2_to_name( $label );
        }

        $label = preg_replace( '/\s+/', ' ', $label );
        if ( function_exists( 'mb_convert_case' ) ) {
            $label = mb_convert_case( $label, MB_CASE_TITLE, 'UTF-8' );
        }
        return $label;
    }

    private function iso2_to_name( $code ) {
        $code = strtoupper( trim( (string) $code ) );
        if ( $code === '' ) return '';

        if ( class_exists( '\\Locale' ) ) {
            try {
                $name = \Locale::getDisplayRegion( '-' . $code, 'es' );
                if ( $name && is_string( $name ) ) {
                    $name = trim( $name );
                    if ( $name !== '' && $name !== $code ) return $name;
                }
            } catch ( \Throwable $e ) {}
        }

        $fallback = [
            'CO' => 'Colombia',
            'MX' => 'México',
            'ES' => 'España',
            'US' => 'Estados Unidos',
            'AR' => 'Argentina',
            'PE' => 'Perú',
            'CL' => 'Chile',
            'EC' => 'Ecuador',
            'VE' => 'Venezuela',
            'PA' => 'Panamá',
            'CR' => 'Costa Rica',
            'GT' => 'Guatemala',
            'DO' => 'República Dominicana',
            'BR' => 'Brasil',
        ];
        return $fallback[ $code ] ?? $code;
    }
}
