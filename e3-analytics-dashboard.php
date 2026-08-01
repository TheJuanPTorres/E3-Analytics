<?php
/**
 * Plugin Name: E3 Analytics Dashboard
 * Description: Panel de KPIs personalizados para Tutor LMS: registros, inscripciones, progreso, actividad, abandono, rendimiento, retención (7 días → histórico) y comportamiento DAU/MAU.
 * Author: Juan Pablo Torres
 * Version: 1.3.1
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * Text Domain: e3-analytics
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'E3A_VERSION', '1.3.1' );
define( 'E3A_PATH', plugin_dir_path( __FILE__ ) );
define( 'E3A_URL', plugin_dir_url( __FILE__ ) );

require_once E3A_PATH . 'includes/Plugin.php';

add_action('plugins_loaded', function () {
    \E3_Analytics\Plugin::instance()->init();
});
