<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/report_data.php";

/* ===============================
   HELPERS
================================ */
function h(?string $value): string{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function safeExportFilename(string $type, string $extension): string
{
    $safeType = preg_replace('/[^a-zA-Z0-9_-]/', '_', $type) ?: 'report';
    return $safeType . '_' . date('Ymd_His') . '.' . $extension;
}

function csvTextCell($value): string
{
    $value = (string)$value;

    // Prevent Excel auto-converting age groups like 9-14 into Sep-14
    if (preg_match('/^\d{1,2}-\d{1,2}$/', $value)) {
        return '="' . $value . '"';
    }

    return $value;
}

function buildReportSections(
    string $type,
    array $data,
    array $ageGroups,
    array $partnerAgeGroups
): array {
    $sections = [];

    if ($type === "monthly_pregnant_status") {
        $rows = [];
        $totalNew = 0;
        $totalMothers = 0;

        foreach ($ageGroups as $g) {
            $newIdentified = (int)($data[$g]["new_identified"] ?? 0);
            $totalGroup    = (int)($data[$g]["total_mothers"] ?? 0);

            $totalNew += $newIdentified;
            $totalMothers += $totalGroup;

            $rows[] = [$g, $newIdentified, $totalGroup];
        }

        $rows[] = ["TOTAL", $totalNew, $totalMothers];

        $sections[] = [
            "title"   => "",
            "headers" => [
                "Age Group",
                "New Identified Adolescents (Current Reporting Month)",
                "Total Adolescent Mothers in the City/Municipality"
            ],
            "rows"    => $rows
        ];
    }

    if ($type === "family_planning") {
        $rows = [];
        $totalYes = 0;

        foreach ($ageGroups as $g) {
            $yes = (int)($data[$g]["yes"] ?? 0);
            $totalYes += $yes;
            $rows[] = [$g, $yes];
        }

        $rows[] = ["TOTAL", $totalYes];

        $sections[] = [
            "title"   => "",
            "headers" => [
                "Age Group",
                "Adolescent Mothers Practicing Family Planning"
            ],
            "rows"    => $rows
        ];
    }

    if ($type === "education") {
        $labels = [
            "No Education",
            "Elementary",
            "Junior High School",
            "Senior High School",
            "Vocational",
            "College Level"
        ];

        $totals = array_fill_keys($labels, 0);
        $rows = [];

        foreach ($ageGroups as $g) {
            $row = [$g];
            foreach ($labels as $label) {
                $val = (int)($data[$g][$label] ?? 0);
                $totals[$label] += $val;
                $row[] = $val;
            }
            $rows[] = $row;
        }

        $rows[] = [
            "TOTAL",
            $totals["No Education"],
            $totals["Elementary"],
            $totals["Junior High School"],
            $totals["Senior High School"],
            $totals["Vocational"],
            $totals["College Level"]
        ];

        $sections[] = [
            "title"   => "",
            "headers" => [
                "Age Group",
                "No Education",
                "Elementary",
                "Junior High School",
                "Senior High School",
                "Vocational",
                "College Level"
            ],
            "rows"    => $rows
        ];
    }

    if ($type === "civil_status") {
        $rows = [];
        $totalSingle = 0;
        $totalMarried = 0;
        $totalLiving = 0;

        foreach ($ageGroups as $g) {
            $single  = (int)($data[$g]["single"] ?? 0);
            $married = (int)($data[$g]["married"] ?? 0);
            $living  = (int)($data[$g]["living-in"] ?? 0);

            $totalSingle += $single;
            $totalMarried += $married;
            $totalLiving += $living;

            $rows[] = [$g, $single, $married, $living];
        }

        $rows[] = ["TOTAL", $totalSingle, $totalMarried, $totalLiving];

        $sections[] = [
            "title"   => "",
            "headers" => ["Age Group", "Single", "Married", "Living-in"],
            "rows"    => $rows
        ];
    }

    if ($type === "prenatal") {
        $rows = [];
        $totalPregnant = 0;
        $totalAttended = 0;
        $totalNot = 0;

        foreach ($ageGroups as $g) {
            $preg = (int)($data[$g]["total"] ?? 0);
            $att  = (int)($data[$g]["attended"] ?? 0);
            $not  = (int)($data[$g]["not"] ?? 0);

            $totalPregnant += $preg;
            $totalAttended += $att;
            $totalNot += $not;

            $rows[] = [$g, $preg, $att, $not];
        }

        $rows[] = ["TOTAL", $totalPregnant, $totalAttended, $totalNot];

        $sections[] = [
            "title"   => "",
            "headers" => [
                "Age Group",
                "Number of Pregnant Adolescents",
                "Attended Prenatal Care",
                "Did Not Attend Prenatal Care"
            ],
            "rows"    => $rows
        ];
    }

    if ($type === "first_pregnancy_9_14") {
        $fp9_9 = (int)($data["9-14"]["first_age"][9] ?? 0);
        $fp9_10 = (int)($data["9-14"]["first_age"][10] ?? 0);
        $fp9_11 = (int)($data["9-14"]["first_age"][11] ?? 0);
        $fp9_12 = (int)($data["9-14"]["first_age"][12] ?? 0);
        $fp9_13 = (int)($data["9-14"]["first_age"][13] ?? 0);
        $fp9_14 = (int)($data["9-14"]["first_age"][14] ?? 0);

        $sections[] = [
            "title"   => "Adolescent Mothers Aged 9-14 by Age at First Pregnancy",
            "headers" => ["Age Group", "9", "10", "11", "12", "13", "14"],
            "rows"    => [
                ["9-14", $fp9_9, $fp9_10, $fp9_11, $fp9_12, $fp9_13, $fp9_14],
                ["TOTAL", $fp9_9, $fp9_10, $fp9_11, $fp9_12, $fp9_13, $fp9_14],
            ]
        ];
    }

    if ($type === "first_pregnancy_15_17") {
        $fp15_9_14 =
            (int)($data["15-17"]["first_age"][9] ?? 0) +
            (int)($data["15-17"]["first_age"][10] ?? 0) +
            (int)($data["15-17"]["first_age"][11] ?? 0) +
            (int)($data["15-17"]["first_age"][12] ?? 0) +
            (int)($data["15-17"]["first_age"][13] ?? 0) +
            (int)($data["15-17"]["first_age"][14] ?? 0);

        $fp15_15 = (int)($data["15-17"]["first_age"][15] ?? 0);
        $fp15_16 = (int)($data["15-17"]["first_age"][16] ?? 0);
        $fp15_17 = (int)($data["15-17"]["first_age"][17] ?? 0);

        $sections[] = [
            "title"   => "Adolescent Mothers Aged 15-17 by Age at First Pregnancy",
            "headers" => ["Age Group", "9-14", "15", "16", "17"],
            "rows"    => [
                ["15-17", $fp15_9_14, $fp15_15, $fp15_16, $fp15_17],
                ["TOTAL", $fp15_9_14, $fp15_15, $fp15_16, $fp15_17],
            ]
        ];
    }

    if ($type === "first_pregnancy_18_19") {
        $fp18_9_14 =
            (int)($data["18-19"]["first_age"][9] ?? 0) +
            (int)($data["18-19"]["first_age"][10] ?? 0) +
            (int)($data["18-19"]["first_age"][11] ?? 0) +
            (int)($data["18-19"]["first_age"][12] ?? 0) +
            (int)($data["18-19"]["first_age"][13] ?? 0) +
            (int)($data["18-19"]["first_age"][14] ?? 0);

        $fp18_15_17 =
            (int)($data["18-19"]["first_age"][15] ?? 0) +
            (int)($data["18-19"]["first_age"][16] ?? 0) +
            (int)($data["18-19"]["first_age"][17] ?? 0);

        $fp18_18 = (int)($data["18-19"]["first_age"][18] ?? 0);
        $fp18_19 = (int)($data["18-19"]["first_age"][19] ?? 0);

        $sections[] = [
            "title"   => "Adolescent Mothers Aged 18-19 by Age at First Pregnancy",
            "headers" => ["Age Group", "9-14", "15-17", "18", "19"],
            "rows"    => [
                ["18-19", $fp18_9_14, $fp18_15_17, $fp18_18, $fp18_19],
                ["TOTAL", $fp18_9_14, $fp18_15_17, $fp18_18, $fp18_19],
            ]
        ];
    }

    if ($type === "pregnancies_living_children") {
        $tp1014 = (int)($data["9-14"]["total_pregnancies"] ?? 0);
        $tp1517 = (int)($data["15-17"]["total_pregnancies"] ?? 0);
        $tp1819 = (int)($data["18-19"]["total_pregnancies"] ?? 0);

        $lc1014 = (int)($data["9-14"]["living_children"] ?? 0);
        $lc1517 = (int)($data["15-17"]["living_children"] ?? 0);
        $lc1819 = (int)($data["18-19"]["living_children"] ?? 0);

        $sections[] = [
            "title"   => "Adolescent Mothers by Total Number of Pregnancies, Total Number of Living Children, and Age Group",
            "headers" => ["Age Group", "Total Number of Pregnancies", "Number of Living Children"],
            "rows"    => [
                ["9-14", $tp1014, $lc1014],
                ["15-17", $tp1517, $lc1517],
                ["18-19", $tp1819, $lc1819],
                ["TOTAL", $tp1014 + $tp1517 + $tp1819, $lc1014 + $lc1517 + $lc1819],
            ]
        ];
    }

    if ($type === "membership") {
        $rows = [];
        $total4ps = 0;
        $totalIps = 0;
        $totalBoth = 0;

        foreach ($ageGroups as $g) {
            $m4ps  = (int)($data[$g]["is_4ps"] ?? 0);
            $mIps  = (int)($data[$g]["is_ips"] ?? 0);
            $mBoth = (int)($data[$g]["both"] ?? 0);

            $total4ps += $m4ps;
            $totalIps += $mIps;
            $totalBoth += $mBoth;

            $rows[] = [$g, $m4ps, $mIps, $mBoth];
        }

        $rows[] = ["TOTAL", $total4ps, $totalIps, $totalBoth];

        $sections[] = [
            "title"   => "",
            "headers" => [
                "Age Group",
                "Total Number of Pregnant Adolescents and Adolescent Mothers who are 4Ps Members",
                "Total Number of Pregnant Adolescents and Adolescent Mothers who are IP Members",
                "Total Number of Pregnant Adolescents and Adolescent Mothers who are both 4Ps and IP Members"
            ],
            "rows"    => $rows
        ];
    }

    if ($type === "income") {
        $labels = [
            "No Income",
            "Less than P5,000",
            "P5,001-P10,000",
            "P10,001-P15,000",
            "P15,000-P20,000",
            "P20,001 and above"
        ];

        $totals = array_fill_keys($labels, 0);
        $rows = [];

        foreach ($ageGroups as $g) {
            $row = [$g];
            foreach ($labels as $label) {
                $val = (int)($data[$g][$label] ?? 0);
                $totals[$label] += $val;
                $row[] = $val;
            }
            $rows[] = $row;
        }

        $rows[] = array_merge(
            ["TOTAL"],
            array_map(fn($label) => $totals[$label], $labels)
        );

        $sections[] = [
            "title"   => "",
            "headers" => array_merge(["Age Group"], $labels),
            "rows"    => $rows
        ];
    }

    if ($type === "support_services") {
        $labels = [
            "Livelihood training",
            "Skills training",
            "Health services",
            "Scholarship",
            "Others"
        ];

        $totals = array_fill_keys($labels, 0);
        $rows = [];

        foreach ($ageGroups as $g) {
            $row = [$g];
            foreach ($labels as $label) {
                $val = (int)($data[$g][$label] ?? 0);
                $totals[$label] += $val;
                $row[] = $val;
            }
            $rows[] = $row;
        }

        $rows[] = array_merge(
            ["TOTAL"],
            array_map(fn($label) => $totals[$label], $labels)
        );

        $sections[] = [
            "title"   => "",
            "headers" => array_merge(["Age Group"], $labels),
            "rows"    => $rows
        ];
    }

    if ($type === "partner_education") {
        $partnerEduLabels = [
            "No Education",
            "Elementary",
            "Junior High School",
            "Senior High School",
            "Vocational",
            "College Level"
        ];

        $eduTotals = array_fill_keys($partnerEduLabels, 0);
        $eduRows = [];

        foreach ($partnerAgeGroups as $pg) {
            $row = [$pg];
            foreach ($partnerEduLabels as $label) {
                $val = (int)($data[$pg]["education"][$label] ?? 0);
                $eduTotals[$label] += $val;
                $row[] = $val;
            }
            $eduRows[] = $row;
        }

        $eduRows[] = array_merge(["TOTAL"], array_map(fn($label) => $eduTotals[$label], $partnerEduLabels));

        $sections[] = [
            "title"   => "Educational Attainment of Partners, by Age Group",
            "headers" => array_merge(["Partner Age Group"], $partnerEduLabels),
            "rows"    => $eduRows
        ];
    }

    if ($type === "partner_income") {
        $partnerIncomeLabels = [
            "No Income",
            "Less than P5,000",
            "P5,001-P10,000",
            "P10,001-P15,000",
            "P15,000-P20,000",
            "P20,001 and above"
        ];

        $incomeTotals = array_fill_keys($partnerIncomeLabels, 0);
        $incomeRows = [];

        foreach ($partnerAgeGroups as $pg) {
            $row = [$pg];
            foreach ($partnerIncomeLabels as $label) {
                $val = (int)($data[$pg]["income"][$label] ?? 0);
                $incomeTotals[$label] += $val;
                $row[] = $val;
            }
            $incomeRows[] = $row;
        }

        $incomeRows[] = array_merge(["TOTAL"], array_map(fn($label) => $incomeTotals[$label], $partnerIncomeLabels));

        $sections[] = [
            "title"   => "Monthly Income Range of Partners, by Age Group",
            "headers" => array_merge(["Partner Age Group"], $partnerIncomeLabels),
            "rows"    => $incomeRows
        ];
    }

    return $sections;
}

/* ===============================
   INPUTS
================================ */
$type   = (string)($_GET['type'] ?? '');
$format = strtolower(trim((string)($_GET['format'] ?? '')));

$sections = buildReportSections($type, $data, $ageGroups, $partnerAgeGroups);

/* ===============================
   CSV EXPORT
   IMPORTANT: MUST RUN BEFORE ANY HTML OUTPUT
================================ */
if ($format === 'csv' || $format === 'excel') {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $filename = safeExportFilename($type, 'csv');

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit('Unable to create CSV export.');
    }

    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, [(string)$municipalityName]);
    fputcsv($out, [(string)$title]);
    fputcsv($out, []);

    foreach ($sections as $index => $section) {
        if (!empty($section['title'])) {
            fputcsv($out, [csvTextCell((string)$section['title'])]);
        }

        fputcsv($out, array_map('csvTextCell', $section['headers']));

        foreach ($section['rows'] as $row) {
            $csvRow = array_map('csvTextCell', $row);
            fputcsv($out, $csvRow);
        }

        if ($index < count($sections) - 1) {
            fputcsv($out, []);
        }
    }

    fclose($out);
    exit;
}

