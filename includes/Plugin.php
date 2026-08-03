<?php
namespace E3_Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once E3A_PATH . 'includes/Support/DatePeriod.php';
require_once E3A_PATH . 'includes/Support/Math.php';
require_once E3A_PATH . 'includes/Support/Xlsx.php';
require_once E3A_PATH . 'includes/Support/CountryHelper.php';
require_once E3A_PATH . 'includes/Support/CountryResolver.php';
require_once E3A_PATH . 'includes/Support/BucketHelper.php';
require_once E3A_PATH . 'includes/Repositories/UsersRepository.php';
require_once E3A_PATH . 'includes/Repositories/EnrollmentsRepository.php';
require_once E3A_PATH . 'includes/Integrations/TutorLms.php';
require_once E3A_PATH . 'includes/Services/MetricsService.php';
require_once E3A_PATH . 'includes/Services/DropoutProgressService.php';
require_once E3A_PATH . 'includes/Services/CountryAnalyticsService.php';
require_once E3A_PATH . 'includes/Services/ExportService.php';
require_once E3A_PATH . 'includes/Admin/Page.php';
// TEMPORAL: borrar junto con el archivo al agregar las columnas demográficas.
require_once E3A_PATH . 'includes/Admin/MetaScan.php';
require_once E3A_PATH . 'includes/Admin/CountrySim.php';

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
    }
}
