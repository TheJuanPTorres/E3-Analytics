<?php
/**
 * Vista: Configuración — Quizzes de retroalimentación
 *
 * Variables disponibles:
 * @var array  $quizzes_by_course  Quizzes agrupados por curso
 * @var int[]  $feedback_ids       IDs de quizzes actualmente marcados como retroalimentación
 * @var string $notice             Mensaje de notificación (vacío si no hay)
 * @var string $notice_type        'success' | 'error'
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="e3-shell">

  <div class="e3-topbar">
    <div class="e3-topbar-left">
      <div class="e3-topbar-title">Configuración</div>
      <div class="e3-topbar-sub">E3 Analytics Dashboard</div>
    </div>
  </div>

  <?php if ( $notice !== '' ) : ?>
    <div class="e3-settings-notice e3-settings-notice--<?php echo esc_attr( $notice_type ); ?>">
      <?php echo esc_html( $notice ); ?>
    </div>
  <?php endif; ?>

  <div class="e3-grid e3-grid--single">

    <div class="e3-card">
      <div class="e3-card-head">
        <div>
          <div class="e3-card-title">
            <span class="dashicons dashicons-feedback" aria-hidden="true"></span>
            Quizzes de retroalimentación
          </div>
          <div class="e3-card-sub">
            Los quizzes marcados aquí <strong>no bloquean la completación del curso</strong>.
            Úsalos para encuestas de satisfacción o preguntas abiertas que no requieren calificación formal.
          </div>
        </div>
      </div>

      <?php if ( empty( $quizzes_by_course ) ) : ?>
        <div class="e3-empty">No se encontraron quizzes publicados en ningún curso.</div>
      <?php else : ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
          <input type="hidden" name="action" value="e3a_save_settings">
          <?php wp_nonce_field( 'e3a_save_settings', '_wpnonce' ); ?>

          <div class="e3-settings-courses">
            <?php foreach ( $quizzes_by_course as $course_id => $course ) : ?>
              <div class="e3-settings-course">

                <div class="e3-settings-course-title">
                  <span class="dashicons dashicons-welcome-learn-more" aria-hidden="true"></span>
                  <?php echo esc_html( $course['title'] ); ?>
                </div>

                <div class="e3-settings-quizzes">
                  <?php foreach ( $course['quizzes'] as $quiz ) :
                    $checked = in_array( $quiz['id'], $feedback_ids, true );
                  ?>
                    <label class="e3-settings-quiz-label">
                      <input
                        type="checkbox"
                        name="feedback_quiz_ids[]"
                        value="<?php echo esc_attr( $quiz['id'] ); ?>"
                        <?php checked( $checked ); ?>
                        class="e3-settings-checkbox"
                      >
                      <span class="e3-settings-quiz-name"><?php echo esc_html( $quiz['title'] ); ?></span>
                      <?php if ( $checked ) : ?>
                        <span class="e3-tag e3-tag--feedback">Retroalimentación</span>
                      <?php endif; ?>
                    </label>
                  <?php endforeach; ?>
                </div>

              </div>
            <?php endforeach; ?>
          </div>

          <div class="e3-settings-footer">
            <button type="submit" class="e3-btn-primary">
              <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
              Guardar cambios
            </button>
            <span class="e3-settings-hint">
              Los cambios se aplican inmediatamente en el dashboard y los reportes.
            </span>
          </div>

        </form>

      <?php endif; ?>
    </div>

    <div class="e3-card">
      <div class="e3-card-head">
        <div>
          <div class="e3-card-title">
            <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
            ¿Cómo funciona?
          </div>
        </div>
      </div>
      <div class="e3-settings-info">
        <p>Cuando un alumno tiene <strong>100% de progreso</strong> en un curso pero Tutor LMS no lo marca como completado (por ejemplo, por un quiz de preguntas abiertas pendiente de revisión), el dashboard lo contabilizaba como no finalizado.</p>
        <p>Al marcar un quiz como <strong>retroalimentación</strong>, el sistema lo excluye del cálculo de completación. Si todos los quizzes que bloquean al alumno son de retroalimentación, se lo cuenta como completado.</p>
        <p><strong>Nota:</strong> esto solo afecta los reportes de este dashboard. Tutor LMS sigue funcionando igual.</p>
      </div>
    </div>

  </div>
</div>

<style>
.e3-settings-notice {
  margin: 12px 20px 0;
  padding: 10px 16px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 500;
}
.e3-settings-notice--success { background: #ecfdf5; color: #065f46; border-left: 3px solid #10b981; }
.e3-settings-notice--error   { background: #fef2f2; color: #991b1b; border-left: 3px solid #ef4444; }

.e3-settings-courses { display: flex; flex-direction: column; gap: 20px; padding: 16px 0; }

.e3-settings-course { border: 1px solid var(--e3-border, #e5e7eb); border-radius: 8px; overflow: hidden; }

.e3-settings-course-title {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 16px;
  background: var(--e3-bg-soft, #f9fafb);
  font-weight: 600; font-size: 13px;
  color: var(--e3-text, #111827);
  border-bottom: 1px solid var(--e3-border, #e5e7eb);
}
.e3-settings-course-title .dashicons { color: var(--e3-accent, #6366f1); font-size: 16px; width: 16px; height: 16px; }

.e3-settings-quizzes { display: flex; flex-direction: column; gap: 0; }

.e3-settings-quiz-label {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 16px;
  cursor: pointer;
  transition: background 0.15s;
  border-bottom: 1px solid var(--e3-border, #e5e7eb);
}
.e3-settings-quiz-label:last-child { border-bottom: none; }
.e3-settings-quiz-label:hover { background: var(--e3-bg-soft, #f9fafb); }

.e3-settings-checkbox { margin: 0; cursor: pointer; width: 15px; height: 15px; flex-shrink: 0; }

.e3-settings-quiz-name { font-size: 13px; color: var(--e3-text-muted, #374151); flex: 1; }

.e3-tag--feedback {
  background: #ede9fe; color: #5b21b6;
  font-size: 10px; font-weight: 600;
  padding: 2px 8px; border-radius: 20px;
  text-transform: uppercase; letter-spacing: 0.04em;
}

.e3-settings-footer {
  display: flex; align-items: center; gap: 16px;
  padding: 16px 0 4px;
  border-top: 1px solid var(--e3-border, #e5e7eb);
  margin-top: 8px;
}

.e3-btn-primary {
  display: inline-flex; align-items: center; gap: 6px;
  background: var(--e3-accent, #6366f1); color: #fff;
  border: none; border-radius: 6px;
  padding: 8px 18px; font-size: 13px; font-weight: 600;
  cursor: pointer; transition: background 0.15s;
}
.e3-btn-primary:hover { background: #4f46e5; }
.e3-btn-primary .dashicons { font-size: 16px; width: 16px; height: 16px; }

.e3-settings-hint { font-size: 12px; color: var(--e3-text-muted, #6b7280); }

.e3-settings-info { padding: 4px 0 8px; }
.e3-settings-info p { font-size: 13px; color: var(--e3-text-muted, #374151); line-height: 1.6; margin: 0 0 10px; }
.e3-settings-info p:last-child { margin-bottom: 0; }

.e3-grid--single { display: flex; flex-direction: column; gap: 16px; }
</style>
