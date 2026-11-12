<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/auth.php";

$pageTitle = "Report Table";
require_once __DIR__ . "/../includes/header.php";
require_once __DIR__ . "/../includes/sidebar.php";
require_once __DIR__ . "/report_data.php";


if (!function_exists('normalizeIncomeBucketLabel')) {
    function normalizeIncomeBucketLabel(string $label): string
    {
        $original = trim($label);

        $normalized = str_replace(['₱', 'Php', 'php', ','], ['P', 'P', 'P', ''], $original);
        $normalized = preg_replace('/\s+/', ' ', (string)$normalized);
        $normalized = strtolower(trim((string)$normalized));

        // No Income
        if (
            $normalized === 'no income' ||
            $normalized === 'none' ||
            $normalized === 'no monthly income' ||
            $normalized === 'without income' ||
            $normalized === 'walay income' ||
            $normalized === 'walay kita' ||
            $normalized === 'not earning' ||
            $normalized === 'unemployed no income' ||
            $normalized === '0' ||
            $normalized === 'p0' ||
            $normalized === '0 income'
        ) {
            return 'No Income';
        }

        // 1-10,000
        if (
            $normalized === '1-10000' ||
            $normalized === '1 - 10000' ||
            $normalized === '1-10,000' ||
            $normalized === '1 - 10,000' ||
            $normalized === '1 to 10000' ||
            $normalized === '1 to 10,000' ||
            $normalized === 'p1-p10000' ||
            $normalized === 'p1-p10,000' ||
            $normalized === 'p1 to p10000' ||
            $normalized === 'p1 to p10,000' ||
            $normalized === 'less than p5000' ||
            $normalized === 'less than p5,000' ||
            $normalized === 'p5001-p10000' ||
            $normalized === 'p5,001-p10,000' ||
            $normalized === '0-10000' ||
            $normalized === '0-10,000' ||
            $normalized === '0 to 10000' ||
            $normalized === '0 to 10,000'
        ) {
            return '1-10,000';
        }

        // 10,001 - 20,000
        if (
            $normalized === 'p10001-p15000' ||
            $normalized === 'p10,001-p15,000' ||
            $normalized === 'p15000-p20000' ||
            $normalized === 'p15,000-p20,000' ||
            $normalized === '10001-20000' ||
            $normalized === '10,001-20,000' ||
            $normalized === '10,001 - 20,000' ||
            $normalized === '10001 to 20000' ||
            $normalized === '10,001 to 20,000'
        ) {
            return '10,001 - 20,000';
        }

        // 20,001 and Above
        if (
            $normalized === 'p20001 and above' ||
            $normalized === 'p20,001 and above' ||
            $normalized === '20001 and above' ||
            $normalized === '20,001 and above' ||
            $normalized === '20001-above' ||
            $normalized === '20,001-above' ||
            $normalized === 'above 20000' ||
            $normalized === 'above 20,000'
        ) {
            return '20,001 and Above';
        }

        return $original;
    }
}

if (!function_exists('getMergedIncomeValueFromFlatGroup')) {
    function getMergedIncomeValueFromFlatGroup(array $groupData, string $targetBucket): int
    {
        $total = 0;

        foreach ($groupData as $label => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $normalized = normalizeIncomeBucketLabel((string)$label);
            if ($normalized === $targetBucket) {
                $total += (int)$value;
            }
        }

        return $total;
    }
}

if (!function_exists('getMergedIncomeValueFromNestedGroup')) {
    function getMergedIncomeValueFromNestedGroup(array $groupData, string $nestedKey, string $targetBucket): int
    {
        $nested = $groupData[$nestedKey] ?? [];
        if (!is_array($nested)) {
            return 0;
        }

        return getMergedIncomeValueFromFlatGroup($nested, $targetBucket);
    }
}
?>

<style>
.reports-page{
    width: 100%;
    max-width: none;
    margin: 0;
    padding: 18px 18px 28px;
    background:
        radial-gradient(900px 420px at 20% 0%, rgba(22,58,115,.10), transparent 60%),
        radial-gradient(760px 380px at 86% 18%, rgba(236,72,153,.08), transparent 55%),
        linear-gradient(180deg, rgba(255,255,255,.55), rgba(255,255,255,0)),
        #f4f6fb;
    border-radius: 18px;
    box-sizing: border-box;
}

