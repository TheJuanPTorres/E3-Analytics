<?php
namespace E3_Analytics\Support;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Math {
    public static function growth_percent($current, $previous) {
        $current  = (float) $current;
        $previous = (float) $previous;

        if ($previous <= 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
