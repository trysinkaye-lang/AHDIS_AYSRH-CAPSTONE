<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../includes/header.php";
require_once __DIR__ . "/../includes/sidebar.php";
require_once __DIR__ . "/report_data.php";
?>

<style>
:root{
    --bg: #f1f5f9;
    --shell: #ffffff;
    --line: #dbe4f0;
    --line-soft: #e8eef6;
    --text: #0f172a;
    --muted: #64748b;
    --muted-2: #475569;
    --blue-1: #0f172a;
    --blue-2: #1e3a8a;
    --blue-3: #2563eb;
    --btn-dark: #475569;
    --btn-dark-hover: #334155;
    --btn-blue: #2563eb;
    --btn-blue-hover: #1d4ed8;
    --card-shadow: 0 14px 34px rgba(15,23,42,.08);
    --soft-shadow: 0 10px 24px rgba(15,23,42,.05);
}

.reports-graph-page{
    width: 100% !important;
    min-height: 100vh !important;
    margin: 0 !important;
    padding: 0 0 36px !important;
    background: #f3f7fb !important;
    overflow-x: hidden;
}

body{
    overflow-x: hidden;
}

.report-page,
.chart-page{
    min-height: 100vh;
    background: #f3f7fb;
}

.graph-shell{
    background: var(--shell);
    border-radius: 18px !important;
    overflow: hidden;
    border: 1px solid var(--line);
    box-shadow: var(--card-shadow);
}

