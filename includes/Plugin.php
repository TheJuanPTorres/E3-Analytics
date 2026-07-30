<?php
namespace E3_Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once E3A_PATH . 'includes/Support/DatePeriod.php';
require_once E3A_PATH . 'includes/Support/Math.php';
require_once E3A_PATH . 'includes/Support/Xlsx.php';
require_once E3A_PATH . 'includes/Support/CountryHelper.php';
require_once E3A_PATH . 'includes/Support/BucketHelper.php';
require_once E3A_PATH . 'includes/Settings.php';
require_once E3A_PATH . 'includes/Repositories/UsersRepository.php';
require_once E3A_PATH . 'includes/Repositories/EnrollmentsRepository.php';
require_once E3A_PATH . 'includes/Integrations/TutorLms.php';
require_once E3A_PATH . 'includes/Services/MetricsService.php';
require_once E3A_PATH . 'includes/Services/DropoutProgressService.php';
require_once E3A_PATH . 'includes/Services/CountryAnalyticsService.php';
require_once E3A_PATH . 'includes/Services/ExportService.php';
require_once E3A_PATH . 'includes/Admin/Page.php';
// TEMPORAL etapa B1: borrar este require junto con el archivo al cerrar B2.
require_once E3A_PATH . 'includes/Admin/Diagnostics.php';

final class Plugin {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        (new Admin\Page())->hooks();

        // TEMPORAL etapa B1: no hace nada si no hay WP-CLI. Borrar al cerrar B2.
        Admin\Diagnostics::register_cli();
    }
}
