<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../includes/header.php";
require_once __DIR__ . "/../includes/sidebar.php";

/* ===============================
   REPORT LIST - ORDERED
================================ */
$reports = [
    [
        "title" => "Monthly Status of Pregnant Adolescents and Adolescent Mothers by Age Group",
        "type"  => "monthly_pregnant_status"
    ],
    [
        "title" => "Distribution of Adolescent Mothers Practicing Family Planning by Age Group",
        "type"  => "family_planning"
    ],
    [
        "title" => "Educational Attainment Among Pregnant and Adolescent Mothers by Age Group",
        "type"  => "education"
    ],
    [
        "title" => "Civil Status Among Pregnant and Adolescent Mothers by Age Group",
        "type"  => "civil_status"
    ],
    [
        "title" => "Prenatal Care Attendance of Pregnant Adolescent by Month of Pregnancy and Age Group",
        "type"  => "prenatal"
    ],
    [
        "title" => "Adolescent Mothers Aged 9-14 by Age at First Pregnancy",
        "type"  => "first_pregnancy_9_14"
    ],
    [
        "title" => "Adolescent Mothers Aged 15-17 by Age at First Pregnancy",
        "type"  => "first_pregnancy_15_17"
    ],
    [
        "title" => "Adolescent Mothers Aged 18-19 by Age at First Pregnancy",
        "type"  => "first_pregnancy_18_19"
    ],
    [
        "title" => "Adolescent Mothers by Total Number of Pregnancies, Total Number of Living Children, and Age Group",
        "type"  => "pregnancies_living_children"
    ],
    [
        "title" => "Number of Pregnant Adolescents and Adolescent Mothers by 4Ps and IPs Membership and Age Group",
        "type"  => "membership"
    ],
    [
        "title" => "Distribution of Pregnant Adolescents and Adolescent Mothers by Household Income Level and Age Group",
        "type"  => "income"
    ],
    [
        "title" => "Distribution of Pregnant Adolescents and Adolescent Mothers by Expressed Needs for Government Support Services",
        "type"  => "support_services"
    ],
    [
        "title" => "Educational Attainment of Partners, by Age Group",
        "type"  => "partner_education"
    ],
    [
        "title" => "Monthly Income Range of Partners, by Age Group",
        "type"  => "partner_income"
    ]
];
?>

<style>
:root{
    --p2528-navy:#0f172a;
    --p2528-deep:#163a73;
    --p2528-blue:#1d4ed8;
    --p2528-bright:#2563eb;
    --p2528-soft:#f8fbff;
    --p2528-card:#ffffff;
    --p2528-border:#dbe4f0;
    --p2528-text:#0f172a;
    --p2528-muted:#64748b;
}

.reports-page{
    width:100%;
    max-width:100%;
    margin:0;
    padding:6px 0 24px;
}

.reports-shell{
    width:100%;
    max-width:100%;
    background:#ffffff;
    border:1px solid var(--p2528-border);
    border-radius:24px;
    overflow:hidden;
    box-shadow:0 20px 40px rgba(15,23,42,.08);
}

/* HERO */
.reports-hero{
    padding:22px 24px;
    color:#ffffff;
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.12), transparent 22%),
        linear-gradient(135deg, #0f172a 0%, #163a73 42%, #df82c6 75%, #cd87be 100%);
}

.reports-hero h1{
    margin:0;
    font-size:30px;
    font-weight:900;
    line-height:1.1;
}

/* BODY */
.reports-body{
    padding:18px;
    background:linear-gradient(180deg, #f8fbff 0%, #f4f8fd 100%);
}

.report-list{
    display:flex;
    flex-direction:column;
    gap:12px;
}

/* REPORT CARD */
.report-card{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:18px;
    width:100%;
    padding:16px 20px;
    border-radius:18px;
    background:#ffffff;
    border:1px solid var(--p2528-border);
    transition:
        background-color 120ms ease,
        color 120ms ease,
        border-color 120ms ease,
        box-shadow 120ms ease,
        opacity 120ms ease;
}

.report-card:hover{
    box-shadow:0 12px 24px rgba(15,23,42,.08);
}

.report-main{
    display:flex;
    align-items:center;
    gap:16px;
    flex:1 1 auto;
    min-width:0;
}

.report-info{
    flex:1 1 auto;
    min-width:0;
}

.report-info h3{
    margin:0;
    font-size:16px;
    font-weight:600;
    line-height:1.45;
    color:var(--p2528-text);
    word-break:break-word;
    overflow-wrap:anywhere;
    max-width:100%;
}

.report-actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    flex:0 0 auto;
}

.report-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:150px;
    height:42px;
    padding:0 16px;
    border-radius:12px;
    color:#ffffff;
    text-decoration:none;
    font-size:14px;
    font-weight:700;
    white-space:nowrap;
    transition:
        background-color 120ms ease,
        color 120ms ease,
        border-color 120ms ease,
        box-shadow 120ms ease,
        opacity 120ms ease;
    background:#2955d9;
    box-shadow:0 8px 18px rgba(41,85,217,.20);
}

.report-btn:hover{
    background:#1f47c7;
    color:#ffffff;
}

/* RESPONSIVE */
@media (max-width: 900px){
    .report-card{
        align-items:flex-start;
    }

    .report-main{
        align-items:flex-start;
    }

    .report-info h3{
        font-size:15px;
        line-height:1.4;
    }
}

@media (max-width: 760px){
    .reports-page{
        padding:6px 0 18px;
    }

    .reports-hero{
        padding:20px 18px;
    }

    .reports-hero h1{
        font-size:26px;
    }

    .reports-body{
        padding:14px;
    }

    .report-card{
        flex-direction:column;
        align-items:stretch;
        gap:14px;
        padding:16px;
    }

    .report-main{
        align-items:flex-start;
        gap:14px;
    }

    .report-info h3{
        font-size:15px;
    }

    .report-actions{
        width:100%;
    }

    .report-btn{
        width:100%;
        min-width:0;
    }
}
</style>

<div class="reports-page">
    <div class="reports-shell">

        <div class="reports-hero">
            <h1>Reports</h1>
        </div>

        <div class="reports-body">
            <div class="report-list">
                <?php foreach ($reports as $report): ?>
                    <div class="report-card">
                        <div class="report-main">
                            <div class="report-info">
                                <h3><?= htmlspecialchars($report["title"], ENT_QUOTES, 'UTF-8') ?></h3>
                            </div>
                        </div>

                        <div class="report-actions">
                            <a
                                class="report-btn"
                                href="report_table.php?type=<?= urlencode($report["type"]) ?>"
                            >
                                Open Report
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
