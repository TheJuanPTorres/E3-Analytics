<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$kpi     = $data['kpis'] ?? [];
$ret     = $data['retention'] ?? [];
$dau_mau = $data['dau_mau'] ?? [];

$period        = (int) ( $data['period'] ?? 30 );
$current_start = (string) ( $data['current_start'] ?? '' );
$current_end   = (string) ( $data['current_end'] ?? '' );
$prev_start    = (string) ( $data['prev_start'] ?? '' );
$prev_end      = (string) ( $data['prev_end'] ?? '' );

// Sin fallback al entero: con un rango custom, period_int es la duración real
// (46, por ejemplo) y emitir period=46 en una URL cae al default en silencio.
// Si period_key falta, es un defecto de DatePeriod, no algo que parchear acá.
$period_key    = (string) ( $data['period_key'] ?? '' );
$is_all        = (bool) ( $data['is_all'] ?? false );
$has_compare   = ( $prev_start !== '' && $prev_end !== '' );

$fmt_date = function( $mysql ) {
  if ( ! $mysql ) return '';
  $ts = strtotime( $mysql );
  if ( ! $ts ) return (string) $mysql;
  return date_i18n( get_option( 'date_format' ), $ts );
};
$fmt_range = function( $start, $end ) use ( $fmt_date ) {
  $a = $fmt_date( $start );
  $b = $fmt_date( $end );
  if ( $a === '' && $b === '' ) return '';
  if ( $a === '' ) return $b;
  if ( $b === '' ) return $a;
  return $a . ' – ' . $b;
};

$current_range = $fmt_range( $current_start, $current_end );
$prev_range    = $fmt_range( $prev_start, $prev_end );


$active_users       = (int) ( $kpi['active_users'] ?? 0 );
$current_enrolls    = (int) ( $kpi['current_enrollments'] ?? 0 );
$current_completed  = (int) ( $kpi['current_completed'] ?? 0 );
$completion_rate    = (float) ( $kpi['completion_rate'] ?? 0 );
$dropout_rate       = (float) ( $kpi['dropout_rate'] ?? 0 );
$activity_rate      = (float) ( $kpi['activity_rate'] ?? 0 );

$ret_base = (int) ( $ret['base'] ?? 0 );

$ret_windows = [
  [ 'key' => '7',   'label' => '7 días' ],
  [ 'key' => '14',  'label' => '14 días' ],
  [ 'key' => '30',  'label' => '30 días' ],
  [ 'key' => '60',  'label' => '60 días' ],
  [ 'key' => '90',  'label' => '90 días' ],
  [ 'key' => '180', 'label' => '180 días' ],
  [ 'key' => '365', 'label' => '365 días' ],
  [ 'key' => 'all', 'label' => 'Histórico' ],
];

$courses_stats = $data['courses_stats'] ?? [];
$top_courses   = array_slice( $courses_stats, 0, 5 );

$dashboard_url = admin_url( 'admin.php?page=e3-analytics-dashboard&period=' . rawurlencode( $period_key ) );
$dropout_url   = admin_url( 'admin.php?page=e3-analytics-dropout-progress&period=' . rawurlencode( $period_key ) );
$country_url   = admin_url( 'admin.php?page=e3-analytics-country-analysis&period=' . rawurlencode( $period_key ) );

$export_base  = admin_url( 'admin-post.php' );
$export_nonce = wp_create_nonce( 'e3a_export_excel' );
$export_url   = function( $key ) use ( $export_base, $export_nonce, $period_key ) {
  return add_query_arg(
    [
      'action'  => 'e3a_export_excel',
      'scope'   => 'dashboard',
      'key'     => $key,
      'period'  => $period_key,
      '_wpnonce'=> $export_nonce,
    ],
    $export_base
  );
};
$course_post_type = 'courses';
if ( function_exists( 'post_type_exists' ) ) {
  if ( post_type_exists( 'tutor_course' ) ) {
    $course_post_type = 'tutor_course';
  } elseif ( post_type_exists( 'courses' ) ) {
    $course_post_type = 'courses';
  } elseif ( post_type_exists( 'course' ) ) {
    $course_post_type = 'course';
  }
}
$courses_url   = admin_url( 'edit.php?post_type=' . $course_post_type );
$users_url     = admin_url( 'users.php' );

