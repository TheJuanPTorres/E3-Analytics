<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$dates  = $report['dates'] ?? [];
$totals = $report['totals'] ?? [];
$rows   = $report['countries'] ?? [];

$period_key = (string) ( $dates['period_key'] ?? '30' );
$is_all     = (bool) ( $dates['is_all'] ?? false );

$start = (string) ( $dates['current_start'] ?? '' );
$end   = (string) ( $dates['current_end'] ?? '' );

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

$range = $fmt_range( $start, $end );

$dashboard_url = admin_url( 'admin.php?page=e3-analytics-dashboard&period=' . rawurlencode( $period_key ) );
$dropout_url   = admin_url( 'admin.php?page=e3-analytics-dropout-progress&period=' . rawurlencode( $period_key ) );
$country_url   = admin_url( 'admin.php?page=e3-analytics-country-analysis&period=' . rawurlencode( $period_key ) );

$export_base  = admin_url( 'admin-post.php' );
$export_nonce = wp_create_nonce( 'e3a_export_excel' );
$export_url   = add_query_arg(
  [
    'action'   => 'e3a_export_excel',
    'scope'    => 'country',
    'key'      => 'country_summary',
    'period'   => $period_key,
    '_wpnonce' => $export_nonce,
  ],
  $export_base
);

/**
 * URL del botón "Descargar usuarios".
 * - Si existe el handler admin_post_e3a_export_country_users, lo usa.
 * - Si no existe, intenta fallback con export_excel (key=country_users).
 */
$users_export_url = '';
if ( has_action( 'admin_post_e3a_export_country_users' ) ) {
  $users_export_url = add_query_arg(
    [
      'action'   => 'e3a_export_country_users',
      'period'   => $period_key,
      '_wpnonce' => wp_create_nonce( 'e3a_export_country_users' ),
    ],
    $export_base
  );
} else {
  // Fallback: solo funcionará si tu ExportService soporta key=country_users.
  $users_export_url = add_query_arg(
    [
      'action'   => 'e3a_export_excel',
      'scope'    => 'country',
      'key'      => 'country_users',
      'period'   => $period_key,
      '_wpnonce' => wp_create_nonce( 'e3a_export_excel' ),
    ],
    $export_base
  );
}

$users_total  = (int) ( $totals['users_total'] ?? 0 );
$users_known  = (int) ( $totals['users_known'] ?? 0 );
$users_unknown = max( 0, $users_total - $users_known );
$coverage     = (float) ( $totals['coverage_percent'] ?? 0 );

$top10 = array_slice( (array) $rows, 0, 10 );

$flag_map = [
  'México'=>'🇲🇽','Mexico'=>'🇲🇽','Colombia'=>'🇨🇴','Argentina'=>'🇦🇷',
  'España'=>'🇪🇸','Spain'=>'🇪🇸','Perú'=>'🇵🇪','Peru'=>'🇵🇪',
  'Chile'=>'🇨🇱','Venezuela'=>'🇻🇪','Ecuador'=>'🇪🇨','Bolivia'=>'🇧🇴',
  'Uruguay'=>'🇺🇾','Paraguay'=>'🇵🇾','Guatemala'=>'🇬🇹','Costa Rica'=>'🇨🇷',
  'Cuba'=>'🇨🇺','Honduras'=>'🇭🇳','Panamá'=>'🇵🇦','Panama'=>'🇵🇦',
  'El Salvador'=>'🇸🇻','Nicaragua'=>'🇳🇮','Puerto Rico'=>'🇵🇷',
  'República Dominicana'=>'🇩🇴','Dominican Republic'=>'🇩🇴',
  'United States'=>'🇺🇸','Estados Unidos'=>'🇺🇸',
  'Brazil'=>'🇧🇷','Brasil'=>'🇧🇷','Canada'=>'🇨🇦','Canadá'=>'🇨🇦',
  'France'=>'🇫🇷','Francia'=>'🇫🇷','Germany'=>'🇩🇪','Alemania'=>'🇩🇪',
  'Italy'=>'🇮🇹','Italia'=>'🇮🇹','United Kingdom'=>'🇬🇧','Reino Unido'=>'🇬🇧',
  'Portugal'=>'🇵🇹','Japan'=>'🇯🇵','Japón'=>'🇯🇵',
  'China'=>'🇨🇳','Australia'=>'🇦🇺','India'=>'🇮🇳',
];
$get_flag = static function( $c ) use ( $flag_map ) { return $flag_map[$c] ?? ''; };
?>

