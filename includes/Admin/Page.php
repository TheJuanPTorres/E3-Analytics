<?php
namespace E3_Analytics\Admin;

use E3_Analytics\Services\MetricsService;
use E3_Analytics\Services\DropoutProgressService;
use E3_Analytics\Services\CountryAnalyticsService;
use E3_Analytics\Services\ExportService;
use E3_Analytics\Support\Xlsx;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Page {
    private $slug_dashboard = 'e3-analytics-dashboard';
    private $slug_dropout   = 'e3-analytics-dropout-progress';
    private $slug_country   = 'e3-analytics-country-analysis';

    public function hooks() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'admin_post_e3a_export_dropout_users', [ $this, 'export_dropout_users' ] );
        add_action( 'admin_post_e3a_export_excel', [ $this, 'export_excel' ] );
        add_action( 'admin_post_e3a_export_country_users', [ $this, 'export_country_users' ] );
    }

    /**
     * Lee el período del request y lo devuelve como UN escalar.
     *
     * Es el ÚNICO lector de $_GET para el período en todo el plugin: DatePeriod
     * dejó de tener fallback a la superglobal. Los 6 handlers que necesitan el
     * período llaman acá.
     *
     * El formulario manda el rango personalizado en dos campos nativos,
     * e3a_from y e3a_to, y este método los compone en la clave canónica
     * 'YYYY-MM-DD..YYYY-MM-DD'. A partir de ahí el período viaja como un escalar
     * 'period', igual que '30' o 'this_month': ni las URLs de navegación ni las
     * de export llevan parámetros nuevos.
     *
     * OJO — 'custom' es un valor válido del <option> del selector y NO es un
     * valor válido para DatePeriod. Este método lo intercepta y lo traduce al
     * rango compuesto. NO agregar 'custom' al allowlist de DatePeriod "para que
     * funcione": eso volvería representable el estado "custom sin fechas", que
     * DatePeriod coercionaría al default en silencio. Si el selector dice
     * "Rango personalizado" pero los inputs vienen vacíos, acá no se compone
     * nada y se cae al default, que es el comportamiento correcto.
     *
     * @return string|null Null si el request no trae período.
     */
    private function read_period() {
        $from = isset( $_GET['e3a_from'] ) ? sanitize_text_field( wp_unslash( $_GET['e3a_from'] ) ) : '';
        $to   = isset( $_GET['e3a_to'] ) ? sanitize_text_field( wp_unslash( $_GET['e3a_to'] ) ) : '';

        if ( '' !== $from && '' !== $to ) {
            return $from . '..' . $to;
        }

        $period = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : '';

        // 'custom' sin fechas: no es resoluble. Se deja caer al default.
        if ( 'custom' === $period ) {
            return null;
        }

        return '' !== $period ? $period : null;
    }

    public function register_menu() {
        add_menu_page(
            'E3 Analytics',
            'E3 Analytics',
            'manage_options',
            $this->slug_dashboard,
            [ $this, 'render_dashboard' ],
            'dashicons-chart-line',
            25
        );

        add_submenu_page(
            $this->slug_dashboard,
            'Avance en abandono',
            'Avance en abandono',
            'manage_options',
            $this->slug_dropout,
            [ $this, 'render_dropout' ]
        );

        add_submenu_page(
            $this->slug_dashboard,
            'Análisis por país',
            'Análisis por país',
            'manage_options',
            $this->slug_country,
            [ $this, 'render_country' ]
        );

    }

    public function enqueue_assets( $hook ) {
        if ( empty( $_GET['page'] ) ) return;

        $page = sanitize_text_field( wp_unslash( $_GET['page'] ) );
        $plugin_pages = [ $this->slug_dashboard, $this->slug_dropout, $this->slug_country ];
        if ( ! in_array( $page, $plugin_pages, true ) ) return;

        wp_enqueue_style(
            'e3a-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
            [],
            E3A_VERSION
        );

        wp_enqueue_style( 'e3a-admin', E3A_URL . 'admin/assets/admin.css', [ 'e3a-fonts' ], E3A_VERSION );
        wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', [], null, true );
        add_filter( 'script_loader_tag', [ $this, 'add_chartjs_sri' ], 10, 2 );
        wp_enqueue_script( 'e3a-admin', E3A_URL . 'admin/assets/admin.js', [ 'chartjs' ], E3A_VERSION, true );

        if ( $page === $this->slug_dropout ) {
            wp_enqueue_style( 'e3a-dropout', E3A_URL . 'admin/assets/dropout.css', [ 'e3a-admin' ], E3A_VERSION );
            wp_enqueue_script( 'e3a-dropout', E3A_URL . 'admin/assets/dropout.js', [ 'e3a-admin' ], E3A_VERSION, true );
        }

        if ( $page === $this->slug_country ) {
            // CACHE BUSTING: usa filemtime para que cambie automáticamente cuando edites el CSS
            $css_path = E3A_PATH . 'admin/assets/country.css';
            $css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : E3A_VERSION;

            wp_enqueue_style( 'e3a-country', E3A_URL . 'admin/assets/country.css', [ 'e3a-admin' ], $css_ver );
            wp_enqueue_script( 'e3a-country', E3A_URL . 'admin/assets/country.js', [ 'e3a-admin' ], E3A_VERSION, true );
        }
    }

    public function add_chartjs_sri( $tag, $handle ) {
        if ( $handle !== 'chartjs' ) return $tag;
        return str_replace(
            '<script ',
            '<script integrity="sha384-NrKB+u6Ts6AtkIhwPixiKTzgSKNblyhlk0Sohlgar9UHUBzai/sgnNNWWd291xqt" crossorigin="anonymous" ',
            $tag
        );
    }

    public function render_dashboard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'No tienes permisos suficientes para ver esta página.', 'e3-analytics' ) );
        }

        $period = $this->read_period();

        $service = new MetricsService();
        $data    = $service->get_dashboard_data( $period );

        $chart_payload = [
            'labels'    => $data['chart']['labels'] ?? [],
            'first'     => $data['chart']['first'] ?? [],
            'returning' => $data['chart']['returning'] ?? [],
        ];

        wp_add_inline_script( 'e3a-admin', 'window.E3A_CHART = ' . wp_json_encode( $chart_payload ) . ';', 'before' );
        require E3A_PATH . 'admin/views/dashboard.php';
    }

    public function render_dropout() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'No tienes permisos suficientes para ver esta página.', 'e3-analytics' ) );
        }

        $period    = $this->read_period();
        $course_id = isset( $_GET['course_id'] ) ? (int) $_GET['course_id'] : 0;
        $bucket    = isset( $_GET['bucket'] ) ? sanitize_text_field( wp_unslash( $_GET['bucket'] ) ) : '';
        $q         = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

        $service = new DropoutProgressService();
        $report  = $service->get_report([
            'period'        => $period,
            'course_id'     => $course_id,
            'bucket'        => $bucket,
            'q'             => $q,
            'include_users' => $course_id > 0,
        ]);

        $chart_payload = $report['chart'] ?? [ 'labels' => [], 'datasets' => [] ];
        wp_add_inline_script( 'e3a-dropout', 'window.E3A_DROPOUT_CHART = ' . wp_json_encode( $chart_payload ) . ';', 'before' );
        require E3A_PATH . 'admin/views/dropout-progress.php';
    }

    public function render_country() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'No tienes permisos suficientes para ver esta página.', 'e3-analytics' ) );
        }

        $period = $this->read_period();

        $service = new CountryAnalyticsService();
        $report  = $service->get_report( $period );

        $chart_payload = $report['chart'] ?? [ 'labels' => [], 'enrollments' => [], 'completed' => [] ];
        wp_add_inline_script( 'e3a-country', 'window.E3A_COUNTRY_CHART = ' . wp_json_encode( $chart_payload ) . ';', 'before' );

        require E3A_PATH . 'admin/views/country-analysis.php';
    }

    public function export_excel() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'No tienes permisos suficientes para realizar esta acción.', 'e3-analytics' ) );
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'e3a_export_excel' ) ) {
            wp_die( __( 'Nonce inválido. Recarga la página e intenta de nuevo.', 'e3-analytics' ) );
        }

        $scope = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : '';
        $key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

        $allowed_scopes = [ 'dashboard', 'dropout', 'country' ];
        $allowed_keys   = [
            'new_users', 'first_time_enrollments', 'enrollments', 'active_users',
            'cross_course_users', 'performance', 'chart_new_vs_returning', 'courses_detail',
            'top_courses', 'insights', 'retention',
            'dropout_user_list', 'dropout_distribution', 'dropout_course_detail', 'dropout_total_users',
            '',
        ];

        if ( ! in_array( $scope, $allowed_scopes, true ) || ! in_array( $key, $allowed_keys, true ) ) {
            wp_die( __( 'Parámetro de exportación no válido.', 'e3-analytics' ) );
        }

        $period    = $this->read_period();
        $course_id = isset( $_GET['course_id'] ) ? (int) $_GET['course_id'] : 0;
        $bucket    = isset( $_GET['bucket'] ) ? sanitize_text_field( wp_unslash( $_GET['bucket'] ) ) : '';
        $q         = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

        $export = new ExportService();
        $result = $export->build( $scope, $key, [
            'period'    => $period,
            'course_id' => $course_id,
            'bucket'    => $bucket,
            'q'         => $q,
        ] );

        if ( ! is_array( $result ) || empty( $result['sheets'] ) ) {
            wp_die( __( 'No se pudo generar el reporte.', 'e3-analytics' ) );
        }

        $filename = isset( $result['filename'] ) ? (string) $result['filename'] : 'e3-export';
        Xlsx::download( $filename, $result['sheets'] );
    }

    public function export_country_users() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'No tienes permisos suficientes para realizar esta acción.', 'e3-analytics' ) );
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'e3a_export_country_users' ) ) {
            wp_die( __( 'Nonce inválido. Recarga la página e intenta de nuevo.', 'e3-analytics' ) );
        }

        $period = $this->read_period();
        $limit  = isset( $_GET['limit'] ) ? (int) $_GET['limit'] : 0;
        $include_meta = isset( $_GET['include_meta'] ) ? (int) $_GET['include_meta'] : 0;

        require_once E3A_PATH . 'includes/Services/CountryUsersExportService.php';

        $service = new \E3_Analytics\Services\CountryUsersExportService();
        $result  = $service->build( $period, $limit, $include_meta === 1 );

        if ( ! is_array( $result ) || empty( $result['sheets'] ) ) {
            wp_die( __( 'No se pudo generar el reporte.', 'e3-analytics' ) );
        }

        $filename = isset( $result['filename'] ) ? (string) $result['filename'] : 'e3-pais-usuarios';
        Xlsx::download( $filename, $result['sheets'] );
    }

    public function export_dropout_users() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'No tienes permisos suficientes para realizar esta acción.', 'e3-analytics' ) );
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'e3a_export_dropout_users' ) ) {
            wp_die( __( 'Nonce inválido. Recarga la página e intenta de nuevo.', 'e3-analytics' ) );
        }

        $period    = $this->read_period();
        $course_id = isset( $_GET['course_id'] ) ? (int) $_GET['course_id'] : 0;
        $bucket    = isset( $_GET['bucket'] ) ? sanitize_text_field( wp_unslash( $_GET['bucket'] ) ) : '';
        $q         = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

        if ( $course_id <= 0 ) {
            wp_die( __( 'Debes seleccionar un curso para exportar el listado.', 'e3-analytics' ) );
        }

        $service = new DropoutProgressService();
        $report  = $service->get_report([
            'period'        => $period,
            'course_id'     => $course_id,
            'bucket'        => $bucket,
            'q'             => $q,
            'include_users' => true,
        ]);

        $users = (array) ( $report['users'] ?? [] );

        $course_title = get_the_title( $course_id );
        $safe_course  = sanitize_title( $course_title ?: 'curso' );
        $file_name    = 'abandono-' . $safe_course . '-' . date_i18n( 'Y-m-d' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );

        $out = fopen( 'php://output', 'w' );
        if ( $out === false ) {
            wp_die( __( 'No fue posible generar el archivo CSV.', 'e3-analytics' ) );
        }

        fwrite( $out, "\xEF\xBB\xBF" );

        fputcsv( $out, [
            'course_id',
            'course_title',
            'user_id',
            'user_login',
            'display_name',
            'user_email',
            'progress_percent',
            'bucket',
            'bucket_label',
            'enroll_date',
        ] );

        foreach ( $users as $u ) {
            fputcsv( $out, [
                (int) ( $u['course_id'] ?? 0 ),
                (string) ( $u['course_title'] ?? '' ),
                (int) ( $u['user_id'] ?? 0 ),
                (string) ( $u['user_login'] ?? '' ),
                (string) ( $u['display_name'] ?? '' ),
                (string) ( $u['user_email'] ?? '' ),
                (float) ( $u['progress'] ?? 0 ),
                (string) ( $u['bucket'] ?? '' ),
                (string) ( $u['bucket_label'] ?? '' ),
                (string) ( $u['enroll_date'] ?? '' ),
            ] );
        }

        fclose( $out );
        exit;
    }
}
