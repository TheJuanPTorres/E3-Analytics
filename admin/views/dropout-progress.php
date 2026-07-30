<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$dates   = $report['dates'] ?? [];
$period  = (int) ( $dates['period_int'] ?? 30 );

$period_key = (string) ( $dates['period_key'] ?? (string) $period );
$is_all     = (bool) ( $dates['is_all'] ?? false );

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

$current_start = (string) ( $dates['current_start'] ?? '' );
$current_end   = (string) ( $dates['current_end'] ?? '' );

$current_range = $fmt_range( $current_start, $current_end );

$summary = $report['summary'] ?? [];
$courses = $report['courses'] ?? [];
$buckets = $report['buckets'] ?? [];

$filters = $report['filters'] ?? [];
$users   = $report['users'] ?? [];

$selected_course_id = (int) ( $filters['course_id'] ?? 0 );
$selected_bucket    = (string) ( $filters['bucket'] ?? '' );
$selected_q         = (string) ( $filters['q'] ?? '' );

$dashboard_url = admin_url( 'admin.php?page=e3-analytics-dashboard&period=' . rawurlencode( $period_key ) );
$dropout_url   = admin_url( 'admin.php?page=e3-analytics-dropout-progress&period=' . rawurlencode( $period_key ) );
$country_url   = admin_url( 'admin.php?page=e3-analytics-country-analysis&period=' . rawurlencode( $period_key ) );

$export_excel_base  = admin_url( 'admin-post.php' );
$export_excel_nonce = wp_create_nonce( 'e3a_export_excel' );
$export_excel_url   = function( $key, $extra = [] ) use ( $export_excel_base, $export_excel_nonce, $period_key, $selected_course_id, $selected_bucket, $selected_q ) {
  $params = array_merge(
    [
      'action'    => 'e3a_export_excel',
      'scope'     => 'dropout',
      'key'       => $key,
      'period'    => $period_key,
      'course_id' => $selected_course_id,
      'bucket'    => $selected_bucket,
      'q'         => $selected_q,
      '_wpnonce'  => $export_excel_nonce,
    ],
    is_array( $extra ) ? $extra : []
  );

  return add_query_arg( $params, $export_excel_base );
};

$export_url = '';
if ( $selected_course_id > 0 ) {
  $export_url = add_query_arg(
    [
      'action'    => 'e3a_export_dropout_users',
      'period'    => $period_key,
      'course_id' => $selected_course_id,
      'bucket'    => $selected_bucket,
      'q'         => $selected_q,
      '_wpnonce'  => wp_create_nonce( 'e3a_export_dropout_users' ),
    ],
    admin_url( 'admin-post.php' )
  );
}
?>

