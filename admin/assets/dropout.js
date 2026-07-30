(function () {
  const MAX_TRIES = 25;

  const helper = (window.E3Analytics && window.E3Analytics.chart) || {};
  const sanitizeLabel = helper.sanitizeLabel || function (label) {
    let s = String(label || '').trim();
    s = s.replace(/^curso\s+/i, '');
    s = s.replace(/\s+/g, ' ').trim();
    return s;
  };

  function showFallback(canvas, message) {
    const fn = helper.showFallback;
    if (typeof fn === 'function') return fn(canvas, message);

    const wrap = canvas.closest('.e3-chart-wrap') || canvas.parentElement;
    if (!wrap) return;
    if (wrap.querySelector('.e3-chart-fallback')) return;

    const div = document.createElement('div');
    div.className = 'e3-chart-fallback';
    div.textContent = message;
    canvas.style.display = 'none';
    wrap.appendChild(div);
  }

  function colorForBucket(label, idx) {
    const key = String(label || '').toLowerCase();

    if (key.includes('0') && (key.includes('10') || key.includes('–10') || key.includes('-10'))) return ['rgba(37,99,235,.55)', 'rgba(37,99,235,1)'];
    if (key.includes('11') || key.includes('25')) return ['rgba(99,102,241,.45)', 'rgba(99,102,241,1)'];
    if (key.includes('26') || key.includes('50')) return ['rgba(20,184,166,.40)', 'rgba(20,184,166,1)'];
    if (key.includes('51') || key.includes('75')) return ['rgba(245,158,11,.35)', 'rgba(245,158,11,1)'];
    if (key.includes('76') || key.includes('99')) return ['rgba(244,63,94,.35)', 'rgba(244,63,94,1)'];

    const palette = [
      ['rgba(37,99,235,.55)', 'rgba(37,99,235,1)'],
      ['rgba(99,102,241,.45)', 'rgba(99,102,241,1)'],
      ['rgba(20,184,166,.40)', 'rgba(20,184,166,1)'],
      ['rgba(245,158,11,.35)', 'rgba(245,158,11,1)'],
      ['rgba(244,63,94,.35)', 'rgba(244,63,94,1)'],
    ];
    return palette[idx % palette.length];
  }

  function initDropoutChart() {
    const canvas = document.getElementById('e3-dropout-progress-chart');
    if (!canvas) return true;

    const payload = window.E3A_DROPOUT_CHART || window.E3DropoutData || window.E3DropoutData || window.E3A_DROPOUT_DATA;
    if (!payload || !Array.isArray(payload.labels) || payload.labels.length === 0) {
      showFallback(canvas, 'No hay datos suficientes para graficar en este período.');
      return true;
    }

    if (typeof window.Chart === 'undefined') {
      showFallback(canvas, 'No se pudo cargar Chart.js. Revisa que no esté bloqueado por un optimizador, CDN o plugin de caché.');
      return false;
    }

    const rawLabels = payload.labels || [];
    const fullLabels = rawLabels.map(sanitizeLabel);

    const datasets = (payload.datasets || []).map((ds, idx) => {
      const colors = colorForBucket(ds.label, idx);
      return {
        ...ds,
        backgroundColor: colors[0],
        borderColor: colors[1],
        borderWidth: 1,
        borderRadius: 10,
        borderSkipped: false,
        maxBarThickness: 56,
        categoryPercentage: 0.72,
        barPercentage: 0.92,
      };
    });

    const data = {
      labels: fullLabels,
      datasets,
    };

    const options = typeof helper.makeBaseOptions === 'function'
      ? helper.makeBaseOptions(fullLabels, { stacked: true, maxLen: 18, maxLines: 4 })
      : {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            x: { stacked: true },
            y: { stacked: true, beginAtZero: true },
          },
        };

    options.scales = options.scales || {};
    options.scales.x = { ...(options.scales.x || {}), stacked: true };
    options.scales.y = { ...(options.scales.y || {}), stacked: true, beginAtZero: true };

    options.plugins = options.plugins || {};
    options.plugins.tooltip = options.plugins.tooltip || {};
    options.plugins.tooltip.callbacks = options.plugins.tooltip.callbacks || {};
    options.plugins.tooltip.callbacks.title = function (items) {
      if (!items || !items.length) return '';
      const i = items[0].dataIndex;
      return fullLabels[i] || items[0].label || '';
    };
    options.plugins.tooltip.callbacks.footer = function (items) {
      if (!items || !items.length) return '';
      let total = 0;
      for (const it of items) total += Number(it.parsed && it.parsed.y ? it.parsed.y : 0);
      return total ? 'Total: ' + total : '';
    };

    const ctx = canvas.getContext('2d');
    if (canvas.__e3Chart) {
      try { canvas.__e3Chart.destroy(); } catch (e) {}
    }

    canvas.__e3Chart = new Chart(ctx, {
      type: 'bar',
      data,
      options,
    });

    return true;
  }

  function boot() {
    let tries = 0;
    (function tick() {
      const ok = initDropoutChart();
      if (ok) return;
      tries += 1;
      if (tries >= MAX_TRIES) return;
      setTimeout(tick, 200);
    })();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