.graph-topbar{
    min-height: 0 !important;
    padding: 26px 32px !important;
    border-radius: 18px 18px 0 0 !important;
    color: #fff;
    background: linear-gradient(90deg, #0f1d35 0%, #1f4ed8 55%, #c084d9 100%) !important;
    display: block !important;
}

.graph-title h1{
    margin: 0 !important;
    font-size: 28px !important;
    font-weight: 800 !important;
    letter-spacing: 0.3px !important;
    line-height: 1.15 !important;
}

.graph-title p{
    margin: 8px 0 0 !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    color: rgba(255,255,255,.90) !important;
    letter-spacing: .01em;
}

.graph-actions{
    padding: 20px 28px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    background: #fff;
    border-bottom: 1px solid var(--line-soft);
}

.graph-actions a,
.graph-actions button{
    border: none;
    text-decoration: none;
    color: #fff;
    font-weight: 700;
    font-size: 13px;
    padding: 11px 16px;
    border-radius: 12px;
    cursor: pointer;
    transition:
        background-color 120ms ease,
        color 120ms ease,
        border-color 120ms ease,
        box-shadow 120ms ease,
        opacity 120ms ease;
    box-shadow: 0 8px 18px rgba(15,23,42,.10);
}

.graph-actions a:hover,
.graph-actions button:hover{
    transform: none;
}

.btn-back{
    background: var(--btn-dark);
}
.btn-back:hover{
    background: var(--btn-dark-hover);
}

.btn-png{
    background: var(--btn-blue);
}
.btn-png:hover{
    background: var(--btn-blue-hover);
}

.graph-body{
    padding: 24px 28px 36px !important;
    background: #f3f7fb !important;
}

.graph-error{
    margin-bottom: 18px;
    background: #fff1f2;
    border: 1px solid #fecdd3;
    color: #9f1239;
    border-radius: 12px;
    padding: 13px 15px;
    font-weight: 700;
}

.graph-summary-strip{
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
    padding: 14px 16px;
    background: #ffffff;
    border: 1px solid var(--line-soft);
    border-radius: 16px;
    box-shadow: var(--soft-shadow);
}

.graph-summary-left{
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.graph-summary-label{
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #2563eb;
}

.graph-summary-text{
    font-size: 13px;
    color: var(--muted);
    line-height: 1.5;
}

.chart-grid{
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}

.chart-card{
    background: #ffffff !important;
    border: 1px solid #dce6f1 !important;
    border-radius: 18px !important;
    padding: 28px !important;
    margin: 0 !important;
    overflow: hidden;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05) !important;
}

.chart-card.small{
    min-height: 0;
}

.chart-card-head{
    padding: 0 0 18px;
    border-bottom: 1px solid var(--line-soft);
    background: transparent;
}

.chart-card-title{
    margin: 0;
    font-size: 15px;
    font-weight: 800;
    color: var(--text);
    letter-spacing: -.01em;
}

.chart-card-subtitle{
    margin: 4px 0 0;
    font-size: 12px;
    color: var(--muted);
}

.chart-card-body{
    padding: 0;
    background: #fff;
}

.chart-wrap{
    position: relative;
    width: 100%;
    height: 560px;
    max-height: 560px;
    min-height: 0;
    overflow: hidden;
    box-sizing: border-box;
    background: #ffffff;
    border: 1px solid #dce6f1;
    border-radius: 16px;
    padding: 20px 24px;
}

.chart-wrap canvas{
    width: 100% !important;
    height: 100% !important;
    max-height: 100% !important;
    display: block;
}

.chart-wrap.has-empty-state{
    display:grid;
    place-items:center;
}

.chart-wrap.has-empty-state canvas{
    display:none !important;
}

.chart-empty-state{
    width:100%;
    height:100%;
    min-height:0;
    display:grid;
    place-items:center;
    text-align:center;
    color:var(--muted);
    font-size:14px;
    font-weight:800;
}

.chart-card.small .chart-wrap{
    height: 500px;
    max-height: 500px;
    min-height: 0;
}

.chart-grid.two-col{
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.chart-grid.three-col{
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.export-capture{
    background: #fff;
}

@media (max-width: 1100px){
    .chart-grid.two-col,
    .chart-grid.three-col{
        grid-template-columns: 1fr;
    }
}

@media (max-width: 640px){
    .reports-graph-page{
        padding-top: 10px;
    }

    .graph-topbar,
    .graph-actions,
    .graph-body{
        padding-left: 16px;
        padding-right: 16px;
    }

    .graph-title h1{
        font-size: 22px;
    }

    .graph-title p{
        font-size: 13px;
    }

    .graph-summary-strip{
        flex-direction: column;
        align-items: flex-start;
    }

    .chart-card-head,
    .chart-card-body{
        padding-left: 14px;
        padding-right: 14px;
    }

    .chart-wrap{
        height: 420px;
        max-height: 420px;
        min-height: 0;
        padding: 8px;
    }

    .chart-card.small .chart-wrap{
        height: 380px;
        max-height: 380px;
        min-height: 0;
    }
}
</style>

<div class="reports-graph-page report-page chart-page">
    <div class="graph-shell export-capture" id="graphExportArea">

        <div class="graph-topbar">
            <div class="graph-title">
                <h1><?= htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') ?></h1>
                <p><?= htmlspecialchars((string)$municipalityName, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>

        <div class="graph-actions">
            <a class="btn-back" href="report_table.php?type=<?= urlencode((string)$type) ?>">Back to Table</a>
            <button type="button" class="btn-png" onclick="exportGraph()">Export Graph</button>
        </div>

        <div class="graph-body">

            <?php if($reportError !== ''): ?>
                <div class="graph-error"><?= htmlspecialchars((string)$reportError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if($type === 'partner_education'): ?>
                <div class="chart-grid">
                    <div class="chart-card">
                        <div class="chart-card-head">
                            <h3 class="chart-card-title">Educational Attainment of Partners</h3>
                            <p class="chart-card-subtitle">Distribution by partner age group</p>
                        </div>
                        <div class="chart-card-body">
                            <div class="chart-wrap">
                                <canvas id="partnerEducationChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif($type === 'partner_income'): ?>
                <div class="chart-grid">
                    <div class="chart-card">
                        <div class="chart-card-head">
                            <h3 class="chart-card-title">Monthly Income Range of Partners</h3>
                            <p class="chart-card-subtitle">Distribution by partner age group</p>
                        </div>
                        <div class="chart-card-body">
                            <div class="chart-wrap">
                                <canvas id="partnerIncomeChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="chart-grid">
                    <div class="chart-card">
                        <div class="chart-card-body">
                            <div class="chart-wrap">
                                <canvas id="reportChartMain"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script src="<?= htmlspecialchars(assetUrl('/assets/js/chart.umd.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
let chartInstances = [];
const reportType = <?= json_encode($type) ?>;
const graphData = <?= json_encode($graphData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const BRAND = '#2948A8';
const DEEP = '#143978';
const TICK = 'rgba(11,18,32,0.90)';
const CHART_FONT_FAMILY = 'Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial';

function hexToRgb(hex){
    const value = String(hex || '').replace('#', '').trim();
    if (value.length !== 6) {
        return { r: 41, g: 72, b: 168 };
    }

    return {
        r: parseInt(value.slice(0, 2), 16),
        g: parseInt(value.slice(2, 4), 16),
        b: parseInt(value.slice(4, 6), 16)
    };
}

function rgba(hex, alpha){
    const { r, g, b } = hexToRgb(hex);
    return `rgba(${r},${g},${b},${alpha})`;
}

const GRID = rgba(BRAND, 0.10);
const INT_TICKS = {
    precision: 0,
    callback: (value) => (Number.isInteger(Number(value)) ? value : '')
};

const EXCEL_COLORS = {
    blue: BRAND,
    orange: '#ED7D31',
    gray: '#A5A5A5',
    yellow: '#FFC000',
    lightBlue: '#5B9BD5',
    green: '#70AD47'
};

const valueLabelPlugin = {
    id: 'valueLabelPlugin',
    afterDatasetsDraw(chart){
        const { ctx, chartArea } = chart;
        if (!chartArea) return;

        chart.data.datasets.forEach((dataset, datasetIndex) => {
            const meta = chart.getDatasetMeta(datasetIndex);
            if (meta.hidden) return;

            meta.data.forEach((element, index) => {
                const rawValue = dataset.data[index];
                const value = Number(rawValue);

                if (rawValue === null || rawValue === undefined || Number.isNaN(value)) return;
                if (!chart.options?.plugins?.valueLabelPlugin?.showZero && value === 0) return;

                ctx.save();
                ctx.fillStyle = '#0b1220';
                ctx.font = `900 9px ${CHART_FONT_FAMILY}`;
                ctx.textBaseline = 'middle';

                const isHorizontal = chart.options?.indexAxis === 'y';
                const text = String(value);

                if (isHorizontal) {
                    ctx.textAlign = 'left';
                    const width = ctx.measureText(text).width;
                    const x = Math.min(element.x + 6, chartArea.right - width - 2);
                    const y = element.y;
                    ctx.fillText(text, x, y);
                } else {
                    ctx.textAlign = 'center';
                    const labelY = Math.max(element.y - 8, chartArea.top + 10);
                    ctx.fillText(text, element.x, labelY);
                }

                ctx.restore();
            });
        });
    }
};

Chart.register(valueLabelPlugin);
Chart.defaults.font.family = CHART_FONT_FAMILY;
Chart.defaults.color = '#0b1220';
Chart.defaults.animation = false;
Chart.defaults.animations = {
    colors: false,
    x: false,
    y: false,
    radius: false,
    tension: false
};
if (Chart.defaults.transitions) {
    Object.keys(Chart.defaults.transitions).forEach((key) => {
        Chart.defaults.transitions[key].animation = false;
    });
}
Chart.defaults.plugins.tooltip.backgroundColor = rgba(DEEP, 0.96);
Chart.defaults.plugins.tooltip.titleColor = '#ffffff';
Chart.defaults.plugins.tooltip.bodyColor = '#ffffff';
Chart.defaults.plugins.tooltip.borderColor = rgba(DEEP, 0.16);
Chart.defaults.plugins.tooltip.borderWidth = 1;

function destroyCharts(){
    chartInstances.forEach(chart => chart.destroy());
    chartInstances = [];
}

function normalizeLabels(labels){
    return (labels || []).map(label => Array.isArray(label) ? label : String(label ?? ''));
}

function ensureChartEmptyState(canvas){
    const wrap = canvas?.closest('.chart-wrap');
    if (!wrap) return null;

    let emptyState = wrap.querySelector('.chart-empty-state');
    if (!emptyState) {
        emptyState = document.createElement('div');
        emptyState.className = 'chart-empty-state';
        emptyState.textContent = 'No data available for this chart.';
        emptyState.hidden = true;
        wrap.appendChild(emptyState);
    }

    return emptyState;
}

function setChartEmptyState(canvas, show){
    if (!canvas) return;

    const wrap = canvas.closest('.chart-wrap');
    const emptyState = ensureChartEmptyState(canvas);

    if (show) {
        wrap?.classList.add('has-empty-state');
        canvas.hidden = true;
        if (emptyState) emptyState.hidden = false;
    } else {
        wrap?.classList.remove('has-empty-state');
        canvas.hidden = false;
        if (emptyState) emptyState.hidden = true;
    }
}

function sanitizeGraphData(labels, datasets){
    const normalizedLabels = normalizeLabels(labels);
    const normalizedDatasets = Array.isArray(datasets) ? datasets : [];
    const keepIndexes = [];

    normalizedLabels.forEach((rawLabel, index) => {
        const label = String(rawLabel ?? '').trim();
        if (label === '' || label.toLowerCase() === 'no data') {
            return;
        }
        const labelTotal = normalizedDatasets.reduce((sum, ds) => {
            const value = Number(Array.isArray(ds.data) ? ds.data[index] : 0);
            return sum + (Number.isFinite(value) ? value : 0);
        }, 0);
        if (labelTotal <= 0) {
            return;
        }
        keepIndexes.push(index);
    });

    const sanitizedLabels = keepIndexes.map(index => normalizedLabels[index]);
    const sanitizedDatasets = normalizedDatasets.map(ds => ({
        ...ds,
        data: keepIndexes.map(index => {
            const value = Number(Array.isArray(ds.data) ? ds.data[index] : 0);
            return Number.isFinite(value) ? value : 0;
        })
    }));
    const total = sanitizedDatasets.reduce((sum, ds) => {
        return sum + (Array.isArray(ds.data) ? ds.data.reduce((a, v) => a + v, 0) : 0);
    }, 0);

    return { labels: sanitizedLabels, datasets: sanitizedDatasets, total };
}

function createReportChart(canvas, config){
    if (!canvas || !config) return null;

    const sanitized = sanitizeGraphData(config.data?.labels || [], config.data?.datasets || []);
    if (sanitized.total <= 0 || !sanitized.labels.length || !sanitized.datasets.length) {
        setChartEmptyState(canvas, true);
        return null;
    }

    config.data = config.data || {};
    config.data.labels = sanitized.labels;
    config.data.datasets = sanitized.datasets;

    setChartEmptyState(canvas, false);
    const chart = new Chart(canvas, config);
    chartInstances.push(chart);
    return chart;
}

function normalizeIncomeText(text){
    return String(text ?? '')
        .replace(/₱/g, 'P')
        .replace(/Php/gi, 'P')
        .replace(/,/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}

function normalizeAgeText(text){
    return String(text ?? '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}

function excelLegend(padding = 14){
    return {
        display: true,
        position: 'bottom',
        align: 'center',
        fullSize: true,
        labels: {
            color: '#0b1220',
            boxWidth: 14,
            boxHeight: 14,
            padding: padding,
            usePointStyle: true,
            pointStyle: 'rectRounded',
            font: {
                family: CHART_FONT_FAMILY,
                size: 13,
                weight: '600'
            }
        }
    };
}

function getAllNumericValues(datasets){
    const values = [];
    (datasets || []).forEach(ds => {
        (ds.data || []).forEach(v => {
            const n = Number(v);
            if (!Number.isNaN(n)) values.push(n);
        });
    });
    return values;
}

function getAutoStep(maxValue){
    if (maxValue <= 5) return 1;
    if (maxValue <= 10) return 2;
    if (maxValue <= 20) return 5;
    if (maxValue <= 50) return 10;
    if (maxValue <= 100) return 20;
    return Math.max(10, Math.ceil(maxValue / 5));
}

function getAutoMax(maxValue){
    if (maxValue <= 0) return 5;

    const padded = maxValue <= 10
        ? maxValue + 2
        : Math.ceil(maxValue * 1.20);

    const step = getAutoStep(padded);
    return Math.ceil(padded / step) * step;
}

function buildHorizontalOptions(config = {}, datasets = []){
    const values = getAllNumericValues(datasets);
    const actualMax = Math.max(0, ...values);
    const resolvedMax = config.xMax ?? getAutoMax(actualMax);
    const resolvedStep = config.stepSize ?? getAutoStep(resolvedMax);

    return {
        responsive: true,
        maintainAspectRatio: false,
        resizeDelay: 150,
        indexAxis: 'y',
        layout: {
            padding: { top: 10, right: 30, bottom: 10, left: 10 }
        },
        animation: false,
        plugins: {
            title: {
                display: false
            },
            tooltip: {
                enabled: true,
                backgroundColor: 'rgba(0,0,0,0.85)',
                titleColor: '#ffffff',
                bodyColor: '#ffffff',
                padding: 10
            },
            legend: config.legend ?? (datasets.length > 1 ? excelLegend(config.legendPadding ?? 14) : { display: false }),
            valueLabelPlugin: {
                showZero: false
            }
        },
        scales: {
            y: {
                offset: true,
                ticks: {
                    color: TICK,
                    autoSkip: false,
                    font: {
                        family: CHART_FONT_FAMILY,
                        size: 12,
                        weight: '600'
                    }
                },
                grid: {
                    display: false,
                    drawBorder: false
                },
                border: { display: false }
            },
            x: {
                beginAtZero: true,
                max: resolvedMax,
                suggestedMax: resolvedMax,
                grace: '10%',
                ticks: {
                    color: TICK,
                    stepSize: resolvedStep,
                    precision: INT_TICKS.precision,
                    callback: INT_TICKS.callback,
                    font: {
                        family: CHART_FONT_FAMILY,
                        size: 12
                    }
                },
                grid: {
                    display: true,
                    color: '#e8eef7',
                    lineWidth: 1,
                    drawBorder: false
                },
                border: { display: false }
            }
        }
    };
}

function buildVerticalOptions(config = {}, datasets = []){
    const values = getAllNumericValues(datasets);
    const actualMax = Math.max(0, ...values);
    const resolvedMax = config.xMax ?? getAutoMax(actualMax);
    const resolvedStep = config.stepSize ?? getAutoStep(resolvedMax);

    return {
        responsive: true,
        maintainAspectRatio: false,
        resizeDelay: 150,
        indexAxis: 'x',
        layout: {
            padding: { top: 4, right: 2, bottom: 2, left: 2 }
        },
        animation: false,
        plugins: {
            title: {
                display: false
            },
            tooltip: {
                enabled: true,
                backgroundColor: 'rgba(0,0,0,0.85)',
                titleColor: '#ffffff',
                bodyColor: '#ffffff',
                padding: 10
            },
            legend: config.legend ?? (datasets.length > 1 ? excelLegend(config.legendPadding ?? 14) : { display: false }),
            valueLabelPlugin: {
                showZero: false
            }
        },
        scales: {
            x: {
                ticks: {
                    color: TICK,
                    autoSkip: false,
                    maxRotation: 28,
                    minRotation: 0,
                    font: {
                        family: CHART_FONT_FAMILY,
                        size: 9
                    }
                },
                grid: {
                    display: false,
                    drawBorder: false
                },
                border: { display: false }
            },
            y: {
                beginAtZero: true,
                max: resolvedMax,
                suggestedMax: resolvedMax,
                grace: '10%',
                ticks: {
                    color: TICK,
                    stepSize: resolvedStep,
                    precision: INT_TICKS.precision,
                    callback: INT_TICKS.callback,
                    font: {
                        family: CHART_FONT_FAMILY,
                        size: 10
                    }
                },
                grid: {
                    display: true,
                    color: GRID,
                    lineWidth: 1,
                    drawBorder: false
                },
                border: { display: false }
            }
        }
    };
}

function buildPregnancyHorizontalOptions(config = {}, datasets = []){
    const legend = excelLegend(28);
    legend.labels = {
        ...legend.labels,
        sort: (a, b) => {
            const order = {
                'Number of Living Children': 1,
                'Total Number of Pregnancies': 2
            };
            return (order[a.text] ?? 99) - (order[b.text] ?? 99);
        }
    };

    return buildHorizontalOptions({
        ...config,
        legend
    }, datasets);
}

function mapStandardDatasetColors(datasets){
    const defaultColors = [
        EXCEL_COLORS.blue,
        EXCEL_COLORS.orange,
        EXCEL_COLORS.gray,
        EXCEL_COLORS.yellow,
        EXCEL_COLORS.lightBlue,
        EXCEL_COLORS.green
    ];

    return (datasets || []).map((ds, i) => ({
        ...ds,
        type: 'bar',
        fill: false,
        tension: 0,
        clip: false,
        backgroundColor: ds.backgroundColor || defaultColors[i % defaultColors.length],
        borderColor: ds.borderColor || defaultColors[i % defaultColors.length],
        borderWidth: 0,
        borderRadius: ds.borderRadius ?? 6,
        pointStyle: 'rectRounded',
        pointRadius: 0,
        pointHoverRadius: 0,
        barThickness: ds.barThickness ?? 'flex',
        maxBarThickness: ds.maxBarThickness ?? 28,
        categoryPercentage: ds.categoryPercentage ?? 0.72,
        barPercentage: ds.barPercentage ?? 0.80
    }));
}

function mapPartnerAgeColors(datasets){
    const colorMap = {
        'Below 18': EXCEL_COLORS.blue,
        '18-24': EXCEL_COLORS.orange,
        '25 and above': EXCEL_COLORS.gray,
        '9-14': EXCEL_COLORS.blue,
        '15-17': EXCEL_COLORS.orange,
        '18-19': EXCEL_COLORS.gray
    };

    return (datasets || []).map((ds) => ({
        ...ds,
        type: 'bar',
        fill: false,
        tension: 0,
        clip: false,
        backgroundColor: colorMap[ds.label] || ds.backgroundColor || EXCEL_COLORS.blue,
        borderColor: colorMap[ds.label] || ds.borderColor || EXCEL_COLORS.blue,
        borderWidth: 0,
        borderRadius: ds.borderRadius ?? 6,
        pointStyle: 'rectRounded',
        pointRadius: 0,
        pointHoverRadius: 0,
        barThickness: ds.barThickness ?? 'flex',
        maxBarThickness: ds.maxBarThickness ?? 28,
        categoryPercentage: ds.categoryPercentage ?? 0.72,
        barPercentage: ds.barPercentage ?? 0.80
    }));
}

function mapNeedsColors(datasets){
    const colorMap = {
        'Livelihood Training': EXCEL_COLORS.blue,
        'Skills Training': EXCEL_COLORS.orange,
        'Health Services': EXCEL_COLORS.gray,
        'Scholarship': EXCEL_COLORS.yellow,
        'Others': EXCEL_COLORS.lightBlue,
        'Livelihood training': EXCEL_COLORS.blue,
        'Skills training': EXCEL_COLORS.orange,
        'Health services': EXCEL_COLORS.gray
    };

    return (datasets || []).map((ds) => ({
        ...ds,
        type: 'bar',
        fill: false,
        tension: 0,
        clip: false,
        backgroundColor: colorMap[ds.label] || ds.backgroundColor || EXCEL_COLORS.blue,
        borderColor: colorMap[ds.label] || ds.borderColor || EXCEL_COLORS.blue,
        borderWidth: 0,
        borderRadius: ds.borderRadius ?? 6,
        pointStyle: 'rectRounded',
        pointRadius: 0,
        pointHoverRadius: 0,
        barThickness: ds.barThickness ?? 'flex',
        maxBarThickness: ds.maxBarThickness ?? 28,
        categoryPercentage: ds.categoryPercentage ?? 0.72,
        barPercentage: ds.barPercentage ?? 0.80
    }));
}

function isPartnerIncomeBucket(text){
    const n = normalizeIncomeText(text);
    return [
        'no income',
        'less than p5000',
        'less than p5,000',
        'p5001-p10000',
        'p5,001-p10,000',
        'p10001-p15000',
        'p10,001-p15,000',
        'p15000-p20000',
        'p15,000-p20,000',
        'p20001 and above',
        'p20,001 and above',
        '1-10000',
        '1-10,000',
        '1 to 10000',
        '1 to 10,000',
        '10001-20000',
        '10,001 - 20,000',
        '10001 to 20000',
        '10,001 to 20,000',
        '20001 and above',
        '20,001 and above'
    ].includes(n);
}

function canonicalPartnerIncomeLabel(text){
    const n = normalizeIncomeText(text);

    if (n === 'no income') {
        return 'No Income';
    }

    if (
        n === 'less than p5000' ||
        n === 'less than p5,000' ||
        n === 'p5001-p10000' ||
        n === 'p5,001-p10,000' ||
        n === '0-10000' ||
        n === '0-10,000' ||
        n === '0 to 10000' ||
        n === '0 to 10,000' ||
        n === '1-10000' ||
        n === '1-10,000' ||
        n === '1 to 10000' ||
        n === '1 to 10,000'
    ) {
        return '1-10,000';
    }

    if (
        n === 'p10001-p15000' ||
        n === 'p10,001-p15,000' ||
        n === 'p15000-p20000' ||
        n === 'p15,000-p20,000' ||
        n === '10001-20000' ||
        n === '10,001 - 20,000' ||
        n === '10001 to 20000' ||
        n === '10,001 to 20,000'
    ) {
        return '10,001 - 20,000';
    }

    if (
        n === 'p20001 and above' ||
        n === 'p20,001 and above' ||
        n === '20001 and above' ||
        n === '20,001 and above'
    ) {
        return '20,001 and Above';
    }

    return '';
}

function isPartnerAgeGroup(text){
    const n = normalizeAgeText(text);
    return [
        '25 and above',
        '18-24',
        'below 18',
        '18-19',
        '15-17',
        '9-14'
    ].includes(n);
}

function canonicalPartnerAgeLabel(text){
    const n = normalizeAgeText(text);

    if (n === '25 and above' || n === '18-19') return '25 and above';
    if (n === '18-24' || n === '15-17') return '18-24';
    if (n === 'below 18' || n === '9-14') return 'Below 18';

    return String(text ?? '').trim();
}

function buildPartnerIncomeChartData(rawGraphData){
    const incomeOrder = [
        'No Income',
        '1-10,000',
        '10,001 - 20,000',
        '20,001 and Above'
    ];

    const ageOrder = ['Below 18', '18-24', '25 and above'];

    const labels = Array.isArray(rawGraphData?.labels)
        ? rawGraphData.labels.map(v => String(v ?? '').trim())
        : [];

    const datasets = Array.isArray(rawGraphData?.datasets)
        ? rawGraphData.datasets
        : [];

    const labelLooksIncome = labels.some(isPartnerIncomeBucket);
    const labelLooksAge = labels.some(isPartnerAgeGroup);
    const datasetLooksIncome = datasets.some(ds => isPartnerIncomeBucket(ds?.label ?? ''));
    const datasetLooksAge = datasets.some(ds => isPartnerAgeGroup(ds?.label ?? ''));

    if (labelLooksIncome && datasetLooksAge) {
        return {
            labels: incomeOrder,
            datasets: mapPartnerAgeColors(ageOrder.map((age) => {
                const found = datasets.find(ds => canonicalPartnerAgeLabel(ds?.label ?? '') === age);
                const rawData = Array.isArray(found?.data) ? found.data : [];
                const aligned = incomeOrder.map((income, index) => {
                    const labelAtIndex = canonicalPartnerIncomeLabel(labels[index] ?? '');
                    if (labelAtIndex === income) {
                        const val = Number(rawData[index] ?? 0);
                        return Number.isNaN(val) ? 0 : val;
                    }

                    const matchedIndex = labels.findIndex(lbl => canonicalPartnerIncomeLabel(lbl) === income);
                    const val = Number(rawData[matchedIndex] ?? 0);
                    return Number.isNaN(val) ? 0 : val;
                });

                return {
                    label: age,
                    data: aligned
                };
            }))
        };
    }

    const matrix = {};
    incomeOrder.forEach(income => {
        matrix[income] = {
            'Below 18': 0,
            '18-24': 0,
            '25 and above': 0
        };
    });

    if (labelLooksAge && datasetLooksIncome) {
        datasets.forEach(ds => {
            const income = canonicalPartnerIncomeLabel(ds?.label ?? '');
            if (!incomeOrder.includes(income)) return;

            const dsData = Array.isArray(ds?.data) ? ds.data : [];
            labels.forEach((label, index) => {
                const age = canonicalPartnerAgeLabel(label);
                if (!ageOrder.includes(age)) return;

                const val = Number(dsData[index] ?? 0);
                matrix[income][age] += Number.isNaN(val) ? 0 : val;
            });
        });

        return {
            labels: incomeOrder,
            datasets: mapPartnerAgeColors([
                {
                    label: 'Below 18',
                    data: incomeOrder.map(income => Number(matrix[income]['Below 18'] ?? 0))
                },
                {
                    label: '18-24',
                    data: incomeOrder.map(income => Number(matrix[income]['18-24'] ?? 0))
                },
                {
                    label: '25 and above',
                    data: incomeOrder.map(income => Number(matrix[income]['25 and above'] ?? 0))
                }
            ])
        };
    }

    return {
        labels: incomeOrder,
        datasets: mapPartnerAgeColors([
            { label: 'Below 18', data: [0, 0, 0, 0] },
            { label: '18-24', data: [0, 0, 0, 0] },
            { label: '25 and above', data: [0, 0, 0, 0] }
        ])
    };
}

function makeStandardChart(canvasId, config){
    const canvas = document.getElementById(canvasId);
    if(!canvas || !config) return;

    const datasets = mapStandardDatasetColors(config.datasets || []);
    const axis = String(config.indexAxis || 'y').toLowerCase() === 'x' ? 'x' : 'y';

    createReportChart(canvas, {
        type: 'bar',
        data: {
            labels: normalizeLabels(config.labels || []),
            datasets: datasets
        },
        options: axis === 'y'
            ? buildHorizontalOptions(config, datasets)
            : buildVerticalOptions(config, datasets)
    });
}

function makePartnerEducationChart(canvasId){
    const canvas = document.getElementById(canvasId);
    if(!canvas) return;

    const datasets = mapPartnerAgeColors(graphData?.datasets?.length ? graphData.datasets : [
        { label: 'Below 18', data: [0, 0, 0, 0, 0, 0] },
        { label: '18-24', data: [0, 0, 0, 0, 0, 0] },
        { label: '25 and above', data: [0, 0, 0, 0, 0, 0] }
    ]);

    const labels = normalizeLabels(graphData?.labels?.length ? graphData.labels : [
        'No Education',
        'Elementary',
        'Junior High School',
        'Senior High School',
        'Vocational',
        'College Level'
    ]);

    createReportChart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: buildHorizontalOptions({
            legend: {
                display: true,
                position: 'bottom',
                align: 'center',
                fullSize: true,
                labels: {
                    color: '#0b1220',
                    boxWidth: 14,
                    boxHeight: 14,
                    padding: 16,
                    usePointStyle: true,
                    pointStyle: 'rectRounded',
                    font: {
                        family: CHART_FONT_FAMILY,
                        size: 13,
                        weight: '600'
                    },
                    sort: (a, b) => {
                        const order = { 'Below 18': 1, '18-24': 2, '25 and above': 3 };
                        return (order[a.text] ?? 99) - (order[b.text] ?? 99);
                    }
                }
            }
        }, datasets)
    });
}

function makePartnerIncomeChart(canvasId){
    const canvas = document.getElementById(canvasId);
    if(!canvas) return;

    const datasets = mapPartnerAgeColors(graphData?.datasets?.length ? graphData.datasets : [
        { label: 'Below 18', data: [0, 0, 0, 0] },
        { label: '18-24', data: [0, 0, 0, 0] },
        { label: '25 and above', data: [0, 0, 0, 0] }
    ]);

    const labels = normalizeLabels(graphData?.labels?.length ? graphData.labels : [
        'No Income',
        '1-10,000',
        '10,001 - 20,000',
        '20,001 and Above'
    ]);

    createReportChart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: buildHorizontalOptions({
            legend: {
                display: true,
                position: 'bottom',
                align: 'center',
                fullSize: true,
                labels: {
                    color: '#0b1220',
                    boxWidth: 14,
                    boxHeight: 14,
                    padding: 16,
                    usePointStyle: true,
                    pointStyle: 'rectRounded',
                    font: {
                        family: CHART_FONT_FAMILY,
                        size: 13,
                        weight: '600'
                    },
                    sort: (a, b) => {
                        const order = {
                            'Below 18': 1,
                            '18-24': 2,
                            '25 and above': 3
                        };
                        return (order[a.text] ?? 99) - (order[b.text] ?? 99);
                    }
                }
            }
        }, datasets)
    });
}

function makeMembershipChart(canvasId){
    const canvas = document.getElementById(canvasId);
    if(!canvas) return;

    const datasets = graphData?.datasets?.length ? mapPartnerAgeColors(graphData.datasets) : mapPartnerAgeColors([
        { label: '18-19', data: [0, 0, 0] },
        { label: '15-17', data: [0, 0, 0] },
        { label: '9-14', data: [0, 0, 0] }
    ]);

    const labels = normalizeLabels(graphData?.labels?.length ? graphData.labels : [
        'Both 4Ps and IPs Members',
        'IPs Members',
        '4Ps Members'
    ]);

    const maxValue = getAutoMax(Math.max(...getAllNumericValues(datasets), 0));
    const options = buildHorizontalOptions({
        xMax: maxValue,
        stepSize: getAutoStep(maxValue),
        legend: {
            display: true,
            position: 'bottom',
            align: 'center',
            fullSize: true,
            labels: {
                color: '#0b1220',
                boxWidth: 14,
                boxHeight: 14,
                padding: 18,
                usePointStyle: true,
                pointStyle: 'rectRounded',
                font: {
                    family: CHART_FONT_FAMILY,
                    size: 13,
                    weight: '600'
                },
                sort: (a, b) => {
                    const order = { '18-19': 1, '15-17': 2, '9-14': 3 };
                    return (order[a.text] ?? 99) - (order[b.text] ?? 99);
                }
            }
        }
    }, datasets);

    options.plugins.valueLabelPlugin.showZero = true;

    createReportChart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: options
    });
}

function makePregnanciesLivingChildrenChart(canvasId){
    const canvas = document.getElementById(canvasId);
    if(!canvas) return;

    const datasets = graphData?.datasets?.length ? mapStandardDatasetColors(graphData.datasets) : [
        {
            label: 'Number of Living Children',
            data: [0, 0, 0],
            backgroundColor: EXCEL_COLORS.orange,
            borderColor: EXCEL_COLORS.orange,
            borderWidth: 0,
            borderRadius: 6,
            pointStyle: 'rectRounded',
            pointRadius: 0,
            pointHoverRadius: 0,
            barThickness: 'flex',
            maxBarThickness: 28,
            categoryPercentage: 0.72,
            barPercentage: 0.80
        },
        {
            label: 'Total Number of Pregnancies',
            data: [0, 0, 0],
            backgroundColor: EXCEL_COLORS.blue,
            borderColor: EXCEL_COLORS.blue,
            borderWidth: 0,
            borderRadius: 6,
            pointStyle: 'rectRounded',
            pointRadius: 0,
            pointHoverRadius: 0,
            barThickness: 'flex',
            maxBarThickness: 28,
            categoryPercentage: 0.72,
            barPercentage: 0.80
        }
    ];

    const labels = normalizeLabels(graphData?.labels?.length ? graphData.labels : ['9-14', '15-17', '18-19']);

    createReportChart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: buildPregnancyHorizontalOptions({}, datasets)
    });
}

function makeNeedsSupportChart(canvasId){
    const canvas = document.getElementById(canvasId);
    if(!canvas) return;

    const datasets = graphData?.datasets?.length ? mapNeedsColors(graphData.datasets) : mapNeedsColors([
        { label: 'Livelihood training', data: [0, 0, 0] },
        { label: 'Skills training', data: [0, 0, 0] },
        { label: 'Health services', data: [0, 0, 0] },
        { label: 'Scholarship', data: [0, 0, 0] },
        { label: 'Others', data: [0, 0, 0] }
    ]);

    const labels = normalizeLabels(graphData?.labels?.length ? graphData.labels : ['9-14', '15-17', '18-19']);

    createReportChart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: buildHorizontalOptions({
            legendPadding: 18
        }, datasets)
    });
}