.reports-container{
    max-width: 1180px;
    margin: 0 auto;
    background: #fff;
    padding: 30px;
    border-radius: 18px;
    box-shadow: 0 18px 55px rgba(15,23,42,.10);
    border: 1px solid rgba(15,23,42,.08);
}

.report-actions{
    margin-bottom: 24px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.report-actions a{
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 16px;
    border-radius: 14px;
    border: none;
    cursor: pointer;
    font-size: 14px;
    color: #fff;
    text-decoration: none;
    font-weight: 800;
    transition:
        background-color 120ms ease,
        color 120ms ease,
        border-color 120ms ease,
        box-shadow 120ms ease,
        opacity 120ms ease;
    box-shadow: 0 10px 18px rgba(15,23,42,.10);
}

.report-actions a:hover{
    text-decoration: none;
    color: #fff;
}

.btn-back{
    background: #64748b;
}
.btn-back:hover{
    background: #475569;
}

.btn-graph{
    background: #2563eb;
}
.btn-graph:hover{
    background: #1d4ed8;
}

.btn-excel{
    background: #059669;
}
.btn-excel:hover{
    background: #047857;
}

.report-header{
    text-align: center;
    margin-bottom: 25px;
}

.report-header img{
    width: 78px;
    height: 78px;
    object-fit: contain;
    margin: 0 auto 10px;
    background: #fff;
    display: block;
}

.report-header h2{
    margin: 0 0 8px;
    font-size: 18px;
    font-weight: 800;
    color: #111827;
}

.report-header p{
    margin: 0;
    color: #475569;
    font-size: 15px;
}

.report-block{
    margin-bottom: 28px;
}

.report-block h3{
    margin-bottom: 12px;
    font-size: 18px;
    color: #1e293b;
    text-align: center;
    font-weight: 800;
}

table{
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    font-size: 14px;
    background: #fff;
}

th, td{
    padding: 12px 10px;
    border: 1px solid #a8adb7;
    text-align: center;
    vertical-align: middle;
}

th{
    background: #e5e7eb;
    font-weight: 800;
}

.total-row{
    font-weight: 800;
    background: #f3f4f6;
}

.section{
    transition: opacity 120ms ease;
}

.meta-note{
    margin-top: 14px;
    font-size: 12px;
    color: #64748b;
}

.report-error{
    margin-top: 15px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    padding: 12px 14px;
    border-radius: 8px;
    font-weight: 700;
}

.report-page-footer{
    max-width: 1180px;
    margin: 18px auto 0;
    background: #1d4588;
    border-radius: 0;
    padding: 34px 24px;
    text-align: center;
    box-shadow: 0 10px 24px rgba(15,23,42,.10);
}

.report-page-footer p{
    margin: 0;
    line-height: 1.7;
}

.report-page-footer .footer-main{
    color: #ffffff;
    font-size: 16px;
    font-weight: 500;
}

.report-page-footer .footer-sub{
    color: #facc15;
    font-size: 15px;
    margin-top: 10px;
    font-weight: 500;
}

@media (max-width: 768px){
    .reports-page{
        padding: 12px 12px 24px;
    }

    .reports-container{
        padding: 18px;
        border-radius: 14px;
    }

    .report-actions{
        flex-direction: column;
    }

    .report-actions a{
        width: 100%;
        text-align: center;
    }

    .report-header img{
        width: 70px;
        height: 70px;
    }

    table{
        font-size: 13px;
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }

    th, td{
        padding: 10px 8px;
    }

    .report-page-footer{
        padding: 24px 16px;
    }

    .report-page-footer .footer-main{
        font-size: 14px;
    }

    .report-page-footer .footer-sub{
        font-size: 13px;
    }
}
</style>

<div class="reports-page">

    <div class="reports-container">

        <div class="report-actions">
            <a class="btn-back" href="index.php">← Back</a>

            <a class="btn-graph" href="report_graph.php?type=<?= urlencode((string)$type) ?>">
                View Graph
            </a>

            <a class="btn-excel" href="report_export_excel.php?type=<?= urlencode((string)$type) ?>">
                Export Excel Report
            </a>
        </div>

        <div class="report-header">
            <?php if (!empty($municipalityLogo)): ?>
                <img
                    src="<?= htmlspecialchars((string)$municipalityLogo, ENT_QUOTES, 'UTF-8') ?>"
                    alt="Province/Municipality Logo"
                >
            <?php endif; ?>

            <h2><?= htmlspecialchars((string)$municipalityName, ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') ?></p>

            <?php if ($reportError !== ''): ?>
                <div class="report-error"><?= htmlspecialchars((string)$reportError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>

        <div class="section">

            <?php if($type === "monthly_pregnant_status"): ?>
                <?php
                $totalNew = 0;
                $totalMothers = 0;
                ?>
                <div class="report-block">
                    <table>
                        <tr>
                            <th>Age Group</th>
                            <th>New Identified Adolescents (Current Reporting Month)</th>
                            <th>Total Adolescent Mothers in the City/Municipality</th>
                        </tr>

                        <?php foreach($ageGroups as $g): ?>
                            <?php
                            $newIdentified = $data[$g]["new_identified"] ?? 0;
                            $totalGroup = $data[$g]["total_mothers"] ?? 0;
                            $totalNew += $newIdentified;
                            $totalMothers += $totalGroup;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$g, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $newIdentified ?></td>
                                <td><?= $totalGroup ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="total-row">
                            <td>TOTAL</td>
                            <td><?= $totalNew ?></td>
                            <td><?= $totalMothers ?></td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <?php if($type === "family_planning"): ?>
                <?php $totalYes = 0; ?>
                <div class="report-block">
                    <table>
                        <tr>
                            <th>Age Group</th>
                            <th>Adolescent Mothers Practicing Family Planning</th>
                        </tr>

                        <?php foreach($ageGroups as $g): ?>
                            <?php
                            $yes = $data[$g]["yes"] ?? 0;
                            $totalYes += $yes;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$g, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $yes ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="total-row">
                            <td>TOTAL</td>
                            <td><?= $totalYes ?></td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <?php if($type === "education"): ?>
                <?php
                $totals = [
                    "No Education" => 0,
                    "Elementary" => 0,
                    "Junior High School" => 0,
                    "Senior High School" => 0,
                    "Vocational" => 0,
                    "College Level" => 0
                ];
                ?>
                <div class="report-block">
                    <table>
                        <tr>
                            <th>Age Group</th>
                            <th>No Education</th>
                            <th>Elementary</th>
                            <th>Junior High School</th>
                            <th>Senior High School</th>
                            <th>Vocational</th>
                            <th>College Level</th>
                        </tr>

                        <?php foreach($ageGroups as $g): ?>
                            <?php
                            $noEducation = $data[$g]["No Education"] ?? 0;
                            $elementary = $data[$g]["Elementary"] ?? 0;
                            $junior = $data[$g]["Junior High School"] ?? 0;
                            $senior = $data[$g]["Senior High School"] ?? 0;
                            $vocational = $data[$g]["Vocational"] ?? 0;
                            $college = $data[$g]["College Level"] ?? 0;

                            $totals["No Education"] += $noEducation;
                            $totals["Elementary"] += $elementary;
                            $totals["Junior High School"] += $junior;
                            $totals["Senior High School"] += $senior;
                            $totals["Vocational"] += $vocational;
                            $totals["College Level"] += $college;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$g, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $noEducation ?></td>
                                <td><?= $elementary ?></td>
                                <td><?= $junior ?></td>
                                <td><?= $senior ?></td>
                                <td><?= $vocational ?></td>
                                <td><?= $college ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="total-row">
                            <td>TOTAL</td>
                            <td><?= $totals["No Education"] ?></td>
                            <td><?= $totals["Elementary"] ?></td>
                            <td><?= $totals["Junior High School"] ?></td>
                            <td><?= $totals["Senior High School"] ?></td>
                            <td><?= $totals["Vocational"] ?></td>
                            <td><?= $totals["College Level"] ?></td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <?php if($type === "civil_status"): ?>
                <?php
                $totalSingle = 0;
                $totalMarried = 0;
                $totalLiving = 0;
                ?>
                <div class="report-block">
                    <table>
                        <tr>
                            <th>Age Group</th>
                            <th>Single</th>
                            <th>Married</th>
                            <th>Living-in</th>
                        </tr>

                        <?php foreach($ageGroups as $g): ?>
                            <?php
                            $single = $data[$g]["single"] ?? 0;
                            $married = $data[$g]["married"] ?? 0;
                            $living = $data[$g]["living-in"] ?? 0;

                            $totalSingle += $single;
                            $totalMarried += $married;
                            $totalLiving += $living;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$g, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $single ?></td>
                                <td><?= $married ?></td>
                                <td><?= $living ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="total-row">
                            <td>TOTAL</td>
                            <td><?= $totalSingle ?></td>
                            <td><?= $totalMarried ?></td>
                            <td><?= $totalLiving ?></td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <?php if($type === "prenatal"): ?>
                <?php
                $totalPregnant = 0;
                $totalAttended = 0;
                $totalNot = 0;
                ?>
                <div class="report-block">
                    <table>
                        <tr>
                            <th>Age Group</th>
                            <th>Number of Pregnant Adolescents</th>
                            <th>Attended Prenatal Care</th>
                            <th>Did Not Attend Prenatal Care</th>
                        </tr>

                        <?php foreach($ageGroups as $g): ?>
                            <?php
                            $preg = $data[$g]["total"] ?? 0;
                            $att = $data[$g]["attended"] ?? 0;
                            $not = $data[$g]["not"] ?? 0;

                            $totalPregnant += $preg;
                            $totalAttended += $att;
                            $totalNot += $not;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$g, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $preg ?></td>
                                <td><?= $att ?></td>
                                <td><?= $not ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="total-row">
                            <td>TOTAL</td>
                            <td><?= $totalPregnant ?></td>
                            <td><?= $totalAttended ?></td>
                            <td><?= $totalNot ?></td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <?php if($type === "first_pregnancy_9_14"): ?>
                <?php
                $fp9_9 = $data["9-14"]["first_age"][9] ?? 0;
                $fp9_10 = $data["9-14"]["first_age"][10] ?? 0;
                $fp9_11 = $data["9-14"]["first_age"][11] ?? 0;
                $fp9_12 = $data["9-14"]["first_age"][12] ?? 0;
                $fp9_13 = $data["9-14"]["first_age"][13] ?? 0;
                $fp9_14 = $data["9-14"]["first_age"][14] ?? 0;
                ?>
                <div class="report-block">
                    <h3>Adolescent Mothers Aged 9-14 by Age at First Pregnancy</h3>
                    <table>
                        <tr>
                            <th rowspan="2">Age Group</th>
                            <th colspan="6">Age at First Pregnancy</th>
                        </tr>
                        <tr>
                            <th>9 years old</th>
                            <th>10 years old</th>
                            <th>11 years old</th>
                            <th>12 years old</th>
                            <th>13 years old</th>
                            <th>14 years old</th>
                        </tr>
                        <tr>
                            <td>9-14</td>
                            <td><?= $fp9_9 ?></td>
                            <td><?= $fp9_10 ?></td>
                            <td><?= $fp9_11 ?></td>
                            <td><?= $fp9_12 ?></td>
                            <td><?= $fp9_13 ?></td>
                            <td><?= $fp9_14 ?></td>
                        </tr>
                        <tr class="total-row">
                            <td>TOTAL</td>
                            <td><?= $fp9_9 ?></td>
                            <td><?= $fp9_10 ?></td>
                            <td><?= $fp9_11 ?></td>
                            <td><?= $fp9_12 ?></td>
                            <td><?= $fp9_13 ?></td>
                            <td><?= $fp9_14 ?></td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <?php if($type === "first_pregnancy_15_17"): ?>
                <?php
                $fp15_9_14 =
                    ($data["15-17"]["first_age"][9] ?? 0) +
                    ($data["15-17"]["first_age"][10] ?? 0) +
                    ($data["15-17"]["first_age"][11] ?? 0) +
                    ($data["15-17"]["first_age"][12] ?? 0) +
                    ($data["15-17"]["first_age"][13] ?? 0) +
                    ($data["15-17"]["first_age"][14] ?? 0);

                $fp15_15 = $data["15-17"]["first_age"][15] ?? 0;
                $fp15_16 = $data["15-17"]["first_age"][16] ?? 0;
                $fp15_17 = $data["15-17"]["first_age"][17] ?? 0;
                ?>
                <div class="report-block">
                    <h3>Adolescent Mothers Aged 15-17 by Age at First Pregnancy</h3>
                    <table>
                        <tr>
                            <th rowspan="2">Age Group</th>
                            <th colspan="4">Age at First Pregnancy</th>
                        </tr>
                        <tr>
                            <th>9-14 years old</th>
                            <th>15 years old</th>
                            <th>16 years old</th>
                            <th>17 years old</th>
                        </tr>
                        <tr>
                            <td>15-17</td>
                            <td><?= $fp15_9_14 ?></td>
                            <td><?= $fp15_15 ?></td>
                            <td><?= $fp15_16 ?></td>
                            <td><?= $fp15_17 ?></td>
                        </tr>
                        <tr class="total-row">
                            <td>TOTAL</td>
                            <td><?= $fp15_9_14 ?></td>
                            <td><?= $fp15_15 ?></td>
                            <td><?= $fp15_16 ?></td>
                            <td><?= $fp15_17 ?></td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <?php if($type === "first_pregnancy_18_19"): ?>
                <?php
                $fp18_9_14 =
                    ($data["18-19"]["first_age"][9] ?? 0) +
                    ($data["18-19"]["first_age"][10] ?? 0) +
                    ($data["18-19"]["first_age"][11] ?? 0) +
                    ($data["18-19"]["first_age"][12] ?? 0) +
                    ($data["18-19"]["first_age"][13] ?? 0) +
                    ($data["18-19"]["first_age"][14] ?? 0);

                $fp18_15_17 =
                    ($data["18-19"]["first_age"][15] ?? 0) +
                    ($data["18-19"]["first_age"][16] ?? 0) +
                    ($data["18-19"]["first_age"][17] ?? 0);

                $fp18_18 = $data["18-19"]["first_age"][18] ?? 0;
                $fp18_19 = $data["18-19"]["first_age"][19] ?? 0;
                ?>
                <div class="report-block">
                    <h3>Adolescent Mothers Aged 18-19 by Age at First Pregnancy</h3>
                    <table>
                        <tr>
                            <th rowspan="2">Age Group</th>
                            <th colspan="4">Age at First Pregnancy</th>
                        </tr>
                        <tr>
                            <th>9-14 years old</th>
                            <th>15-17 years old</th>
                            <th>18 years old</th>
                            <th>19 years old</th>
                        </tr>
                        <tr>
                            <td>18-19</td>
                            <td><?= $fp18_9_14 ?></td>
                            <td><?= $fp18_15_17 ?></td>
                            <td><?= $fp18_18 ?></td>
                            <td><?= $fp18_19 ?></td>
                        </tr>
                        <tr class="total-row">
                            <td>TOTAL</td>
                            <td><?= $fp18_9_14 ?></td>
                            <td><?= $fp18_15_17 ?></td>
                            <td><?= $fp18_18 ?></td>
                            <td><?= $fp18_19 ?></td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <?php if($type === "pregnancies_living_children"): ?>
                <?php
                $tp1014 = $data["9-14"]["total_pregnancies"] ?? 0;
                $tp1517 = $data["15-17"]["total_pregnancies"] ?? 0;
                $tp1819 = $data["18-19"]["total_pregnancies"] ?? 0;

                $lc1014 = $data["9-14"]["living_children"] ?? 0;
                $lc1517 = $data["15-17"]["living_children"] ?? 0;
                $lc1819 = $data["18-19"]["living_children"] ?? 0;
                ?>
                <div class="report-block">
                    <h3>Adolescent Mothers by Total Number of Pregnancies, Total Number of Living Children, and Age Group</h3>
                    <table>
                        <tr>
                            <th>Age Group</th>
                            <th>Total Number of Pregnancies</th>
                            <th>Number of Living Children</th>
                        </tr>
                        <tr>
                            <td>9-14</td>
                            <td><?= $tp1014 ?></td>
                            <td><?= $lc1014 ?></td>
                        </tr>
                        <tr>
                            <td>15-17</td>
                            <td><?= $tp1517 ?></td>
                            <td><?= $lc1517 ?></td>
                        </tr>
                        <tr>
                            <td>18-19</td>
                            <td><?= $tp1819 ?></td>
                            <td><?= $lc1819 ?></td>
                        </tr>
                        <tr class="total-row">
                            <td>TOTAL</td>
                            <td><?= $tp1014 + $tp1517 + $tp1819 ?></td>
                            <td><?= $lc1014 + $lc1517 + $lc1819 ?></td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <?php if($type === "membership"): ?>
                <?php
                $both1014 = $data["9-14"]["both"] ?? 0;
                $both1517 = $data["15-17"]["both"] ?? 0;
                $both1819 = $data["18-19"]["both"] ?? 0;

                $ips1014 = $data["9-14"]["is_ips"] ?? 0;
                $ips1517 = $data["15-17"]["is_ips"] ?? 0;
                $ips1819 = $data["18-19"]["is_ips"] ?? 0;

                $fourps1014 = $data["9-14"]["is_4ps"] ?? 0;
                $fourps1517 = $data["15-17"]["is_4ps"] ?? 0;
                $fourps1819 = $data["18-19"]["is_4ps"] ?? 0;

                $totalFourPs = $fourps1014 + $fourps1517 + $fourps1819;
                $totalIps    = $ips1014 + $ips1517 + $ips1819;
                $totalBoth   = $both1014 + $both1517 + $both1819;
                ?>
                <div class="report-block">
                    <h3>Number of Pregnant Adolescents and Adolescent Mothers by 4Ps and IP Membership and Age Group</h3>
                    <table>
                        <tr>
                            <th>Age group</th>
                            <th>Total Number of Pregnant Adolescents and Adolescent Mothers who are 4Ps Members</th>
                            <th>Total Number of Pregnant Adolescents and Adolescent Mothers who are IPs Members</th>
                            <th>Total Number of Pregnant Adolescents and Adolescent Mothers who are both 4Ps and IPs Members</th>
                        </tr>
                        <tr>
                            <td>9-14</td>
                            <td><?= $fourps1014 ?></td>
                            <td><?= $ips1014 ?></td>
                            <td><?= $both1014 ?></td>
                        </tr>
                        <tr>
                            <td>15-17</td>
                            <td><?= $fourps1517 ?></td>
                            <td><?= $ips1517 ?></td>
                            <td><?= $both1517 ?></td>
                        </tr>
                        <tr>
                            <td>18-19</td>
                            <td><?= $fourps1819 ?></td>
                            <td><?= $ips1819 ?></td>
                            <td><?= $both1819 ?></td>
                        </tr>
                        <tr class="total-row">
                            <td>TOTAL</td>
                            <td><?= $totalFourPs ?></td>
                            <td><?= $totalIps ?></td>
                            <td><?= $totalBoth ?></td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <?php if($type === "income"): ?>
                <?php
                $incomeLabels = [
                    "No Income",
                    "1-10,000",
                    "10,001 - 20,000",
                    "20,001 and Above"
                ];
                $incomeTotals = array_fill_keys($incomeLabels, 0);
                ?>
                <div class="report-block">
                    <h3>Distribution of Pregnant Adolescents and Adolescent Mothers by Household Income Level and Age Group</h3>
                    <table>
                        <tr>
                            <th>Age Group</th>
                            <?php foreach($incomeLabels as $label): ?>
                                <th><?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?></th>
                            <?php endforeach; ?>
                        </tr>

                        <?php foreach($ageGroups as $g): ?>
                            <?php
                            $groupData = (isset($data[$g]) && is_array($data[$g])) ? $data[$g] : [];

                            $valNoIncome = getMergedIncomeValueFromFlatGroup($groupData, 'No Income');
                            $val1to10k   = getMergedIncomeValueFromFlatGroup($groupData, '1-10,000');
                            $val10to20k  = getMergedIncomeValueFromFlatGroup($groupData, '10,001 - 20,000');
                            $val20Above  = getMergedIncomeValueFromFlatGroup($groupData, '20,001 and Above');

                            $incomeTotals['No Income'] += $valNoIncome;
                            $incomeTotals['1-10,000'] += $val1to10k;
                            $incomeTotals['10,001 - 20,000'] += $val10to20k;
                            $incomeTotals['20,001 and Above'] += $val20Above;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$g, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $valNoIncome ?></td>
                                <td><?= $val1to10k ?></td>
                                <td><?= $val10to20k ?></td>
                                <td><?= $val20Above ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="total-row">
                            <td>TOTAL</td>
                            <td><?= $incomeTotals['No Income'] ?></td>
                            <td><?= $incomeTotals['1-10,000'] ?></td>
                            <td><?= $incomeTotals['10,001 - 20,000'] ?></td>
                            <td><?= $incomeTotals['20,001 and Above'] ?></td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <?php if($type === "support_services"): ?>
                <?php
                $serviceLabels = [
                    "Livelihood training",
                    "Skills training",
                    "Health services",
                    "Scholarship",
                    "Others"
                ];
                $serviceTotals = array_fill_keys($serviceLabels, 0);
                ?>
                <div class="report-block">
                    <table>
                        <tr>
                            <th>Age Group</th>
                            <?php foreach($serviceLabels as $label): ?>
                                <th><?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?></th>
                            <?php endforeach; ?>
                        </tr>

                        <?php foreach($ageGroups as $g): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$g, ENT_QUOTES, 'UTF-8') ?></td>
                                <?php foreach($serviceLabels as $label): ?>
                                    <?php
                                    $val = $data[$g][$label] ?? 0;
                                    $serviceTotals[$label] += $val;
                                    ?>
                                    <td><?= $val ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="total-row">
                            <td>TOTAL</td>
                            <?php foreach($serviceLabels as $label): ?>
                                <td><?= $serviceTotals[$label] ?></td>
                            <?php endforeach; ?>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <?php if($type === "partner_education"): ?>
                <?php
                $partnerEduLabels = [
                    "No Education",
                    "Elementary",
                    "Junior High School",
                    "Senior High School",
                    "Vocational",
                    "College Level"
                ];
                $eduTotals = array_fill_keys($partnerEduLabels, 0);
                ?>
                <div class="report-block">
                    <h3>Educational Attainment of Partners, by Age Group</h3>
                    <table>
                        <tr>
                            <th>Age Group of Partners</th>
                            <?php foreach($partnerEduLabels as $label): ?>
                                <th><?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?></th>
                            <?php endforeach; ?>
                        </tr>

                        <?php foreach($partnerAgeGroups as $pg): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$pg, ENT_QUOTES, 'UTF-8') ?></td>
                                <?php foreach($partnerEduLabels as $label): ?>
                                    <?php
                                    $val = $data[$pg]["education"][$label] ?? 0;
                                    $eduTotals[$label] += $val;
                                    ?>
                                    <td><?= $val ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="total-row">
                            <td>TOTAL</td>
                            <?php foreach($partnerEduLabels as $label): ?>
                                <td><?= $eduTotals[$label] ?></td>
                            <?php endforeach; ?>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <?php if($type === "partner_income"): ?>
                <?php
                $partnerIncomeLabels = [
                    "No Income",
                    "1-10,000",
                    "10,001 - 20,000",
                    "20,001 and Above"
                ];
                $partnerIncomeTotals = array_fill_keys($partnerIncomeLabels, 0);
                ?>
                <div class="report-block">
                    <h3>Monthly Income Range of Partners, by Age Group</h3>
                    <table>
                        <tr>
                            <th>Age Group of Partners</th>
                            <?php foreach($partnerIncomeLabels as $label): ?>
                                <th><?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?></th>
                            <?php endforeach; ?>
                        </tr>

                        <?php foreach($partnerAgeGroups as $pg): ?>
                            <?php
                            $partnerGroupData = (isset($data[$pg]) && is_array($data[$pg])) ? $data[$pg] : [];

                            $partnerNoIncome = getMergedIncomeValueFromNestedGroup($partnerGroupData, 'income', 'No Income');
                            $partner1to10k   = getMergedIncomeValueFromNestedGroup($partnerGroupData, 'income', '1-10,000');
                            $partner10to20k  = getMergedIncomeValueFromNestedGroup($partnerGroupData, 'income', '10,001 - 20,000');
                            $partner20Above  = getMergedIncomeValueFromNestedGroup($partnerGroupData, 'income', '20,001 and Above');

                            $partnerIncomeTotals['No Income'] += $partnerNoIncome;
                            $partnerIncomeTotals['1-10,000'] += $partner1to10k;
                            $partnerIncomeTotals['10,001 - 20,000'] += $partner10to20k;
                            $partnerIncomeTotals['20,001 and Above'] += $partner20Above;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$pg, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $partnerNoIncome ?></td>
                                <td><?= $partner1to10k ?></td>
                                <td><?= $partner10to20k ?></td>
                                <td><?= $partner20Above ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="total-row">
                            <td>TOTAL</td>
                            <td><?= $partnerIncomeTotals['No Income'] ?></td>
                            <td><?= $partnerIncomeTotals['1-10,000'] ?></td>
                            <td><?= $partnerIncomeTotals['10,001 - 20,000'] ?></td>
                            <td><?= $partnerIncomeTotals['20,001 and Above'] ?></td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