<div class="wrap e3-shell">
  <div class="e3-canvas">

    <div class="e3-topbar">
      <div class="e3-topbar-left">
        <div class="e3-badge-icon" aria-hidden="true">
          <span class="dashicons dashicons-dismiss"></span>
        </div>
        <div class="e3-topbar-copy">
          <span class="e3-topbar-eyebrow">E3 Analytics</span>
          <h1 class="e3-h1">Avance en abandono</h1>
          <p class="e3-lead">
            Usuarios no completados y su avance promedio, desglosado por rangos de porcentaje en cada curso.
          </p>
        </div>
      </div>
      <div class="e3-topbar-right">
        <div class="e3-meta e3-meta--current">
          <div class="e3-meta-label"><?php echo esc_html( $is_all ? 'Histórico completo' : 'Período analizado' ); ?></div>
          <div class="e3-meta-value"><?php echo esc_html( $current_range ); ?></div>
        </div>
        <div class="e3-meta e3-meta--compare">
          <div class="e3-meta-label">Total en abandono</div>
          <div class="e3-meta-value"><?php echo number_format_i18n( (int)( $summary['total_dropouts'] ?? 0 ) ); ?></div>
        </div>
      </div>
    </div>

    <div class="e3-toolbar">
      <form method="get" class="e3-toolbar-form">
        <input type="hidden" name="page" value="e3-analytics-dropout-progress" />

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
      <a class="e3-nav-item" href="<?php echo esc_url( $dashboard_url ); ?>">
        <span class="dashicons dashicons-chart-area" aria-hidden="true"></span> Dashboard
      </a>
      <span class="e3-nav-item e3-nav-item--active">
        <span class="dashicons dashicons-dismiss" aria-hidden="true"></span> Abandono
      </span>
      <a class="e3-nav-item" href="<?php echo esc_url( $country_url ); ?>">
        <span class="dashicons dashicons-location" aria-hidden="true"></span> País
      </a>
    </nav>

    <div class="e3-section">
      <div class="e3-section-head">
        <h2 class="e3-h2"><span class="dashicons dashicons-welcome-write-blog" aria-hidden="true"></span> Resumen de abandono</h2>
        <div class="e3-muted">Desglose del período seleccionado.</div>
      </div>

      <div class="e3-kpis">
        <div class="e3-kpi" data-e3-export-url="<?php echo esc_url( $export_excel_url('dropout_total_users', ['course_id'=>0,'bucket'=>'','q'=>''] ) ); ?>">
          <div class="e3-kpi-top">
            <div class="e3-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-dismiss"></span></div>
            <div class="e3-kpi-label">Usuarios en abandono</div>
          </div>
          <div class="e3-kpi-value"><?php echo number_format_i18n( (int) ( $summary['total_dropouts'] ?? 0 ) ); ?></div>
          <div class="e3-kpi-desc">Usuarios no completados (deduplicados por curso/usuario) en el período.</div>
        </div>

        <div class="e3-kpi" data-e3-export-url="<?php echo esc_url( $export_excel_url('dropout_total_users', ['course_id'=>0,'bucket'=>'','q'=>''] ) ); ?>">
          <div class="e3-kpi-top">
            <div class="e3-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-chart-area"></span></div>
            <div class="e3-kpi-label">Promedio de avance</div>
          </div>
          <div class="e3-kpi-value"><?php echo esc_html( (float) ( $summary['avg_progress'] ?? 0 ) ); ?>%</div>
          <div class="e3-kpi-desc">Promedio de avance (%) de usuarios no completados.</div>
        </div>

        <div class="e3-kpi" data-e3-export-url="<?php echo esc_url( $export_excel_url('dropout_total_users', ['course_id'=>0,'bucket'=>'','q'=>''] ) ); ?>">
          <div class="e3-kpi-top">
            <div class="e3-kpi-icon" aria-hidden="true"><span class="dashicons dashicons-welcome-learn-more"></span></div>
            <div class="e3-kpi-label">Cursos con abandono</div>
          </div>
          <div class="e3-kpi-value"><?php echo number_format_i18n( (int) ( $summary['courses_count'] ?? 0 ) ); ?></div>
          <div class="e3-kpi-desc">Cursos con al menos un usuario no completado en el período.</div>
        </div>
      </div>
    </div>

    <div class="e3-card" data-e3-export-url="<?php echo esc_url( $export_excel_url('dropout_distribution', ['course_id'=>0,'bucket'=>'','q'=>''] ) ); ?>">
      <div class="e3-card-head">
        <div>
          <div class="e3-card-title"><span class="dashicons dashicons-chart-pie" aria-hidden="true"></span> Distribución por rangos (Top 10 cursos)</div>
          <div class="e3-card-sub">Usuarios en abandono por rangos de avance (%), apilado por curso.</div>
        </div>
        <div class="e3-tag">Top 10</div>
      </div>

      <?php if ( ! empty( $report['chart']['labels'] ?? [] ) ) : ?>
        <div class="e3-chart" style="height: 320px;"><canvas id="e3-dropout-progress-chart"></canvas></div>
      <?php else : ?>
        <div class="e3-empty">No hay suficientes datos para construir el gráfico en este período.</div>
      <?php endif; ?>
    </div>

    <div class="e3-card" data-e3-export-url="<?php echo esc_url( $export_excel_url('dropout_course_detail', ['course_id'=>0,'bucket'=>'','q'=>''] ) ); ?>">
      <div class="e3-card-head">
        <div>
          <div class="e3-card-title"><span class="dashicons dashicons-list-view" aria-hidden="true"></span> Detalle por curso</div>
          <div class="e3-card-sub">Usuarios en abandono, promedio de avance y distribución por rangos.</div>
        </div>
      </div>

      <div class="e3-table-wrap">
        <table class="widefat striped e3-table">
          <thead>
            <tr>
              <th class="e3-th-course">Curso</th>
              <th>Usuarios en abandono</th>
              <th>Promedio avance</th>
              <?php foreach ( $buckets as $bucket_key => $bucket ) : ?>
                <th><?php echo esc_html( $bucket['label'] ?? '' ); ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php if ( ! empty( $courses ) ) : ?>
              <?php foreach ( $courses as $row ) : ?>
                <?php
                  $dropouts = (int) ( $row['dropouts'] ?? 0 );
                  $avg      = (float) ( $row['avg_progress'] ?? 0 );
                  $counts   = (array) ( $row['buckets'] ?? [] );
                ?>
                <tr>
                  <td class="e3-td-course"><?php echo esc_html( $row['course_title'] ?? '' ); ?></td>
                  <td><?php echo number_format_i18n( $dropouts ); ?></td>
                  <td><?php echo esc_html( $avg ); ?>%</td>

                  <?php foreach ( $buckets as $bucket_key => $bucket ) : ?>
                    <?php
                      $key = '';
                      if ( is_string( $bucket_key ) && $bucket_key !== '' ) {
                        $key = $bucket_key;
                      } elseif ( ! empty( $bucket['key'] ) ) {
                        $key = (string) $bucket['key'];
                      }

                      $count = ( $key !== '' ) ? (int) ( $counts[ $key ] ?? 0 ) : 0;
                      $pct   = ( $dropouts > 0 ) ? round( ( $count / $dropouts ) * 100, 1 ) : 0;
                    ?>
                    <td class="e3-td-bucket">
                      <div class="e3-bucket-pill">
                        <div class="e3-bucket-n"><?php echo number_format_i18n( $count ); ?></div>
                        <div class="e3-bucket-p"><?php echo esc_html( $pct ); ?>%</div>
                      </div>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            <?php else : ?>
              <tr><td colspan="<?php echo (int) ( 3 + count( $buckets ) ); ?>">No hay datos para este período.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="e3-card" data-e3-export-url="<?php echo esc_url( $export_excel_url('dropout_user_list') ); ?>">
      <div class="e3-card-head">
        <div>
          <div class="e3-card-title"><span class="dashicons dashicons-groups" aria-hidden="true"></span> Listado de usuarios en abandono</div>
          <div class="e3-card-sub">Selecciona un curso (y opcionalmente un rango) para ver los usuarios no completados. Desde aquí podrás descargar el CSV.</div>
        </div>
        <?php if ( $selected_course_id > 0 && $export_url ) : ?>
          <a class="e3-tag" href="<?php echo esc_url( $export_url ); ?>">Descargar CSV</a>
        <?php endif; ?>
      </div>

      <div class="e3-toolbar" style="margin-top:0;">
        <form method="get" class="e3-toolbar-form">
          <input type="hidden" name="page" value="e3-analytics-dropout-progress" />
          <input type="hidden" name="period" value="<?php echo esc_attr( $period_key ); ?>" />

          <div class="e3-field" style="min-width:260px;">
            <label class="e3-field-label" for="e3-course">Curso</label>
            <select id="e3-course" name="course_id" class="e3-select">
              <option value="0">Selecciona un curso…</option>
              <?php foreach ( $courses as $row ) : ?>
                <?php $cid = (int) ( $row['course_id'] ?? 0 ); ?>
                <?php if ( $cid <= 0 ) continue; ?>
                <option value="<?php echo esc_attr( $cid ); ?>" <?php selected( $selected_course_id, $cid ); ?>>
                  <?php echo esc_html( $row['course_title'] ?? ('Curso #' . $cid) ); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="e3-field" style="min-width:160px;">
            <label class="e3-field-label" for="e3-bucket">Rango</label>
            <select id="e3-bucket" name="bucket" class="e3-select">
              <option value="">Todos</option>
              <?php foreach ( $buckets as $k => $meta ) : ?>
                <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $selected_bucket, (string) $k ); ?>><?php echo esc_html( $meta['label'] ?? '' ); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="e3-field" style="min-width:220px;">
            <label class="e3-field-label" for="e3-q">Buscar</label>
            <input id="e3-q" name="q" class="e3-input" type="text" value="<?php echo esc_attr( $selected_q ); ?>" placeholder="Nombre, email o usuario" />
          </div>

          <button type="submit" class="button button-primary e3-btn">Ver listado</button>
        </form>
      </div>

      <?php if ( $selected_course_id <= 0 ) : ?>
        <div class="e3-empty">Selecciona un curso para mostrar el listado de usuarios en abandono.</div>
      <?php else : ?>
        <div class="e3-table-wrap">
          <table class="widefat striped e3-table">
            <thead>
              <tr>
                <th>Usuario</th>
                <th>Email</th>
                <th>Avance</th>
                <th>Rango</th>
                <th>Inscripción</th>
              </tr>
            </thead>
            <tbody>
              <?php if ( ! empty( $users ) ) : ?>
                <?php foreach ( $users as $u ) : ?>
                  <tr>
                    <td>
                      <div style="display:flex;flex-direction:column;gap:2px;">
                        <strong><?php echo esc_html( $u['display_name'] ?? '' ); ?></strong>
                        <span class="e3-muted" style="font-size:12px;">ID: <?php echo (int) ( $u['user_id'] ?? 0 ); ?> · <?php echo esc_html( $u['user_login'] ?? '' ); ?></span>
                      </div>
                    </td>
                    <td><?php echo esc_html( $u['user_email'] ?? '' ); ?></td>
                    <td><span class="e3-tag"><?php echo esc_html( (float) ( $u['progress'] ?? 0 ) ); ?>%</span></td>
                    <td><?php echo esc_html( $u['bucket_label'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $u['enroll_date'] ?? '' ); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else : ?>
                <tr><td colspan="5">No hay usuarios para este filtro en el período seleccionado.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    
  </div>
</div>
