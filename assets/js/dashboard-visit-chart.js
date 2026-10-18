(() => {
  const canvas = document.getElementById('dashboardVisitChart');
  const payload = document.getElementById('dashboardVisitChartData');
  if (!canvas || !payload || typeof Chart === 'undefined') return;
  const wrapper = canvas.parentElement;
  try {
    wrapper.hidden = false;
    new Chart(canvas, {
      type: 'bar', data: JSON.parse(payload.textContent),
      options: {
        responsive: true, maintainAspectRatio: false,
        animation: false,
        interaction: {mode: 'index', intersect: false},
        plugins: {
          legend: {position: 'bottom', labels: {usePointStyle: true, boxWidth: 9, padding: 16, font: {family: 'Arial', size: 12}}},
          tooltip: {callbacks: {footer: items => `Total: ${items.reduce((sum, item) => sum + item.parsed.y, 0)}`}}
        },
        scales: {
          x: {stacked: true, grid: {display: false}, ticks: {maxRotation: 0, autoSkip: true, maxTicksLimit: 7, color: '#526579', font: {family: 'Arial', size: 11}}},
          y: {stacked: true, beginAtZero: true, suggestedMax: 4, ticks: {precision: 0, color: '#526579'}, grid: {color: '#edf1f5'}, title: {display: true, text: 'Appointments'}}
        }
      }
    });
    document.querySelector('.visit-trend-data').open = false;
  } catch (_) {
    wrapper.hidden = true; // The server-rendered counts remain available.
  }
})();
