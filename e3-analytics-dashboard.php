<?php
/**
 * Plugin Name: E3 Analytics Dashboard
 * Description: Panel de KPIs personalizados para Tutor LMS: registros, inscripciones, progreso, actividad, abandono, rendimiento, retención (7 días → histórico) y comportamiento DAU/MAU.
 * Author: Juan Pablo Torres
 * Version: 1.2.9.2-b1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'E3A_VERSION', '1.2.9.2-b1' );
define( 'E3A_PATH', plugin_dir_path( __FILE__ ) );
define( 'E3A_URL', plugin_dir_url( __FILE__ ) );

require_once E3A_PATH . 'includes/Plugin.php';

add_action('plugins_loaded', function () {
    \E3_Analytics\Plugin::instance()->init();
});
