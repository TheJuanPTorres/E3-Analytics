(function () {
  const MAX_TRIES = 25;

  function sanitizeLabel(label) {
    let s = String(label || '').trim();
    s = s.replace(/^curso\s+/i, '');
    s = s.replace(/\s+/g, ' ').trim();
    return s;
  }

  function splitLongWord(word, maxLen) {
    const out = [];
    let w = String(word || '');
    while (w.length > maxLen) {
      out.push(w.slice(0, maxLen));
      w = w.slice(maxLen);
    }
    if (w) out.push(w);
    return out;
  }

  function toLines(label, maxLen, maxLines) {
    const clean = sanitizeLabel(label);
    if (!clean) return '';

    const wordsRaw = clean.split(' ').filter(Boolean);
    const words = [];
    for (const w of wordsRaw) {
      if (w.length > maxLen) words.push(...splitLongWord(w, maxLen));
      else words.push(w);
    }

    const lines = [];
    let cur = '';

    for (const w of words) {
      const test = (cur ? cur + ' ' : '') + w;
      if (test.length > maxLen) {
        if (cur) lines.push(cur);
        cur = w;
      } else {
        cur = test;
      }
    }
    if (cur) lines.push(cur);

    if (lines.length <= maxLines) return lines;

    const clipped = lines.slice(0, maxLines);
    const last = clipped[maxLines - 1];
    clipped[maxLines - 1] = last.length > maxLen - 1 ? last.slice(0, Math.max(1, maxLen - 1)) + '…' : last + '…';
    return clipped;
  }

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

  function makeTooltipTitle(fullLabels) {
    return function (items) {
      if (!items || !items.length) return '';
      const i = items[0].dataIndex;
      return fullLabels[i] || items[0].label || '';
    };
  }

  function makeBaseOptions(fullLabels, opts) {
    const o = opts || {};
    const maxLen = typeof o.maxLen === 'number' ? o.maxLen : 18;
    const maxLines = typeof o.maxLines === 'number' ? o.maxLines : 4;
    const stacked = !!o.stacked;

    return {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      animation: { duration: 700, easing: 'easeOutQuart' },
      plugins: {
        legend: {
          position: 'top',
          labels: {
            boxWidth: 10,
            usePointStyle: true,
            pointStyle: 'circle',
            padding: 16,
            color: '#334155',
            font: { size: 12, weight: '600' },
          },
        },
        tooltip: {
          enabled: true,
          backgroundColor: 'rgba(15, 23, 42, .94)',
          padding: 12,
          titleSpacing: 6,
          bodySpacing: 6,
          displayColors: true,
          callbacks: {
            title: makeTooltipTitle(fullLabels),
          },
        },
      },
      scales: {
        x: {
          stacked,
          grid: { display: false },
          ticks: {
            color: '#64748b',
            autoSkip: false,
            maxRotation: 0,
            minRotation: 0,
            padding: 10,
            callback: function (value, index) {
              const label = fullLabels[index] ?? this.getLabelForValue(value);
              return toLines(label, maxLen, maxLines);
            },
          },
        },
        y: {
          stacked,
          beginAtZero: true,
          grid: { color: 'rgba(148, 163, 184, .35)' },
          ticks: {
            color: '#64748b',
            precision: 0,
          },
        },
      },
    };
  }

  function initDashboardChart() {
    const canvas = document.getElementById('e3-course-enrollments-chart');
    if (!canvas) return true;

    const payload = window.E3A_CHART;

    if (!payload || !payload.labels || !payload.labels.length) {
      showFallback(canvas, 'No hay datos suficientes para graficar en este período.');
      return true;
    }

    if (typeof window.Chart === 'undefined') {
      showFallback(canvas, 'No se pudo cargar Chart.js. Revisa que no esté bloqueado por un optimizador o CDN.');
      return false;
    }

    const rawLabels = payload.labels || [];
    const fullLabels = rawLabels.map(sanitizeLabel);

    const first = payload.new || payload.first || [];
    const returning = payload.returning || payload.return || [];

    const ctx = canvas.getContext('2d');

    if (canvas.__e3Chart) {
      try {
        canvas.__e3Chart.destroy();
      } catch (e) {
        // ignore
      }
    }

    const data = {
      labels: fullLabels,
      datasets: [
        {
          label: 'Nuevos (registrados en el período)',
          data: first,
          backgroundColor: 'rgba(37, 99, 235, .55)',
          borderColor: 'rgba(37, 99, 235, 1)',
          borderWidth: 1,
          borderRadius: 10,
          borderSkipped: false,
          maxBarThickness: 44,
          categoryPercentage: 0.7,
          barPercentage: 0.9,
        },
        {
          label: 'Ya registrados antes',
          data: returning,
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

    const options = makeBaseOptions(fullLabels, { maxLen: 18, maxLines: 4, stacked: false });

    canvas.style.display = '';
    const fallback = (canvas.closest('.e3-chart-wrap') || canvas.parentElement)?.querySelector('.e3-chart-fallback');
    if (fallback) fallback.remove();

    canvas.__e3Chart = new Chart(ctx, { type: 'bar', data, options });
    return true;
  }

  // Exponer helpers para otras vistas (dropout.js)
  window.E3Analytics = window.E3Analytics || {};
  window.E3Analytics.chart = window.E3Analytics.chart || {};
  window.E3Analytics.chart.sanitizeLabel = sanitizeLabel;
  window.E3Analytics.chart.toLines = toLines;
  window.E3Analytics.chart.makeBaseOptions = makeBaseOptions;
  window.E3Analytics.chart.showFallback = showFallback;

  function boot() {
    initExportButtons();
    let tries = 0;

    (function attempt() {
      const ok = initDashboardChart();
      if (ok) return;
      tries++;
      if (tries >= MAX_TRIES) return;
      setTimeout(attempt, 200);
    })();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => { boot(); initCustomRange(); });
  } else {
    boot();
    initCustomRange();
  }

  /**
   * Rango personalizado: muestra u oculta los dos <input type="date">.
   *
   * Los inputs se renderizan SIEMPRE visibles desde PHP. Si este script no
   * carga, quedan a la vista y todo sigue funcionando: el submit es el boton
   * "Actualizar" del <form method="get">, no depende de ningun listener.
   */
  function initCustomRange() {
    const select = document.querySelector('[data-e3-period]');
    const fields = document.querySelectorAll('[data-e3-custom-range]');
    if (!select || !fields.length) return;

    const sync = () => {
      const show = select.value === 'custom';
      fields.forEach((el) => { el.hidden = !show; });
    };

    select.addEventListener('change', sync);
    sync();
  }

  function initExportButtons() {
    const nodes = document.querySelectorAll('[data-e3-export-url]');
    if (!nodes.length) return;

    nodes.forEach((el) => {
      const url = el.getAttribute('data-e3-export-url');
      if (!url) return;

      if (!el.classList.contains('e3-has-export')) el.classList.add('e3-has-export');

      // Avoid duplicates.
      if (el.querySelector('.e3-export-btn')) return;

      const a = document.createElement('a');
      a.className = 'e3-export-btn';
      a.href = url;
      a.setAttribute('aria-label', 'Descargar Excel');
      a.title = 'Descargar Excel';
      a.innerHTML = '<span class="dashicons dashicons-download" aria-hidden="true"></span>';

      a.addEventListener('click', () => {
        a.classList.add('is-exporting');
        setTimeout(() => a.classList.remove('is-exporting'), 900);
      });

      el.appendChild(a);
    });
  }
})();