<div class="wrap e3-shell">
  <div class="e3-canvas">

    <div class="e3-topbar">
      <div class="e3-topbar-left">
        <div class="e3-badge-icon" aria-hidden="true">
          <span class="dashicons dashicons-location"></span>
        </div>
        <div class="e3-topbar-copy">
          <span class="e3-topbar-eyebrow">E3 Analytics</span>
          <h1 class="e3-h1">Análisis por país</h1>
          <p class="e3-lead">
            Distribución y lectura rápida por país usando <code>country_lms</code> y fallback al país del último login (TutorLMS).
          </p>
        </div>
      </div>

      <div class="e3-topbar-right">
        <div class="e3-meta e3-meta--current">
          <div class="e3-meta-label"><?php echo esc_html( $is_all ? 'Histórico completo' : 'Período analizado' ); ?></div>
          <div class="e3-meta-value"><?php echo esc_html( $range ); ?></div>
        </div>

        <div class="e3-meta e3-meta--compare">
          <div class="e3-meta-label">Cobertura país</div>
          <div class="e3-meta-value">
            <?php echo esc_html( $coverage . '%' ); ?>
            <span class="e3-muted">(<?php echo number_format_i18n( $users_known ); ?>/<?php echo number_format_i18n( $users_total ); ?>)</span>
          </div>
        </div>
      </div>
    </div>

    <div class="e3-toolbar">
      <form method="get" class="e3-toolbar-form">
        <input type="hidden" name="page" value="e3-analytics-country-analysis" />

        <div class="e3-field">
          <label class="e3-field-label" for="e3-period">Período</label>
          <select id="e3-period" name="period" class="e3-select">
<?php foreach ( \E3_Analytics\Support\DatePeriod::presets() as $preset_key => $preset_label ) : ?>
            <option value="<?php echo esc_attr( $preset_key ); ?>" <?php selected( $period_key, $preset_key ); ?>><?php echo esc_html( $preset_label ); ?></option>