function makeHouseholdIncomeChart(canvasId){
    const canvas = document.getElementById(canvasId);
    if(!canvas) return;

    const datasets = mapStandardDatasetColors(graphData?.datasets?.length ? graphData.datasets : [
        { label: '9-14', data: [0, 0, 0, 0] },
        { label: '15-17', data: [0, 0, 0, 0] },
        { label: '18-19', data: [0, 0, 0, 0] }
    ]);

    const labels = normalizeLabels(
        graphData?.labels?.length
            ? graphData.labels
            : ['No Income', '1-10,000', '10,001 - 20,000', '20,001 and Above']
    );

    createReportChart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: buildHorizontalOptions({
            legend: {
                display: true,
                position: 'bottom',
                align: 'center',
                fullSize: true,
                labels: {
                    color: '#0b1220',
                    boxWidth: 14,
                    boxHeight: 14,
                    padding: 14,
                    usePointStyle: true,
                    pointStyle: 'rectRounded',
                    font: {
                        family: CHART_FONT_FAMILY,
                        size: 13,
                        weight: '600'
                    },
                    sort: (a, b) => {
                        const order = {
                            '9-14': 1,
                            '15-17': 2,
                            '18-19': 3
                        };
                        return (order[a.text] ?? 99) - (order[b.text] ?? 99);
                    }
                }
            }
        }, datasets)
    });
}