$chart_labels = $data['chart']['labels'] ?? [];
$chart_first  = $data['chart']['first'] ?? [];
$chart_return = $data['chart']['returning'] ?? [];

$ret_30_rate  = (float) ( $ret['30']['rate'] ?? 0 );
$health_score = (int) round(
    ($activity_rate   * 0.30) +
    ($completion_rate * 0.40) +
    ($ret_30_rate     * 0.30)
);
$health_score = max(0, min(100, $health_score));

if ($health_score >= 70)     { $health_label = 'Bueno';   $health_cls = 'good'; }
elseif ($health_score >= 40) { $health_label = 'Regular'; $health_cls = 'mid';  }
else                          { $health_label = 'Crítico'; $health_cls = 'low';  }

$radius        = 30;
$circumference = round(2 * M_PI * $radius, 2);
$dash_filled   = round(($health_score / 100) * $circumference, 2);
$dash_empty    = round($circumference - $dash_filled, 2);
?>

<div class="wrap e3-shell">
  <div class="e3-canvas">

    <div class="e3-topbar">
      <div class="e3-topbar-left">
        <div class="e3-badge-icon" aria-hidden="true">
          <span class="dashicons dashicons-chart-area"></span>
        </div>
        <div class="e3-topbar-copy">
          <span class="e3-topbar-eyebrow">E3 Analytics</span>
          <h1 class="e3-h1">Panel de analítica LMS</h1>
          <p class="e3-lead">
            Registros, inscripciones, finalización y retención del período seleccionado.
          </p>
        </div>
      </div>

      <div class="e3-topbar-right">
        <div class="e3-meta e3-meta--current">
          <div class="e3-meta-label">Período analizado</div>
          <div class="e3-meta-value"><?php echo esc_html( $current_range ); ?></div>
          <?php if ( $has_compare ): ?>
            <div class="e3-meta-label" style="margin-top:6px;">Comparación</div>
            <div class="e3-meta-value"><?php echo esc_html( $prev_range ); ?></div>
          <?php endif; ?>
        </div>

        <div class="e3-health e3-health--<?php echo esc_attr( $health_cls ); ?>">
          <svg class="e3-health-ring" viewBox="0 0 72 72" aria-hidden="true">
            <circle cx="36" cy="36" r="<?php echo $radius; ?>" class="e3-health-track"/>
            <circle cx="36" cy="36" r="<?php echo $radius; ?>" class="e3-health-fill"
              stroke-dasharray="<?php echo $dash_filled; ?> <?php echo $dash_empty; ?>"/>
          </svg>
          <div class="e3-health-body">
            <div class="e3-health-value"><?php echo $health_score; ?></div>
            <div class="e3-health-label">Salud LMS</div>
            <div class="e3-health-grade"><?php echo esc_html( $health_label ); ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="e3-toolbar">
      <form method="get" class="e3-toolbar-form">
        <input type="hidden" name="page" value="e3-analytics-dashboard" />

        <div class="e3-field">
          <label class="e3-field-label" for="e3-period">Período</label>
          <select id="e3-period" name="period" class="e3-select">
            <option value="7"   <?php selected( $period_key, '7' ); ?>>Últimos 7 días</option>
            <option value="30"  <?php selected( $period_key, '30' ); ?>>Últimos 30 días</option>
            <option value="90"  <?php selected( $period_key, '90' ); ?>>Últimos 90 días</option>
            <option value="365" <?php selected( $period_key, '365' ); ?>>Últimos 12 meses</option>
            <option value="all" <?php selected( $period_key, 'all' ); ?>>Histórico completo</option>
          </select>
        </div>

        <button type="submit" class="button button-primary e3-btn">Actualizar</button>
      </form>
    </div>

    <nav class="e3-nav" aria-label="Navegación rápida">
      <a class="e3-nav-item e3-nav-item--active" href="<?php echo esc_url( $dashboard_url ); ?>">
        <span class="dashicons dashicons-chart-area" aria-hidden="true"></span> Dashboard
      </a>
      <a class="e3-nav-item" href="<?php echo esc_url( $dropout_url ); ?>">
        <span class="dashicons dashicons-dismiss" aria-hidden="true"></span> Abandono
      </a>
      <a class="e3-nav-item" href="<?php echo esc_url( $country_url ); ?>">
        <span class="dashicons dashicons-location" aria-hidden="true"></span> País
      </a>
      <a class="e3-nav-item" href="<?php echo esc_url( $courses_url ); ?>">
        <span class="dashicons dashicons-welcome-learn-more" aria-hidden="true"></span> Cursos
      </a>
      <a class="e3-nav-item" href="<?php echo esc_url( $users_url ); ?>">
        <span class="dashicons dashicons-admin-users" aria-hidden="true"></span> Usuarios
      </a>
    </nav>

    <?php if ( $dropout_rate > 60 ) : ?>
      <div class="e3-notice e3-notice--warn" role="alert">
        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
        <div>
          <strong>Tasa de abandono elevada (<?php echo esc_html( $dropout_rate ); ?>%)</strong> —
          Más del 60% de las inscripciones no se completaron en este período.
          <a href="<?php echo esc_url( $dropout_url ); ?>">Ver detalle →</a>
        </div>
      </div>
    <?php endif; ?>

    <div class="e3-section">
      <div class="e3-section-head">
        <h2 class="e3-h2"><span class="dashicons dashicons-dashboard" aria-hidden="true"></span> Resumen del período</h2>
        <div class="e3-muted">Métricas principales del período seleccionado.</div>
      </div>

      <div class="e3-kpis">
        <div class="e3-kpi e3-kpi--wide" data-e3-export-url="<?php echo esc_url( $export_url('new_users') ); ?>">
          <div class="e3-kpi-top">
            <div class="e3-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-admin-users"></span></div>
            <div class="e3-kpi-label">Nuevos registros</div>
            <div class="e3-kpi-pill <?php
              if ( ! $has_compare ) {
                echo 'neutral';
              } else {
                $g = (float) ( $kpi['growth_new_users'] ?? 0 );
                echo ( $g >= 0 ) ? 'up' : 'down';
              }
            ?>">
              <?php
              if ( ! $has_compare ) {
                echo esc_html( '—' );
              } else {
                $g = (float) ( $kpi['growth_new_users'] ?? 0 );
                echo esc_html( ( $g >= 0 ? '+' : '' ) . $g . '%' );
              }
              ?>
            </div>
          </div>
          <div class="e3-kpi-value"><?php echo number_format_i18n( (int) ( $kpi['current_new_users'] ?? 0 ) ); ?></div>
          <div class="e3-kpi-desc">Usuarios que se registraron por primera vez durante el período.</div>
        </div>

        <div class="e3-kpi e3-kpi--wide" data-e3-export-url="<?php echo esc_url( $export_url('first_time_enrollments') ); ?>">
          <div class="e3-kpi-top">
            <div class="e3-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-welcome-learn-more"></span></div>
            <div class="e3-kpi-label">Nuevos inscritos en cursos</div>
            <div class="e3-kpi-pill <?php
              if ( ! $has_compare ) {
                echo 'neutral';
              } else {
                $g = (float) ( $kpi['growth_first_time_enrollments'] ?? 0 );
                echo ( $g >= 0 ) ? 'up' : 'down';
              }
            ?>">
              <?php
              if ( ! $has_compare ) {
                echo esc_html( '—' );
              } else {
                $g = (float) ( $kpi['growth_first_time_enrollments'] ?? 0 );
                echo esc_html( ( $g >= 0 ? '+' : '' ) . $g . '%' );
              }
              ?>
            </div>
          </div>
          <div class="e3-kpi-value"><?php echo number_format_i18n( (int) ( $kpi['current_first_time_enrollments'] ?? 0 ) ); ?></div>
          <div class="e3-kpi-desc">Usuarios cuya primera matrícula global ocurrió en este período.</div>
        </div>

        <div class="e3-kpi" data-e3-export-url="<?php echo esc_url( $export_url('enrollments') ); ?>">
          <div class="e3-kpi-top">
            <div class="e3-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-forms"></span></div>
            <div class="e3-kpi-label">Inscripciones</div>
            <div class="e3-kpi-pill <?php
              if ( ! $has_compare ) {
                echo 'neutral';
              } else {
                $g = (float) ( $kpi['growth_enrollments'] ?? 0 );
                echo ( $g >= 0 ) ? 'up' : 'down';
              }
            ?>">
              <?php
              if ( ! $has_compare ) {
                echo esc_html( '—' );
              } else {
                $g = (float) ( $kpi['growth_enrollments'] ?? 0 );
                echo esc_html( ( $g >= 0 ? '+' : '' ) . $g . '%' );
              }
              ?>
            </div>
          </div>
          <div class="e3-kpi-value"><?php echo number_format_i18n( (int) ( $kpi['current_enrollments'] ?? 0 ) ); ?></div>
          <div class="e3-kpi-desc">Total de matrículas registradas en cursos durante el período.</div>
        </div>

        
        <div class="e3-kpi" data-e3-export-url="<?php echo esc_url( $export_url('cross_course_users') ); ?>">
          <div class="e3-kpi-top">
            <div class="e3-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-randomize"></span></div>
            <div class="e3-kpi-label">Inscritos a otro curso</div>
          </div>
          <div class="e3-kpi-value"><?php echo number_format_i18n( (int) ( $kpi['cross_course_users'] ?? 0 ) ); ?></div>
          <div class="e3-kpi-desc">Usuarios ya inscritos previamente que iniciaron al menos un curso nuevo para ellos durante el período.</div>
        </div>

