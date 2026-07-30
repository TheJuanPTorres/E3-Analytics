<?php
namespace E3_Analytics\Support;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Método compartido para clasificar progreso en buckets de abandono.
 * Usado por DropoutProgressService y ExportService.
 */
trait BucketHelper {

    private function bucket_key( $p ) {
        $p = (float) $p;
        if ( $p <= 10 ) return '0_10';
        if ( $p <= 25 ) return '11_25';
        if ( $p <= 50 ) return '26_50';
        if ( $p <= 75 ) return '51_75';
        return '76_99';
    }
}