function loadCharts(){
    destroyCharts();

    const normalizedType = String(reportType || '')
        .toLowerCase()
        .trim()
        .replace(/-/g, '_')
        .replace(/\s+/g, '_');

    if (normalizedType === 'partner_education'){
        makePartnerEducationChart('partnerEducationChart');
        return;
    }

    if (normalizedType === 'partner_income'){
        makePartnerIncomeChart('partnerIncomeChart');
        return;
    }

    if (normalizedType === 'pregnancies_living_children'){
        makePregnanciesLivingChildrenChart('reportChartMain');
        return;
    }

    if (
        normalizedType === 'membership' ||
        normalizedType === 'membership_4ps_ip' ||
        normalizedType === 'fourps_ip_membership' ||
        normalizedType === '4ps_ip_membership' ||
        normalizedType === 'four_ps_ip_membership' ||
        normalizedType === 'ip_4ps_membership'
    ){
        makeMembershipChart('reportChartMain');
        return;
    }

    if (
        normalizedType === 'support_services' ||
        normalizedType === 'expressed_needs' ||
        normalizedType === 'government_support_needs' ||
        normalizedType === 'needs_support_services' ||
        normalizedType === 'expressed_needs_government_support'
    ){
        makeNeedsSupportChart('reportChartMain');
        return;
    }

    if (
        normalizedType === 'income' ||
        normalizedType === 'household_income' ||
        normalizedType === 'household_income_level' ||
        normalizedType === 'income_level_age_group' ||
        normalizedType === 'household_income_age_group' ||
        normalizedType.includes('household_income') ||
        normalizedType.includes('income_level') ||
        normalizedType.includes('income_age_group')
    ){
        makeHouseholdIncomeChart('reportChartMain');
        return;
    }

    makeStandardChart('reportChartMain', graphData || {});
}

