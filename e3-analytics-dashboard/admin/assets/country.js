(function () {
  const MAX_TRIES = 25;

  function showFallback(canvas, message) {
    const wrap = canvas.closest('.e3-chart-wrap') || canvas.parentElement;
    if (!wrap) return;
    if (wrap.querySelector('.e3-chart-fallback')) return;
    const div = document.createElement('div');
    div.className = 'e3-chart-fallback';
    div.textContent = message;
    canvas.style.display = 'none';
    wrap.appendChild(div);
  }

  function initCountryChart() {
    const canvas = document.getElementById('e3-country-chart');
    if (!canvas) return true;

    const payload = window.E3A_COUNTRY_CHART;
    if (!payload || !payload.labels || !payload.labels.length) {
      showFallback(canvas, 'No hay datos suficientes para graficar en este período.');
      return true;
    }

    if (typeof window.Chart === 'undefined') {
      showFallback(canvas, 'No se pudo cargar Chart.js.');
      return false;
    }

    const labels = payload.labels || [];
    const enrollments = payload.enrollments || [];
    const completed = payload.completed || [];

    const ctx = canvas.getContext('2d');
    if (canvas.__e3Chart) {
      try { canvas.__e3Chart.destroy(); } catch (e) {}
    }

    const data = {
      labels,
      datasets: [
        {
          label: 'Inscripciones',
          data: enrollments,
          backgroundColor: 'rgba(37, 99, 235, .50)',
          borderColor: 'rgba(37, 99, 235, 1)',
          borderWidth: 1,
          borderRadius: 10,
          borderSkipped: false,
          maxBarThickness: 44,
          categoryPercentage: 0.7,
          barPercentage: 0.9,
        },
        {
          label: 'Completados',
          data: completed,
          backgroundColor: 'rgba(99, 102, 241, .35)',
          borderColor: 'rgba(99, 102, 241, 1)',
          borderWidth: 1,
          borderRadius: 10,
          borderSkipped: false,
          maxBarThickness: 44,
          categoryPercentage: 0.7,
          barPercentage: 0.9,
        },
      ],
    };

    const opts = (window.E3Analytics && window.E3Analytics.chart && window.E3Analytics.chart.makeBaseOptions)
      ? window.E3Analytics.chart.makeBaseOptions(labels, { maxLen: 14, maxLines: 3, stacked: false })
      : { responsive: true, maintainAspectRatio: false };

    canvas.style.display = '';
    const fallback = (canvas.closest('.e3-chart-wrap') || canvas.parentElement)?.querySelector('.e3-chart-fallback');
    if (fallback) fallback.remove();

    canvas.__e3Chart = new Chart(ctx, { type: 'bar', data, options: opts });
    return true;
  }

  function boot() {
    let tries = 0;
    (function attempt() {
      const ok = initCountryChart();
      if (ok) return;
      tries++;
      if (tries >= MAX_TRIES) return;
      setTimeout(attempt, 200);
    })();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