<div class="e3-kpi" data-e3-export-url="<?php echo esc_url( $export_url('active_users') ); ?>">
          <div class="e3-kpi-top">
            <div class="e3-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-visibility"></span></div>
            <div class="e3-kpi-label">Usuarios activos</div>
          </div>
          <div class="e3-kpi-value"><?php echo number_format_i18n( $active_users ); ?></div>
          <div class="e3-kpi-desc">Usuarios con al menos una matrícula en el período.</div>
        </div>

        <div class="e3-kpi" data-e3-export-url="<?php echo esc_url( $export_url('performance') ); ?>">
          <div class="e3-kpi-top">
            <div class="e3-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-awards"></span></div>
            <div class="e3-kpi-label">Rendimiento</div>
          </div>
          <div class="e3-kpi-value"><?php echo esc_html( $kpi['performance'] ?? 0 ); ?></div>
          <div class="e3-kpi-desc">Cursos completados promedio por usuario activo.</div>
        </div>

        <div class="e3-kpi">
          <div class="e3-kpi-top">
            <div class="e3-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-migrate"></span></div>
            <div class="e3-kpi-label">Tasa de actividad</div>
          </div>
          <div class="e3-kpi-value"><?php echo esc_html( $activity_rate ); ?>%</div>
          <div class="e3-kpi-desc">Porcentaje de nuevos registros que se inscribieron a al menos un curso durante el período.</div>
        </div>
      </div>
    </div>

    <div class="e3-grid-2">
      <div class="e3-card" data-e3-export-url="<?php echo esc_url( $export_url('chart_new_vs_returning') ); ?>">
        <div class="e3-card-head">
          <div>
            <div class="e3-card-title"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span> Nuevos vs recurrentes por curso</div>
            <div class="e3-card-sub">Top 5 cursos con más inscripciones en el período.</div>
          </div>
          <div class="e3-tag">Top 5</div>
        </div>

        <?php if ( ! empty( $chart_labels ) ) : ?>
          <div class="e3-chart" style="height: 260px;"><canvas id="e3-course-enrollments-chart"></canvas></div>
        <?php else : ?>
          <div class="e3-empty">Aún no hay suficientes inscripciones para generar este gráfico.</div>
        <?php endif; ?>
      </div>

      <div class="e3-card" data-e3-export-url="<?php echo esc_url( $export_url('insights') ); ?>">
        <div class="e3-card-head">
          <div>
            <div class="e3-card-title"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span> Insights</div>
            <div class="e3-card-sub">Lectura rápida del estado del período.</div>
          </div>
          <div class="e3-tag">Resumen</div>
        </div>

        <div class="e3-insights">
          <div class="e3-insight">
            <div class="e3-insight-icon" aria-hidden="true"><span class="dashicons dashicons-yes-alt"></span></div>
            <div class="e3-insight-body">
              <div class="e3-insight-title">Finalización</div>
              <div class="e3-insight-sub">Completados: <?php echo number_format_i18n( $current_completed ); ?></div>
            </div>
            <div class="e3-insight-metric">
              <div class="e3-insight-value"><?php echo esc_html( $completion_rate ); ?>%</div>
              <div class="e3-insight-small">del total</div>
            </div>
          </div>

          <div class="e3-insight">
            <div class="e3-insight-icon" aria-hidden="true"><span class="dashicons dashicons-dismiss"></span></div>
            <div class="e3-insight-body">
              <div class="e3-insight-title">Abandono</div>
              <div class="e3-insight-sub">No completado</div>
            </div>
            <div class="e3-insight-metric">
              <div class="e3-insight-value"><?php echo esc_html( $dropout_rate ); ?>%</div>
              <div class="e3-insight-small">estimado</div>
            </div>
          </div>

          <div class="e3-insight">
            <div class="e3-insight-icon" aria-hidden="true"><span class="dashicons dashicons-chart-line"></span></div>
            <div class="e3-insight-body">
              <div class="e3-insight-title">DAU/MAU</div>
              <div class="e3-insight-sub">Engagement reciente</div>
            </div>
            <div class="e3-insight-metric">
              <div class="e3-insight-value"><?php echo esc_html( $dau_mau['ratio'] ?? 0 ); ?>%</div>
              <div class="e3-insight-small"><?php echo number_format_i18n( (int) ( $dau_mau['dau'] ?? 0 ) ); ?> / <?php echo number_format_i18n( (int) ( $dau_mau['mau'] ?? 0 ) ); ?></div>
            </div>
          </div>

          <?php
          $progress_value = 0;
          if ( $current_enrolls > 0 ) {
            $progress_value = max( 0, min( 100, (float) $completion_rate ) );
          }
          ?>
          <div class="e3-meter" role="progressbar" aria-valuenow="<?php echo esc_attr( $progress_value ); ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="e3-meter-fill" style="width: <?php echo esc_attr( $progress_value ); ?>%"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="e3-grid-2">
      <div class="e3-card" data-e3-export-url="<?php echo esc_url( $export_url('retention') ); ?>">
        <div class="e3-card-head">
          <div>
            <div class="e3-card-title"><span class="dashicons dashicons-backup" aria-hidden="true"></span> Retención (7 días → Histórico)</div>
            <div class="e3-card-sub">Usuarios registrados en el período seleccionado con actividad reciente.</div>
          </div>
          <div class="e3-tag">Cohortes</div>
        </div>

        <div class="e3-retention-meta">
          <span class="e3-retention-meta-label">Base:</span>
          <strong><?php echo number_format_i18n( (int) $ret_base ); ?></strong>
          <span class="e3-retention-meta-sublabel">usuarios registrados (<?php echo esc_html( $current_range ); ?>)</span>
        </div>

        <div class="e3-timeline" role="list">
          <?php foreach ( $ret_windows as $w ) :
            $key   = (string) ($w['key'] ?? '');
            $label = (string) ($w['label'] ?? $key);
            $r     = (array) ($ret[$key] ?? ['rate'=>0,'active'=>0,'total'=>$ret_base]);
            $rate  = (float) ($r['rate'] ?? 0);
            $bar_h = max(4, (int) $rate);
          ?>
          <div class="e3-timeline-step" role="listitem">
            <div class="e3-timeline-label"><?php echo esc_html( $label ); ?></div>
            <div class="e3-timeline-bar-wrap">
              <div class="e3-timeline-bar" style="height:<?php echo $bar_h; ?>%"></div>
            </div>
            <div class="e3-timeline-rate"><?php echo esc_html( $rate ); ?>%</div>
            <div class="e3-timeline-sub"><?php echo number_format_i18n( (int)($r['active']??0) ); ?> activos</div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>



      <div class="e3-card" data-e3-export-url="<?php echo esc_url( $export_url('top_courses') ); ?>">
        <div class="e3-card-head">
          <div>
            <div class="e3-card-title"><span class="dashicons dashicons-star-filled" aria-hidden="true"></span> Top cursos</div>
            <div class="e3-card-sub">Los cursos más activos del período (por inscripciones).</div>
          </div>
          <div class="e3-tag">Top 5</div>
        </div>

        <?php if ( ! empty( $top_courses ) ) : ?>
          <div class="e3-list">
            <?php foreach ( $top_courses as $row ) : ?>
              <div class="e3-item">
                <div class="e3-item-icon" aria-hidden="true"><span class="dashicons dashicons-welcome-learn-more"></span></div>
                <div class="e3-item-body">
                  <div class="e3-item-title"><?php echo esc_html( $row->course_title ); ?></div>
                  <div class="e3-item-sub">Finalización: <?php echo esc_html( $row->completion_rate ); ?>%</div>
                </div>
                <div class="e3-item-metric">
                  <div class="e3-item-value"><?php echo number_format_i18n( (int) $row->current_enroll ); ?></div>
                  <div class="e3-item-small">inscripciones</div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else : ?>
          <div class="e3-empty">Aún no hay cursos con inscripciones en este período.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="e3-card e3-table-card" data-e3-export-url="<?php echo esc_url( $export_url('courses_detail') ); ?>">
      <div class="e3-card-head">
        <div>
          <div class="e3-card-title"><span class="dashicons dashicons-editor-table" aria-hidden="true"></span> Detalle por curso</div>
          <div class="e3-card-sub">Hasta 20 cursos con mayor número de inscripciones, con tasa de finalización y variación.</div>
        </div>
      </div>

      <div class="e3-table-wrap">
        <table class="e3-table">
          <thead>
            <tr>
              <th>Curso</th>
              <th>Inscripciones período</th>
              <th>Nuevos (curso)</th>
              <th>Recurrentes</th>
              <th>Período anterior</th>
              <th>Variación</th>
              <th>Completados</th>
              <th>Tasa finalización</th>
            </tr>
          </thead>
          <tbody>
            <?php if ( ! empty( $courses_stats ) ) : ?>
              <?php foreach ( $courses_stats as $row ) : ?>
                <tr>
                  <td class="e3-course-name"><?php echo esc_html( $row->course_title ); ?></td>
                  <td><?php echo number_format_i18n( (int) $row->current_enroll ); ?></td>
                  <td><?php echo number_format_i18n( (int) $row->current_first_time_enroll ); ?></td>
                  <td><?php echo number_format_i18n( (int) $row->current_returning_enroll ); ?></td>
                  <td><?php echo $has_compare ? number_format_i18n( (int) $row->previous_enroll ) : '—'; ?></td>
                  <td>
                    <?php
                    if ( ! $has_compare ) {
                      $g = null;
                      $cls = 'neutral';
                      $txt = '—';
                    } else {
                      $g = (float) ( $row->growth_enrollments ?? 0 );
                      $cls = ( $g >= 0 ) ? 'up' : 'down';
                      $txt = ( $g >= 0 ? '+' : '' ) . $g . '%';
                    }
                    ?>
                    <span class="e3-pill <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $txt ); ?></span>
                  </td>
                  <td><?php echo number_format_i18n( (int) $row->current_completed ); ?></td>
                  <td><?php echo esc_html( $row->completion_rate ); ?>%</td>
                </tr>
              <?php endforeach; ?>
            <?php else : ?>
              <tr>
                <td colspan="8" class="e3-empty-td">No hay datos de inscripciones para el período seleccionado.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>
