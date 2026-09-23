(() => {
    const palette = ['#3569d4', '#0d9488', '#d99724', '#db6073', '#8970c5', '#64748b'];
    const statusColors = { pending: '#d99724', approved: '#3569d4', completed: '#0d9488', rejected: '#db6073', cancelled: '#94a3b8' };
    const number = new Intl.NumberFormat();
    const sum = values => values.reduce((total, value) => total + Number(value), 0);
    const annotations = {
        id: 'reportAnnotations',
        afterDatasetsDraw(chart) {
            const { ctx, chartArea, data, options } = chart;
            if (!chartArea) return;
            ctx.save();
            ctx.fillStyle = '#26374d';
            if (chart.config.type === 'doughnut') {
                const arc = chart.getDatasetMeta(0).data[0];
                if (arc) {
                    const total = number.format(sum(data.datasets[0].data));
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    let size = 30;
                    ctx.font = `700 ${size}px ${options.font.family}`;
                    while (ctx.measureText(total).width > arc.innerRadius * 1.65 && size > 12) {
                        ctx.font = `700 ${--size}px ${options.font.family}`;
                    }
                    ctx.fillText(total, arc.x, arc.y - 8);
                    ctx.font = `500 11px ${options.font.family}`;
                    ctx.fillStyle = '#64748b';
                    ctx.fillText('TOTAL', arc.x, arc.y + 17);
                }
            } else if (chart.config.type === 'bar') {
                const horizontal = options.indexAxis === 'y';
                ctx.font = `600 11px ${options.font.family}`;
                ctx.textAlign = horizontal ? 'left' : 'center';
                ctx.textBaseline = horizontal ? 'middle' : 'bottom';
                chart.getDatasetMeta(0).data.forEach((bar, index) => {
                    const value = number.format(data.datasets[0].data[index]);
                    if (horizontal) {
                        const fits = bar.x + 8 + ctx.measureText(value).width <= chart.width - 4;
                        ctx.textAlign = fits ? 'left' : 'right';
                        ctx.fillStyle = fits ? '#26374d' : '#fff';
                        ctx.fillText(value, fits ? bar.x + 8 : bar.x - 8, bar.y);
                    } else {
                        ctx.fillText(value, bar.x, bar.y - 6);
                    }
                });
            }
            ctx.restore();
        }
    };
    document.querySelectorAll('[data-report-chart]').forEach(card => {
        const kind = card.dataset.reportChart;
        const figure = document.createElement('figure');
        figure.className = 'report-chart';
        figure.innerHTML = '<figcaption></figcaption><div class="report-chart-summary" hidden></div><div class="report-chart-body" hidden><div class="report-chart-scroll"><div class="report-chart-plot"><canvas role="img"></canvas></div></div><ul class="report-chart-legend" aria-label="Chart legend" hidden></ul></div><p class="report-chart-status" role="status"></p><details hidden><summary>View chart data</summary><table><thead></thead><tbody></tbody></table></details>';
        card.querySelector(':scope > div').after(figure);
        const caption = figure.querySelector('figcaption');
        const plot = figure.querySelector('.report-chart-plot');
        const chartBody = figure.querySelector('.report-chart-body');
        const summary = figure.querySelector('.report-chart-summary');
        const legend = figure.querySelector('.report-chart-legend');
        const canvas = figure.querySelector('canvas');
        const status = figure.querySelector('[role="status"]');
        const details = figure.querySelector('details');
        const form = card.querySelector('form');
        let chart, controller, timer;
        async function update() {
            controller?.abort();
            const request = controller = new AbortController();
            figure.setAttribute('aria-busy', 'false');
            chart?.destroy();
            chart = null;
            chartBody.hidden = true;
            summary.hidden = true;
            legend.hidden = true;
            legend.replaceChildren();
            details.hidden = true;
            caption.textContent = 'Report preview';
            const body = new URLSearchParams(form ? new FormData(form) : undefined);
            body.set('kind', kind);
            body.set('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            if (kind === 'pet' && !body.get('pet_id')) { status.textContent = 'Select a pet to see medical visits.'; return; }
            if (kind === 'qr' && !body.get('token')?.trim()) { status.textContent = 'Enter an active QR token to see the pet’s medical visits.'; return; }
            status.textContent = 'Loading chart…';
            figure.setAttribute('aria-busy', 'true');
            try {
                const response = await fetch('report_chart_data.php', { method: 'POST', body, signal: request.signal, headers: { Accept: 'application/json' } });
                if (response.redirected) throw new Error('Your session has expired. Reload and sign in again.');
                const data = await response.json();
                if (request.signal.aborted) return;
                if (!response.ok) throw new Error(data.error || 'Unable to load chart.');
                caption.textContent = data.title;
                const head = figure.querySelector('thead');
                const tbody = figure.querySelector('tbody');
                head.replaceChildren(); tbody.replaceChildren();
                function row(parent, values, tag) {
                    const tr = document.createElement('tr');
                    values.forEach(value => { const cell = document.createElement(tag); if (tag === 'th') cell.scope = 'col'; cell.textContent = value; tr.append(cell); });
                    parent.append(tr);
                }
                row(head, ['Category', ...data.datasets.map(set => set.label)], 'th');
                data.labels.forEach((label, i) => row(tbody, [label, ...data.datasets.map(set => set.data[i])], 'td'));
                details.hidden = false;
                if (!data.datasets.some(set => set.data.some(value => value > 0))) { status.textContent = 'No records available for this selection. Try another filter or date range.'; return; }
                if (!window.Chart) throw new Error('Chart could not load. Values are available below.');
                const doughnut = data.type === 'doughnut';
                const line = data.type === 'line';
                const colors = kind === 'appointments' ? data.labels.map(label => statusColors[label.toLowerCase()] || palette[0]) : (data.datasets[0].backgroundColor || palette);
                const datasets = data.datasets.map((set, i) => ({
                    ...set,
                    backgroundColor: doughnut ? colors : palette[i],
                    borderColor: doughnut ? '#fff' : palette[i],
                    borderWidth: doughnut ? 3 : line ? 3 : 0,
                    borderRadius: doughnut ? 5 : 6,
                    borderSkipped: false,
                    maxBarThickness: 30,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#fff',
                    pointBorderWidth: 2,
                    pointStyle: i ? 'rectRot' : 'circle',
                    borderDash: line && i ? [5, 4] : [],
                    tension: 0,
                    hoverOffset: doughnut ? 5 : 0
                }));
                const total = sum(data.datasets.flatMap(set => set.data));
                const units = { pet: 'medical visits', qr: 'medical visits', appointments: 'appointments', vaccinations: 'vaccination doses', directory: 'new registrations', analytics: 'approved pets' };
                summary.replaceChildren();
                const totalLabel = document.createElement('strong');
                totalLabel.textContent = number.format(total);
                const unitLabel = document.createElement('span');
                unitLabel.textContent = units[kind];
                summary.append(totalLabel, unitLabel);
                summary.hidden = doughnut;
                figure.dataset.chartType = data.type;
                plot.style.height = data.horizontal ? `${Math.max(220, data.labels.length * 36)}px` : '';
                plot.style.minWidth = !doughnut && !data.horizontal ? `${data.labels.length * 42}px` : '';
                if (doughnut || line) {
                    const items = doughnut ? data.labels.map((label, i) => ({ label, value: data.datasets[0].data[i], color: colors[i % colors.length] })) : data.datasets.map((set, i) => ({ label: set.label, value: sum(set.data), color: palette[i] }));
                    items.forEach(item => {
                        const li = document.createElement('li');
                        const dot = document.createElement('i');
                        dot.style.backgroundColor = item.color;
                        dot.setAttribute('aria-hidden', 'true');
                        const label = document.createElement('span');
                        label.textContent = item.label;
                        const value = document.createElement('strong');
                        value.textContent = number.format(item.value);
                        li.append(dot, label, value);
                        if (doughnut) {
                            const percent = document.createElement('small');
                            percent.textContent = `${Math.round(item.value / total * 100)}%`;
                            li.append(percent);
                        }
                        legend.append(li);
                    });
                    legend.hidden = false;
                }
                chartBody.hidden = false;
                canvas.setAttribute('aria-label', data.title + '. Expand View chart data for values.');
                chart = new Chart(canvas, { type: data.type, plugins: [annotations], data: { labels: data.labels, datasets }, options: {
                    responsive: true, maintainAspectRatio: false,
                    font: { family: getComputedStyle(card).fontFamily },
                    color: '#64748b',
                    animation: matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 350 },
                    indexAxis: data.horizontal ? 'y' : 'x',
                    interaction: { mode: line ? 'index' : 'nearest', intersect: false },
                    layout: { padding: { top: 20, right: data.horizontal ? 40 : 10, bottom: 4, left: 4 } },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#203149', padding: 12, cornerRadius: 9,
                            usePointStyle: true, boxPadding: 5,
                            titleFont: { size: 12, weight: '600' }, bodyFont: { size: 12 },
                            callbacks: { label: context => {
                                const value = Number(context.raw);
                                return `${context.dataset.label}: ${number.format(value)}${doughnut ? ` (${Math.round(value / total * 100)}%)` : ''}`;
                            } }
                        }
                    },
                    ...(doughnut ? { cutout: '74%', layout: { padding: 8 } } : { scales: {
                        [data.horizontal ? 'x' : 'y']: { beginAtZero: true, grace: '15%', suggestedMax: 1, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 5, padding: 8, font: { size: 11 } }, grid: { color: '#e8eef5', drawTicks: false } },
                        [data.horizontal ? 'y' : 'x']: { border: { display: false }, grid: { display: false }, ticks: { padding: 10, maxRotation: 0, autoSkip: false, font: { size: 11 }, callback: function(value) {
                            const label = this.getLabelForValue(value);
                            if (!data.horizontal) return label.split(' ');
                            return label.length > 18 ? label.slice(0, 17) + '…' : label;
                        } } }
                    } })
                } });
                status.textContent = '';
            } catch (error) {
                if (!request.signal.aborted && error.name !== 'AbortError') {
                    chartBody.hidden = true;
                    summary.hidden = true;
                    status.textContent = error instanceof SyntaxError ? 'Unable to load chart. Reload the page and try again.' : error.message;
                }
            } finally {
                if (controller === request) figure.setAttribute('aria-busy', 'false');
            }
        }
        form?.addEventListener('input', () => { controller?.abort(); clearTimeout(timer); timer = setTimeout(update, kind === 'qr' ? 500 : 200); });
        update();
    });
})();