<?php endforeach; ?>
          </select>
        </div>

        <span class="e3-toolbar-spacer" aria-hidden="true"></span>

        <button type="submit" class="button button-primary e3-btn">Actualizar</button>

        <a href="<?php echo esc_url( $users_export_url ); ?>" class="button e3-btn e3-btn--dark e3-btn--download-users">
          <span class="dashicons dashicons-download" aria-hidden="true"></span>
          Descargar usuarios (Excel)
        </a>
      </form>
    </div>

    <nav class="e3-nav" aria-label="Navegación rápida">
      <a class="e3-nav-item" href="<?php echo esc_url( $dashboard_url ); ?>">
        <span class="dashicons dashicons-chart-area" aria-hidden="true"></span> Dashboard
      </a>
      <a class="e3-nav-item" href="<?php echo esc_url( $dropout_url ); ?>">
        <span class="dashicons dashicons-dismiss" aria-hidden="true"></span> Abandono
      </a>
      <span class="e3-nav-item e3-nav-item--active">
        <span class="dashicons dashicons-location" aria-hidden="true"></span> País
      </span>
    </nav>

    <div class="e3-section">
      <div class="e3-section-head">
        <h2 class="e3-h2"><span class="dashicons dashicons-dashboard" aria-hidden="true"></span> Resumen del período</h2>
        <div class="e3-muted">Lectura rápida de cobertura y universo de usuarios para este rango.</div>
      </div>

      <div class="e3-kpis">
        <div class="e3-kpi">
          <div class="e3-kpi-top">
            <div class="e3-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-admin-users"></span></div>
            <div class="e3-kpi-label">Usuarios (universo)</div>
            <div class="e3-kpi-pill neutral">Total</div>
          </div>
          <div class="e3-kpi-value"><?php echo number_format_i18n( $users_total ); ?></div>
          <div class="e3-kpi-desc">Usuarios detectados dentro del universo del período (registros y/o actividad/inscripción).</div>
        </div>

        <div class="e3-kpi">
          <div class="e3-kpi-top">
            <div class="e3-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-location"></span></div>
            <div class="e3-kpi-label">Con país</div>
            <div class="e3-kpi-pill up">OK</div>
          </div>
          <div class="e3-kpi-value"><?php echo number_format_i18n( $users_known ); ?></div>
          <div class="e3-kpi-desc">Usuarios con país resuelto (country_lms o fallback por último login).</div>
        </div>

        <div class="e3-kpi">
          <div class="e3-kpi-top">
            <div class="e3-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-hidden"></span></div>
            <div class="e3-kpi-label">Sin país</div>
            <div class="e3-kpi-pill down">Pendiente</div>
          </div>
          <div class="e3-kpi-value"><?php echo number_format_i18n( $users_unknown ); ?></div>
          <div class="e3-kpi-desc">Usuarios sin country_lms y sin registro de país en tutor_login_*. Se agrupan como “Desconocido”.</div>
        </div>
      </div>
    </div>

    <div class="e3-country-stack">

      <div class="e3-card">
        <div class="e3-card-head">
          <div>
            <div class="e3-card-title"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span> Top 10 países</div>
            <div class="e3-card-sub">Comparativo de inscripciones vs completados y tabla resumen debajo (no al lado).</div>
          </div>
          <span class="e3-pill"><?php echo esc_html( $coverage . '%' ); ?> cobertura</span>
        </div>

        <?php if ( empty( $top10 ) ) : ?>
          <div class="e3-empty">No hay datos suficientes para construir el Top 10 en este período.</div>
        <?php else : ?>
          <div class="e3-chart e3-chart-wrap" style="height: 320px;">
            <canvas id="e3-country-chart" aria-label="Top 10 países" role="img"></canvas>
          </div>

          <div class="e3-table-wrap e3-country-top10-wrap">
            <table class="widefat striped e3-table e3-country-top10-table">
              <thead>
                <tr>
                  <th>País</th>
                  <th>Inscripciones</th>
                  <th>Completados</th>
                  <th>% Compleción</th>
                  <th>Activos</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ( $top10 as $r ) :
                  $country = (string) ( $r['country'] ?? 'Desconocido' );
                  $enr     = (int) ( $r['enrollments'] ?? 0 );
                  $comp    = (int) ( $r['completed_enrollments'] ?? 0 );
                  $rate    = (float) ( $r['completion_rate'] ?? 0 );
                  $active  = (int) ( $r['active_users'] ?? 0 );
                ?>
                  <tr>
                    <td>
                      <?php $flag = $get_flag( $country ); ?>
                      <?php if ( $flag ) : ?><span aria-hidden="true"><?php echo $flag; ?></span> <?php endif; ?>
                      <strong><?php echo esc_html( $country ); ?></strong>
                    </td>
                    <td><?php echo number_format_i18n( $enr ); ?></td>
                    <td><?php echo number_format_i18n( $comp ); ?></td>
                    <td><?php echo esc_html( $rate . '%' ); ?></td>
                    <td><?php echo number_format_i18n( $active ); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="e3-muted e3-country-note">
            Nota: Si no existe <code>country_lms</code> para un usuario, se usa el país del último login (si existe). Si no hay datos, se agrupa como <strong>Desconocido</strong>.
          </div>
        <?php endif; ?>
      </div>

      <div class="e3-card" data-e3-export-url="<?php echo esc_url( $export_url ); ?>">
        <div class="e3-card-head">
          <div>
            <div class="e3-card-title"><span class="dashicons dashicons-list-view" aria-hidden="true"></span> Distribución por país</div>
            <div class="e3-card-sub">Incluye registros, actividad, inscripciones, completados y recurrencia (inscripción a otro curso).</div>
          </div>
          <span class="e3-pill e3-pill--muted"><?php echo esc_html( count( $rows ) ); ?> países</span>
        </div>

        <div class="e3-country-help">
          <details class="e3-country-defs">
            <summary>¿Qué significa cada columna?</summary>
            <ul>
              <li><strong>País:</strong> valor tomado del meta <code>country_lms</code>. Si está vacío, se intenta inferir desde el país del <em>último login</em> registrado por TutorLMS (meta <code>tutor_login_*</code>). Si no existe información, se agrupa como <strong>Desconocido</strong>.</li>
              <li><strong>Registros:</strong> usuarios creados en WordPress dentro del período (<code>user_registered</code>).</li>
              <li><strong>Nuevos inscritos:</strong> usuarios cuyo <em>primer</em> curso inscrito (histórico) ocurre dentro del período. Se cuenta 1 vez por usuario, aunque se haya inscrito a más de un curso.</li>
              <li><strong>Activos:</strong> usuarios únicos que tienen al menos 1 inscripción creada en el período (pueden tener varias inscripciones).</li>
              <li><strong>Inscripciones:</strong> total de inscripciones creadas en el período (tabla <code>wp_posts</code>, tipo <code>tutor_enrolled</code>). Un mismo usuario puede aportar varias.</li>
              <li><strong>Completados:</strong> inscripciones del período que están marcadas como completadas. Se toma el status de TutorLMS y, si no está disponible, se valida con la verificación robusta de completitud.</li>
              <li><strong>% Compleción:</strong> <code>Completados / Inscripciones</code> (por país) dentro del período.</li>
              <li><strong>Recurrentes:</strong> usuarios que ya estaban inscritos <em>antes</em> del inicio del período y, durante el período, se inscribieron a un <em>curso nuevo</em> (distinto a los cursos que ya tenían). En “Histórico completo” equivale a usuarios con 2+ cursos distintos en su historial.</li>
            </ul>
          </details>
        </div>

        <?php if ( empty( $rows ) ) : ?>
          <div class="e3-empty">No hay datos para mostrar en este período.</div>
        <?php else : ?>
          <div class="e3-table-wrap">
            <table class="e3-table" role="table">
              <thead>
                <tr>
                  <th>
                    <span class="e3-th">País
                      <span class="dashicons dashicons-editor-help e3-th-help" title="País/nacionalidad por usuario. Se usa country_lms; si no existe, se intenta inferir desde el último login (tutor_login_*)." aria-hidden="true"></span>
                    </span>
                  </th>
                  <th>
                    <span class="e3-th">Registros
                      <span class="dashicons dashicons-editor-help e3-th-help" title="Usuarios creados en WordPress en el período (user_registered)." aria-hidden="true"></span>
                    </span>
                  </th>
                  <th>
                    <span class="e3-th">Nuevos inscritos
                      <span class="dashicons dashicons-editor-help e3-th-help" title="Usuarios cuyo primer curso inscrito (histórico) sucede en el período. Se cuenta 1 vez por usuario." aria-hidden="true"></span>
                    </span>
                  </th>
                  <th>
                    <span class="e3-th">Activos
                      <span class="dashicons dashicons-editor-help e3-th-help" title="Usuarios únicos con al menos una inscripción creada en el período." aria-hidden="true"></span>
                    </span>
                  </th>
                  <th>
                    <span class="e3-th">Inscripciones
                      <span class="dashicons dashicons-editor-help e3-th-help" title="Total de inscripciones creadas en el período (tutor_enrolled)." aria-hidden="true"></span>
                    </span>
                  </th>
                  <th>
                    <span class="e3-th">Completados
                      <span class="dashicons dashicons-editor-help e3-th-help" title="Inscripciones del período marcadas como completadas (status TutorLMS + validación robusta)." aria-hidden="true"></span>
                    </span>
                  </th>
                  <th>
                    <span class="e3-th">% Compleción
                      <span class="dashicons dashicons-editor-help e3-th-help" title="Completados / Inscripciones (por país) dentro del período." aria-hidden="true"></span>
                    </span>
                  </th>
                  <th>
                    <span class="e3-th">Recurrentes
                      <span class="dashicons dashicons-editor-help e3-th-help" title="Usuarios que ya estaban inscritos antes del período y se inscriben a un curso nuevo durante el período. En histórico: usuarios con 2+ cursos." aria-hidden="true"></span>
                    </span>
                  </th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ( $rows as $r ) :
                  $country = (string) ( $r['country'] ?? 'Desconocido' );
                  $reg     = (int) ( $r['registered'] ?? 0 );
                  $first   = (int) ( $r['first_time_enrolled'] ?? 0 );
                  $active  = (int) ( $r['active_users'] ?? 0 );
                  $enr     = (int) ( $r['enrollments'] ?? 0 );
                  $comp    = (int) ( $r['completed_enrollments'] ?? 0 );
                  $rate    = (float) ( $r['completion_rate'] ?? 0 );
                  $cross   = (int) ( $r['cross_course_users'] ?? 0 );
                ?>
                  <tr>
                    <td>
                      <?php $flag = $get_flag( $country ); ?>
                      <?php if ( $flag ) : ?><span aria-hidden="true"><?php echo $flag; ?></span> <?php endif; ?>
                      <strong><?php echo esc_html( $country ); ?></strong>
                    </td>
                    <td><?php echo number_format_i18n( $reg ); ?></td>
                    <td><?php echo number_format_i18n( $first ); ?></td>
                    <td><?php echo number_format_i18n( $active ); ?></td>
                    <td><?php echo number_format_i18n( $enr ); ?></td>
                    <td><?php echo number_format_i18n( $comp ); ?></td>
                    <td><?php echo esc_html( $rate . '%' ); ?></td>
                    <td><?php echo number_format_i18n( $cross ); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    </div>

  </div>
</div>