/* ===============================
   NORMAL PAGE VIEW ONLY
================================ */
$pageTitle = "Export Report";
require_once __DIR__ . "/../includes/header.php";
require_once __DIR__ . "/../includes/sidebar.php";
?>

<style>
.export-page{
    max-width: 1240px;
    margin: 0 auto;
    padding: 18px 0 30px;
}
.export-shell{
    background: #fff;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 18px 40px rgba(15,23,42,.08);
    padding: 28px;
}
.export-actions{
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 22px;
}
.export-btn{
    appearance: none;
    border: none;
    border-radius: 14px;
    padding: 12px 18px;
    font-size: 14px;
    font-weight: 700;
    color: #fff;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition:
        background-color 120ms ease,
        color 120ms ease,
        border-color 120ms ease,
        box-shadow 120ms ease,
        opacity 120ms ease;
}
.export-btn:hover{
    box-shadow: 0 10px 18px rgba(15,23,42,.12);
    text-decoration: none;
    color: #fff;
}
.btn-back{ background: #2563eb; }
.btn-back:hover{ background: #1d4ed8; }
.btn-excel{ background: #059669; }
.btn-excel:hover{ background: #047857; }
.btn-png{ background: #ea580c; }
.btn-png:hover{ background: #c2410c; }
.export-report-card{
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    padding: 34px 32px 28px;
}
.export-report-header{
    text-align: center;
    margin-bottom: 26px;
}
.export-report-header img{
    width: 82px;
    height: 82px;
    object-fit: contain;
    margin: 0 auto 12px;
    display: block;
    background: #fff;
}
.export-report-header h2{
    margin: 0 0 8px;
    font-size: 20px;
    font-weight: 800;
    color: #111827;
}
.export-report-header p{
    margin: 0;
    font-size: 16px;
    color: #475569;
}
.export-report-section{
    margin-bottom: 28px;
}
.export-report-section:last-child{
    margin-bottom: 0;
}
.export-report-section h3{
    text-align: center;
    margin: 0 0 12px;
    color: #1f2937;
    font-size: 18px;
    font-weight: 800;
}
.export-report-table{
    width: 100%;
    border-collapse: collapse;
    table-layout: auto;
    background: #fff;
}
.export-report-table th,
.export-report-table td{
    border: 1px solid #9ca3af;
    padding: 12px 10px;
    font-size: 14px;
    text-align: center;
    vertical-align: middle;
    color: #111827;
}
.export-report-table th{
    background: #e5e7eb;
    font-weight: 800;
}
.export-report-table tr.total-row td{
    background: #f3f4f6;
    font-weight: 800;
}
.export-error{
    margin-top: 12px;
    padding: 12px 14px;
    border-radius: 10px;
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
@media (max-width: 768px){
    .export-shell{
        padding: 18px;
        border-radius: 16px;
    }
    .export-report-card{
        padding: 20px 16px;
    }
    .export-actions{
        flex-direction: column;
    }
    .export-btn{
        width: 100%;
    }
    .export-report-table th,
    .export-report-table td{
        font-size: 12px;
        padding: 9px 6px;
    }
}
</style>

<div class="export-page">
    <div class="export-shell">

        <div class="export-actions" id="exportToolbar">
            <a class="export-btn btn-back" href="report_table.php?type=<?= urlencode($type) ?>">Back to Report</a>

            <a class="export-btn btn-excel"
               href="report_export_excel.php?type=<?= urlencode($type) ?>&format=csv">
                EXPORT EXCEL
            </a>

            <button type="button" class="export-btn btn-png" id="downloadPngBtn">
                Download PNG
            </button>
        </div>

        <div id="pngReport" class="export-report-card">
            <div class="export-report-header">
                <?php if (!empty($municipalityLogo)): ?>
                    <img
                        src="<?= h((string)$municipalityLogo) ?>"
                        alt="Province/Municipality Logo"
                        crossorigin="anonymous"
                    >
                <?php endif; ?>

                <h2><?= h((string)$municipalityName) ?></h2>
                <p><?= h((string)$title) ?></p>

                <?php if ($reportError !== ''): ?>
                    <div class="export-error"><?= h((string)$reportError) ?></div>
                <?php endif; ?>
            </div>

            <?php foreach ($sections as $section): ?>
                <div class="export-report-section">
                    <?php if (!empty($section['title'])): ?>
                        <h3><?= h((string)$section['title']) ?></h3>
                    <?php endif; ?>

                    <table class="export-report-table">
                        <tr>
                            <?php foreach ($section['headers'] as $headerCell): ?>
                                <th><?= h((string)$headerCell) ?></th>
                            <?php endforeach; ?>
                        </tr>

                        <?php foreach ($section['rows'] as $row): ?>
                            <?php $isTotalRow = isset($row[0]) && strtoupper((string)$row[0]) === 'TOTAL'; ?>
                            <tr class="<?= $isTotalRow ? 'total-row' : '' ?>">
                                <?php foreach ($row as $cell): ?>
                                    <td><?= h((string)$cell) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>

<script>
(function(){
    const btn = document.getElementById('downloadPngBtn');
    const report = document.getElementById('pngReport');
    const toolbar = document.getElementById('exportToolbar');

    if (!btn || !report) return;

    btn.addEventListener('click', async function(){
        const oldText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Generating PNG...';

        try {
            if (typeof html2canvas !== 'function') {
                alert('PNG export is unavailable because the local screen-capture asset is missing.');
                return;
            }

            if (toolbar) toolbar.style.visibility = 'hidden';

            const canvas = await html2canvas(report, {
                backgroundColor: '#ffffff',
                scale: 2,
                useCORS: true,
                allowTaint: true
            });

            const link = document.createElement('a');
            link.href = canvas.toDataURL('image/png');
            link.download = '<?= preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($type ?: 'report')) ?>_<?= date('Ymd_His') ?>.png';
            document.body.appendChild(link);
            link.click();
            link.remove();
        } catch (error) {
            alert('PNG export failed. Please make sure the logo image is accessible and try again.');
            console.error(error);
        } finally {
            if (toolbar) toolbar.style.visibility = 'visible';
            btn.disabled = false;
            btn.textContent = oldText;
        }
    });
})();
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