async function exportGraph(){
    const exportArea = document.getElementById('graphExportArea');
    if (!exportArea) return;

    const reportTitle = String(document.querySelector('.graph-title h1')?.textContent || '').trim();
    const reportLocation = String(document.querySelector('.graph-title p')?.textContent || '').trim();

    const validCharts = chartInstances.filter((chart) => {
        if (!chart?.canvas || !(chart.canvas instanceof HTMLCanvasElement)) return false;
        if (!Array.isArray(chart.data?.labels) || !Array.isArray(chart.data?.datasets)) return false;

        const datasetTotals = chart.data.datasets.map((dataset) => {
            if (!Array.isArray(dataset.data)) return 0;
            return dataset.data.reduce((sum, value) => {
                const numeric = Number(value);
                return sum + (Number.isFinite(numeric) ? numeric : 0);
            }, 0);
        });

        return datasetTotals.some(total => total > 0);
    });

    console.log({
        reportType,
        chartCount: chartInstances.length,
        charts: validCharts.map(chart => ({
            width: chart.canvas?.width,
            height: chart.canvas?.height,
            labels: Array.isArray(chart.data?.labels) ? chart.data.labels : [],
            datasetTotals: Array.isArray(chart.data?.datasets)
                ? chart.data.datasets.map(dataset => Array.isArray(dataset.data)
                    ? dataset.data.reduce((sum, value) => sum + Number(value || 0), 0)
                    : 0)
                : []
        }))
    });

    if (!validCharts.length) {
        alert('No graph data is available for export.');
        return;
    }

    validCharts.forEach((chart) => {
        if (typeof chart.resize === 'function') chart.resize();
        if (typeof chart.update === 'function') chart.update('none');
    });

    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

    const chartBlocks = validCharts.map((chart) => {
        const canvas = chart.canvas;
        const rect = canvas.getBoundingClientRect();
        const logicalWidth = Math.max(Math.round(rect.width), 0);
        const logicalHeight = Math.max(Math.round(rect.height), 0);
        const title = String(canvas.closest('.chart-card')?.querySelector('.chart-card-title')?.textContent || '').trim();
        return {
            chart,
            canvas,
            logicalWidth,
            logicalHeight,
            title
        };
    }).filter((block) => block.logicalWidth > 0 && block.logicalHeight > 0 && block.canvas.width > 0 && block.canvas.height > 0);

    if (!chartBlocks.length) {
        alert('No rendered chart canvases were available for export.');
        return;
    }

    const padding = 36;
    const headerSpacing = 12;
    const chartSpacing = 26;
    const titleFont = `bold 28px ${CHART_FONT_FAMILY}`;
    const subtitleFont = `600 16px ${CHART_FONT_FAMILY}`;
    const chartTitleFont = `700 18px ${CHART_FONT_FAMILY}`;
    const maxContentWidth = 1600 - padding * 2;
    const intendedWidth = Math.min(maxContentWidth, Math.max(...chartBlocks.map((block) => block.logicalWidth)));
    const scale = 2;

    const chartScale = chartBlocks.some((block) => block.logicalWidth > intendedWidth)
        ? intendedWidth / Math.max(...chartBlocks.map((block) => block.logicalWidth))
        : 1;

    const titleHeight = reportTitle ? 38 : 0;
    const locationHeight = reportLocation ? 24 : 0;
    const headerHeight = titleHeight + locationHeight + (reportTitle || reportLocation ? headerSpacing : 0);

    const totalChartsHeight = chartBlocks.reduce((sum, block) => {
        const titleHeight = block.title ? 32 : 0;
        return sum + titleHeight + block.logicalHeight * chartScale + chartSpacing;
    }, 0) - chartSpacing;

    const exportLogicalWidth = Math.round(intendedWidth + padding * 2);
    const exportLogicalHeight = Math.round(padding + headerHeight + totalChartsHeight + padding);

    const exportCanvas = document.createElement('canvas');
    exportCanvas.width = exportLogicalWidth * scale;
    exportCanvas.height = exportLogicalHeight * scale;

    const ctx = exportCanvas.getContext('2d');
    if (!ctx) {
        alert('Unable to create export image context.');
        return;
    }

    ctx.scale(scale, scale);
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, exportLogicalWidth, exportLogicalHeight);

    let y = padding;
    ctx.fillStyle = '#0f172a';
    ctx.font = titleFont;
    ctx.textBaseline = 'top';
    if (reportTitle) {
        ctx.fillText(reportTitle, padding, y);
        y += titleHeight;
    }
    if (reportLocation) {
        ctx.fillStyle = '#475569';
        ctx.font = subtitleFont;
        ctx.fillText(reportLocation, padding, y);
        y += locationHeight;
    }
    if (reportTitle || reportLocation) {
        y += headerSpacing;
    }

    chartBlocks.forEach((block, index) => {
        const drawWidth = Math.round(block.logicalWidth * chartScale);
        const drawHeight = Math.round(block.logicalHeight * chartScale);
        const x = padding + Math.round((intendedWidth - drawWidth) / 2);

        if (block.title) {
            ctx.fillStyle = '#0f172a';
            ctx.font = chartTitleFont;
            ctx.fillText(block.title, padding, y);
            y += 32;
        }

        ctx.drawImage(
            block.canvas,
            0,
            0,
            block.canvas.width,
            block.canvas.height,
            x,
            y,
            drawWidth,
            drawHeight
        );

        y += drawHeight;
        if (index < chartBlocks.length - 1) {
            y += chartSpacing;
        }
    });

    const filename = '<?= preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($type ?: 'report')) ?>_<?= date('Ymd_His') ?>.png';
    const dataUrl = exportCanvas.toDataURL('image/png');
    const link = document.createElement('a');
    link.href = dataUrl;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
}

loadCharts();

window.addEventListener('resize', () => {
    chartInstances.forEach(chart => chart.resize());
});
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
