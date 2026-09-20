(() => {
    const palette = ['#3478b7', '#18a999', '#e6a23c', '#dc6575', '#8b74c8', '#64748b'];
    document.querySelectorAll('[data-report-chart]').forEach(card => {
        const kind = card.dataset.reportChart;
        const figure = document.createElement('figure');
        figure.className = 'report-chart';
        figure.innerHTML = '<figcaption></figcaption><div class="report-chart-plot" hidden><canvas role="img"></canvas></div><p class="report-chart-status" role="status"></p><details hidden><summary>View chart data</summary><table><thead></thead><tbody></tbody></table></details>';
        card.querySelector(':scope > div').after(figure);
        const caption = figure.querySelector('figcaption');
        const plot = figure.querySelector('.report-chart-plot');
        const canvas = figure.querySelector('canvas');
        const status = figure.querySelector('[role="status"]');
        const details = figure.querySelector('details');
        const form = card.querySelector('form');
        let chart, controller, timer;
        async function update() {
            controller?.abort();
            controller = new AbortController();
            chart?.destroy();
            chart = null;
            plot.hidden = true;
            details.hidden = true;
            caption.textContent = 'Report preview';
            const body = new URLSearchParams(form ? new FormData(form) : undefined);
            body.set('kind', kind);
            body.set('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            if (kind === 'pet' && !body.get('pet_id')) { status.textContent = 'Select a pet to see medical visits.'; return; }
            if (kind === 'qr' && !body.get('token')?.trim()) { status.textContent = 'Enter an active QR token to see the pet’s medical visits.'; return; }
            status.textContent = 'Loading chart…';
            try {
                const response = await fetch('report_chart_data.php', { method: 'POST', body, signal: controller.signal, headers: { Accept: 'application/json' } });
                if (response.redirected) throw new Error('Your session has expired. Reload and sign in again.');
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'Unable to load chart.');
                caption.textContent = data.title;
                if (!data.datasets.some(set => set.data.some(value => value > 0))) { status.textContent = 'No records available for this selection.'; return; }
                const head = figure.querySelector('thead');
                const tbody = figure.querySelector('tbody');
                head.replaceChildren(); tbody.replaceChildren();
                function row(parent, values, tag) {
                    const tr = document.createElement('tr');
                    values.forEach(value => { const cell = document.createElement(tag); cell.textContent = value; tr.append(cell); });
                    parent.append(tr);
                }
                row(head, ['Category', ...data.datasets.map(set => set.label)], 'th');
                data.labels.forEach((label, i) => row(tbody, [label, ...data.datasets.map(set => set.data[i])], 'td'));
                details.hidden = false;
                if (!window.Chart) throw new Error('Chart could not load. Values are available below.');
                const datasets = data.datasets.map((set, i) => ({ ...set, backgroundColor: set.backgroundColor ?? (data.type === 'line' ? palette[i] : palette), borderColor: palette[i], borderWidth: data.type === 'line' ? 2 : 0, borderRadius: data.type === 'bar' ? 5 : 0, pointRadius: 3, tension: 0 }));
                plot.hidden = false;
                canvas.setAttribute('aria-label', data.title + '. Expand View chart data for values.');
                chart = new Chart(canvas, { type: data.type, data: { labels: data.labels, datasets }, options: {
                    responsive: true, maintainAspectRatio: false,
                    animation: !matchMedia('(prefers-reduced-motion: reduce)').matches,
                    indexAxis: data.horizontal ? 'y' : 'x',
                    plugins: { legend: { display: data.type !== 'bar', position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
                    ...(data.type === 'doughnut' ? { cutout: '65%' } : { scales: {
                        [data.horizontal ? 'x' : 'y']: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#edf1f5' } },
                        [data.horizontal ? 'y' : 'x']: { grid: { display: false }, ticks: { font: { size: 10 } } }
                    } })
                } });
                status.textContent = '';
            } catch (error) {
                if (error.name !== 'AbortError') status.textContent = error instanceof SyntaxError ? 'Unable to load chart. Reload the page and try again.' : error.message;
            }
        }
        form?.addEventListener('input', () => { controller?.abort(); clearTimeout(timer); timer = setTimeout(update, kind === 'qr' ? 500 : 200); });
        update();
    });
})();
