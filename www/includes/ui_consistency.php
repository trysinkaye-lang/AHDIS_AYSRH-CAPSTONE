<?php
declare(strict_types=1);
?>
<style>
:root{
  --space-xs:6px;
  --space-sm:10px;
  --space-md:14px;
  --space-lg:18px;
  --space-xl:24px;
  --space-2xl:32px;
  --radius-card:22px;
  --radius-panel:18px;
  --radius-control:14px;
  --input-height:44px;
  --ui-border:#dbe4f0;
  --ui-border-soft:#e8eef6;
  --ui-bg:#f4f7fb;
  --ui-card:#ffffff;
  --ui-muted:#64748b;
  --ui-text:#0f172a;
  --ui-navy:#0f172a;
  --ui-deep:#163a73;
  --ui-blue:#1d4ed8;
  --ui-pink:#d889c7;
  --ui-shadow-sm:0 8px 20px rgba(15,23,42,.06);
  --ui-shadow-md:0 16px 34px rgba(15,23,42,.08);
  --ui-hero:radial-gradient(circle at top right, rgba(255,255,255,.14), transparent 24%),
            linear-gradient(135deg, #0f172a 0%, #163a73 44%, #1d4ed8 72%, #d889c7 100%);
}

html,
body{
  min-height:100%;
  overflow-x:hidden !important;
  overflow-y:auto !important;
}

body{
  background:var(--ui-bg);
}

.app-main{
  flex:1 1 auto !important;
  min-width:0 !important;
  height:auto !important;
  margin-left:var(--sb-w) !important;
  width:calc(100vw - var(--sb-w)) !important;
  max-width:calc(100vw - var(--sb-w)) !important;
  padding:var(--space-xl) !important;
  background:var(--ui-bg);
  overflow-x:hidden !important;
  overflow-y:auto !important;
  box-sizing:border-box !important;
  transition:none !important;
}

.page-content{
  width:100%;
  max-width:none !important;
  height:auto !important;
  margin:0;
  display:flex;
  flex-direction:column;
  flex:1 0 auto;
  min-width:0;
  overflow:visible !important;
  box-sizing:border-box;
}

.page-content > :first-child{
  margin-top:0 !important;
}

.page-content > .content{
  width:100% !important;
  max-width:100% !important;
  margin:0 !important;
  padding:0 !important;
}

.reports-page,
.reports-graph-page,
.export-page,
.view-page,
.import-page,
.account-wrap,
.am-form,
.am-page,
.ewrap{
  width:100% !important;
  max-width:100% !important;
  margin:0 !important;
  padding:0 0 var(--space-xl) !important;
}

.reports-container,
.graph-shell,
.export-shell,
.export-report-card{
  width:100% !important;
  max-width:100% !important;
  margin:0 !important;
  box-sizing:border-box !important;
}

.reports-shell,
.reports-container,
.graph-shell,
.export-shell,
.exp-card,
.export-report-card,
.view-page .page-card,
.import-card,
.form-card,
.am-page,
.am-card,
.ehero,
.ecard,
.dashboard-board,
.section-card,
.table-shell,
.view-section,
.exp-section,
.section-box,
.account-card{
  border:1px solid var(--ui-border) !important;
  border-radius:var(--radius-card) !important;
  box-shadow:var(--ui-shadow-md) !important;
  background:var(--ui-card) !important;
}

.reports-shell,
.reports-container,
.graph-shell,
.export-shell,
.exp-card,
.export-report-card,
.view-page .page-card,
.import-card,
.form-card,
.am-page{
  overflow:hidden;
}

.reports-hero,
.graph-topbar,
.exp-hero,
.view-hero,
.import-hero,
.account-hero,
.am-form .form-header,
.am-hero,
.page-hero{
  min-height:118px;
  padding:var(--space-xl) var(--space-xl) !important;
  border-radius:0 !important;
  background:var(--ui-hero) !important;
  color:#fff !important;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:var(--space-lg);
}

.account-hero{
  border-radius:var(--radius-card) !important;
  margin-bottom:var(--space-lg) !important;
}

.reports-hero h1,
.graph-title h1,
.exp-hero-title,
.view-hero-title,
.import-hero h1,
.import-hero h2,
.account-title,
.am-form .form-header h3,
.am-title h1,
.am-title h2,
.page-hero-title{
  margin:0 !important;
  font-size:28px !important;
  line-height:1.12 !important;
  font-weight:900 !important;
  letter-spacing:0 !important;
  color:#fff !important;
}

.exp-hero-subtitle,
.graph-title p,
.view-hero-subtitle,
.import-hero p,
.account-sub,
.am-form .form-header p,
.am-sub,
.page-hero-subtitle{
  margin:8px 0 0 !important;
  color:rgba(255,255,255,.88) !important;
  font-size:14px !important;
  line-height:1.55 !important;
  font-weight:700 !important;
}

.reports-kpi,
.hero-count-pill,
.view-total,
.account-chip,
.am-chip,
.page-pill,
.page-chip{
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  gap:8px !important;
  min-height:42px !important;
  padding:10px 14px !important;
  border-radius:999px !important;
  border:1px solid rgba(255,255,255,.22) !important;
  background:rgba(255,255,255,.13) !important;
  color:#fff !important;
  box-shadow:none !important;
  white-space:nowrap;
}

.reports-body,
.graph-body,
.exp-body,
.view-stack,
.import-body,
.am-form .form-wrapper,
.ecard-b,
.page-body{
  padding:var(--space-lg) !important;
  background:linear-gradient(180deg,#f8fbff 0%, #f4f8fd 100%) !important;
}

.export-stack,
.report-list,
.account-grid,
.summary-grid,
.am-form .section + .section{
  gap:var(--space-md) !important;
}

.am-form .section,
.view-section,
.exp-section,
.section-box,
.report-card,
.account-card,
.sum-box{
  border:1px solid var(--ui-border) !important;
  border-radius:var(--radius-panel) !important;
  background:#fff !important;
  box-shadow:var(--ui-shadow-sm) !important;
}

.am-form .section,
.section-box,
.account-card{
  padding:var(--space-lg) !important;
}

.exp-section-head,
.view-section-head,
.dashboard-board-head,
.account-card-head,
.table-head{
  min-height:58px;
  padding:var(--space-md) var(--space-lg) !important;
  border-bottom:1px solid var(--ui-border-soft) !important;
  background:linear-gradient(180deg,#f9fbff 0%, #f3f8ff 100%) !important;
}

.section-title,
.exp-section-title,
.view-section-title,
.dashboard-chart-title,
.dashboard-board-title,
.account-card-title,
.table-title{
  margin:0 !important;
  color:var(--ui-deep) !important;
  font-size:15px !important;
  line-height:1.3 !important;
  font-weight:900 !important;
  letter-spacing:0 !important;
}

.am-form .grid-2,
.two-col,
.form-row-2{
  display:grid !important;
  grid-template-columns:repeat(2,minmax(0,1fr)) !important;
  gap:var(--space-md) !important;
  align-items:start;
}

.am-form .am-anim.is-hidden{
  display:none !important;
}

.view-form,
.filter-bar,
.form-row,
.export-actions-panel{
  gap:var(--space-md) !important;
  align-items:end !important;
}

.form-label,
.field label,
.am-form .form-label,
.export-filter-form label{
  display:block;
  margin:0 0 6px !important;
  color:#334155 !important;
  font-size:12px !important;
  line-height:1.3 !important;
  font-weight:900 !important;
}

.form-control,
.am-form .form-control,
.view-form input,
.filter-bar input,
.filter-bar select,
.filter-bar textarea,
.input-file,
.account-form input,
.account-form select,
.account-form textarea,
.import-page input,
.import-page select,
.import-page textarea{
  min-height:var(--input-height) !important;
  border:1px solid #cfdced !important;
  border-radius:var(--radius-control) !important;
  background:#fff !important;
  padding:10px 13px !important;
  color:var(--ui-text) !important;
  font-size:14px !important;
  font-weight:700 !important;
  box-shadow:none !important;
}

textarea.form-control,
.am-form textarea.form-control{
  min-height:96px !important;
}

.form-control:focus,
.am-form .form-control:focus,
.view-form input:focus,
.filter-bar input:focus,
.filter-bar select:focus,
.filter-bar textarea:focus,
.account-form input:focus,
.account-form select:focus,
.account-form textarea:focus{
  border-color:#2563eb !important;
  box-shadow:0 0 0 3px rgba(37,99,235,.13) !important;
  outline:none !important;
}

.hint,
.section-sub,
.view-hero-subtitle,
.account-note,
.section-note{
  color:var(--ui-muted);
}

.am-form .radio-group,
.am-form .check-grid{
  gap:var(--space-sm) !important;
}

.am-form .radio-group label,
.gov-assist-check{
  min-height:42px;
  border-radius:999px !important;
  border:1px solid #d7e3f0 !important;
  background:#f8fbff !important;
  padding:9px 12px !important;
  box-shadow:none !important;
}

.lock-check{
  min-height:0 !important;
  border-radius:14px !important;
  border:1px solid #d7e3f0 !important;
  background:#f8fbff !important;
  padding:12px 14px !important;
  box-shadow:none !important;
}

.am-form .radio-group.education-radio-row{
  flex-wrap:nowrap !important;
  overflow-x:auto;
  padding-bottom:2px;
}

.am-form .radio-group.education-radio-row label{
  flex:0 0 auto;
  padding:10px 20px !important;
}

.am-form .radio-group label.is-selected{
  border-color:#8ea2ff !important;
  background:linear-gradient(135deg, rgba(29,78,216,.12), rgba(236,72,153,.10)) !important;
  color:#0f172a !important;
}

.btn,
.app-btn,
.view-btn,
.report-btn,
.report-actions a,
.graph-actions a,
.graph-actions button,
.account-btn{
  min-height:44px !important;
  border-radius:var(--radius-control) !important;
  padding:0 16px !important;
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  gap:8px !important;
  font-size:13px !important;
  font-weight:900 !important;
  line-height:1 !important;
  white-space:nowrap;
}

.table-shell,
.view-table-wrap,
.table-wrap{
  width:100%;
  overflow:auto !important;
}

.preview-table,
.view-table,
.result-table{
  width:100%;
  border-collapse:separate !important;
  border-spacing:0 !important;
}

.preview-table thead th,
.view-table thead th,
.result-table thead th,
.table-wrap table thead th{
  padding:13px 12px !important;
  background:linear-gradient(180deg,#eff6ff 0%, #dbeafe 100%) !important;
  color:#163a73 !important;
  font-size:13px !important;
  font-weight:900 !important;
  white-space:nowrap;
}

.preview-table tbody td,
.view-table tbody td,
.result-table tbody td,
.table-wrap table tbody td{
  padding:12px !important;
  border-bottom:1px solid #edf2fb !important;
  color:#1e293b !important;
  font-size:13px !important;
  vertical-align:middle !important;
}

.dashboard-expand{
  min-height:34px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:0 12px;
  border-radius:999px;
}

.report-card{
  min-height:74px;
  padding:var(--space-md) var(--space-lg) !important;
}

.report-page-footer{
  width:100% !important;
  max-width:100% !important;
  margin:var(--space-lg) 0 0 !important;
  box-sizing:border-box !important;
}

.report-btn{
  min-width:150px;
}

.export-actions-panel{
  padding:var(--space-lg) !important;
}

.export-actions-copy{
  line-height:1.55 !important;
}

.export-actions-buttons{
  gap:var(--space-sm) !important;
}

.account-grid{
  display:grid !important;
  grid-template-columns:repeat(2,minmax(0,1fr)) !important;
  gap:16px !important;
  align-items:start;
}

.account-card{
  min-width:0;
  border:1px solid #dce6f2 !important;
  border-radius:18px !important;
  background:#fff !important;
  box-shadow:0 12px 28px rgba(15,23,42,.07) !important;
  padding:18px !important;
  overflow:visible !important;
}

.account-wrap .account-card::before{
  display:none !important;
}

.account-card-location{
  grid-column:1 / -1 !important;
}

.account-wrap .account-card-head{
  min-height:0 !important;
  padding:0 0 14px !important;
  margin-bottom:16px !important;
  border-bottom:1px solid #edf2f7 !important;
  background:transparent !important;
  align-items:center !important;
}

.account-wrap .account-hero{
  min-height:0 !important;
  padding:22px !important;
  border-radius:20px !important;
  background:linear-gradient(135deg, #17356f 0%, #2450a8 62%, #c95cae 100%) !important;
  box-shadow:0 14px 32px rgba(22,52,109,.12) !important;
  align-items:center !important;
}

.account-wrap .account-hero::after{
  display:none !important;
}

.account-wrap .account-title{
  font-size:28px !important;
  letter-spacing:0 !important;
}

.account-wrap .account-sub{
  font-size:14px !important;
  line-height:1.45 !important;
}

.pw-wrap{
  display:flex;
  align-items:center;
  gap:8px;
}

.pw-toggle{
  min-height:var(--input-height);
  border-radius:var(--radius-control) !important;
}

.account-wrap .pw-wrap{
  position:relative !important;
  gap:0 !important;
}

.account-wrap .pw-wrap .form-control{
  padding-right:48px !important;
}

.account-wrap .pw-toggle{
  position:absolute !important;
  top:4px !important;
  right:6px !important;
  bottom:4px !important;
  width:36px !important;
  height:auto !important;
  min-height:0 !important;
  border-radius:10px !important;
  transform:none !important;
  transition:background-color 120ms ease, border-color 120ms ease, color 120ms ease !important;
}

.account-wrap .account-btn{
  background:#214fbf !important;
  border:none !important;
  border-radius:12px !important;
  box-shadow:0 10px 20px rgba(38,74,168,.16) !important;
  color:#fff !important;
  transform:none !important;
  transition:background-color 120ms ease, box-shadow 120ms ease, opacity 120ms ease !important;
}

.account-wrap .account-btn:hover{
  background:#1d46a8 !important;
  transform:none !important;
  box-shadow:0 12px 22px rgba(38,74,168,.18) !important;
}

.account-wrap .account-btn:disabled{
  box-shadow:none !important;
}

.account-card-location .account-btn{
  width:auto !important;
  min-width:172px !important;
  align-self:flex-start !important;
}

.account-wrap .account-lock-banner{
  padding:12px 14px !important;
  border-radius:14px !important;
  margin:0 0 14px !important;
  box-shadow:none !important;
}

.account-wrap .account-note{
  margin-top:0 !important;
  padding:11px 13px !important;
  border-radius:12px !important;
}

.account-wrap .lock-check{
  display:grid !important;
  grid-template-columns:18px minmax(0,1fr) !important;
  align-items:flex-start !important;
  gap:10px !important;
}

.account-wrap .lock-check input[type="checkbox"]{
  width:16px !important;
  height:16px !important;
  min-height:0 !important;
  padding:0 !important;
  margin:2px 0 0 !important;
  border:0 !important;
  box-shadow:none !important;
  accent-color:#2958d6 !important;
}

.footer-bleed{
  margin-top:auto !important;
  padding-top:var(--space-lg);
}

.main-footer{
  border-radius:var(--radius-panel) !important;
  padding:14px 16px !important;
}

.sidebar{
  padding:14px !important;
  transition:width 180ms ease !important;
}

.sb-card{
  border-radius:var(--radius-card) !important;
  padding:12px !important;
  transition:background-color 120ms ease, border-color 120ms ease, box-shadow 120ms ease !important;
}

.sb-brand-banner{
  min-height:88px !important;
  border-radius:18px !important;
}

.sidebar .sb-nav > a.sb-link,
.sidebar .sb-nav > a.sb-link:hover,
.sidebar .sb-nav > a.sb-link:focus,
.sidebar .sb-nav > a.sb-link:focus-visible,
.sidebar .sb-nav > a.sb-link:active,
.sidebar .sb-nav > a.sb-link.active{
  min-height:48px !important;
  border-radius:14px !important;
  box-shadow:none !important;
  filter:none !important;
  transform:none !important;
  animation:none !important;
  transition:background-color 120ms ease, border-color 120ms ease, color 120ms ease, opacity 120ms ease !important;
  -webkit-tap-highlight-color:transparent;
}

.sidebar .sb-nav > a.sb-link:hover,
.sidebar .sb-nav > a.sb-link:active,
.sidebar .sb-nav > a.sb-link:focus,
.sidebar .sb-nav > a.sb-link:focus-visible{
  transform:none !important;
  animation:none !important;
  box-shadow:none !important;
  filter:none !important;
}

.sidebar .sb-nav > a.sb-link.active{
  box-shadow:none !important;
}

.sidebar .sb-nav > a.sb-link::after{
  transition:none !important;
}

.sb-footer{
  border-radius:18px !important;
}

.sb-toggle,
.sb-logout-btn,
.sb-logout-icon{
  transform:none !important;
  transition:background-color 120ms ease, border-color 120ms ease, color 120ms ease !important;
}

.sb-toggle:hover,
.sb-logout-btn:hover,
.sb-logout-icon:hover{
  transform:none !important;
}

.app-shell.sidebar-collapsed .sidebar,
body.sidebar-collapsed .app-shell .sidebar{
  padding:12px !important;
}

.app-shell.sidebar-collapsed .sb-card,
body.sidebar-collapsed .app-shell .sb-card{
  padding:10px !important;
  border-radius:24px !important;
}

.app-shell.sidebar-collapsed .sb-brand-banner,
body.sidebar-collapsed .app-shell .sb-brand-banner{
  min-height:52px !important;
  padding:6px !important;
  border-radius:16px !important;
}

.app-shell.sidebar-collapsed .sidebar .sb-nav > a.sb-link,
.app-shell.sidebar-collapsed .sidebar .sb-nav > a.sb-link:hover,
.app-shell.sidebar-collapsed .sidebar .sb-nav > a.sb-link:focus,
.app-shell.sidebar-collapsed .sidebar .sb-nav > a.sb-link:focus-visible,
.app-shell.sidebar-collapsed .sidebar .sb-nav > a.sb-link:active,
.app-shell.sidebar-collapsed .sidebar .sb-nav > a.sb-link.active,
body.sidebar-collapsed .app-shell .sidebar .sb-nav > a.sb-link,
body.sidebar-collapsed .app-shell .sidebar .sb-nav > a.sb-link:hover,
body.sidebar-collapsed .app-shell .sidebar .sb-nav > a.sb-link:focus,
body.sidebar-collapsed .app-shell .sidebar .sb-nav > a.sb-link:focus-visible,
body.sidebar-collapsed .app-shell .sidebar .sb-nav > a.sb-link:active,
body.sidebar-collapsed .app-shell .sidebar .sb-nav > a.sb-link.active{
  width:48px !important;
  min-height:48px !important;
  justify-content:center !important;
  align-self:center !important;
  gap:0 !important;
  padding:0 !important;
}

.app-shell.sidebar-collapsed .sb-user,
body.sidebar-collapsed .app-shell .sb-user{
  display:none !important;
}

.app-shell.sidebar-collapsed .sb-footer,
body.sidebar-collapsed .app-shell .sb-footer{
  width:100% !important;
  padding:5px !important;
  margin-top:12px !important;
  gap:6px !important;
  border-radius:18px !important;
  display:flex !important;
  flex-direction:column !important;
  align-items:center !important;
  justify-content:center !important;
  box-sizing:border-box !important;
}

.app-shell.sidebar-collapsed .sb-avatar,
body.sidebar-collapsed .app-shell .sb-avatar{
  width:38px !important;
  height:38px !important;
  flex-basis:38px !important;
  border-radius:13px !important;
  font-size:17px !important;
}

.app-shell.sidebar-collapsed .sb-logout-icon,
body.sidebar-collapsed .app-shell .sb-logout-icon{
  display:inline-flex !important;
}

body:not(.sidebar-collapsed) .app-shell:not(.sidebar-collapsed) .sb-logout-icon{
  display:none !important;
}

@media (max-width:1100px){
  .account-grid,
  .am-form .grid-2,
  .two-col,
  .form-row-2{
    grid-template-columns:1fr !important;
  }
}

@media (max-width:900px){
  .app-main{
    padding:var(--space-md) !important;
  }

  .page-content{
    max-width:100%;
  }

  .reports-hero,
  .graph-topbar,
  .exp-hero,
  .view-hero,
  .import-hero,
  .account-hero,
  .am-form .form-header,
  .am-hero,
  .page-hero{
    min-height:104px;
    padding:var(--space-lg) !important;
    align-items:flex-start;
  }
}

@media (max-width:700px){
  .reports-hero h1,
  .graph-title h1,
  .exp-hero-title,
  .view-hero-title,
  .import-hero h1,
  .import-hero h2,
  .account-title,
  .am-form .form-header h3,
  .am-title h1,
  .am-title h2,
  .page-hero-title{
    font-size:24px !important;
  }

  .reports-body,
  .graph-body,
  .exp-body,
  .view-stack,
  .import-body,
  .am-form .form-wrapper,
  .page-body{
    padding:var(--space-md) !important;
  }

  .export-actions-buttons,
  .report-actions,
  .view-form{
    width:100%;
  }
}

.dashboard-page{
  flex:1 1 auto !important;
  height:100% !important;
  min-height:0 !important;
  padding:0 !important;
  overflow:hidden !important;
}

.dashboard-page .reports-shell{
  height:100% !important;
  min-height:0 !important;
  display:flex !important;
  flex-direction:column !important;
  overflow:hidden !important;
}

.dashboard-page .reports-body{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow:hidden !important;
  display:flex !important;
  flex-direction:column !important;
}

.dashboard-page .dashboard-board{
  flex:1 1 auto !important;
  min-height:0 !important;
  display:flex !important;
  flex-direction:column !important;
  overflow:hidden !important;
}

.dashboard-page .dashboard-board-body{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow:hidden !important;
}

.dashboard-page .dashboard-grid{
  height:100% !important;
  display:grid !important;
  grid-template-columns:repeat(3,minmax(260px,1fr)) !important;
  grid-template-rows:repeat(2,minmax(0,1fr)) !important;
  gap:12px !important;
  align-items:stretch !important;
}

.dashboard-page .dashboard-chart-card{
  position:relative !important;
  min-height:0 !important;
  height:100% !important;
  padding:12px !important;
  display:flex !important;
  flex-direction:column !important;
  overflow:hidden !important;
}

.dashboard-page .dashboard-chart-head{
  flex:0 0 auto !important;
  min-height:0 !important;
  margin-bottom:6px !important;
}

.dashboard-page .dashboard-canvas{
  position:relative !important;
  flex:1 1 auto !important;
  min-height:0 !important;
  height:auto !important;
  width:100% !important;
  overflow:hidden !important;
}

.dashboard-page .dashboard-canvas canvas{
  display:block !important;
  width:100% !important;
  height:100% !important;
  max-width:100% !important;
  max-height:100% !important;
}

@media (min-width:1001px){
  html.dashboard-screen,
  body.dashboard-screen{
    height:100vh !important;
    overflow:hidden !important;
  }

  body.dashboard-screen .app-shell{
    height:100vh !important;
    min-height:100vh !important;
    overflow:hidden !important;
  }

  body.dashboard-screen .app-main{
    height:100vh !important;
    min-height:100vh !important;
    overflow:hidden !important;
    padding:18px 18px 0 !important;
  }

  body.dashboard-screen .page-content{
    flex:1 1 auto !important;
    height:100% !important;
    min-height:0 !important;
    overflow:hidden !important;
    display:flex !important;
    flex-direction:column !important;
  }

  body.dashboard-screen .footer-bleed{
    display:none !important;
  }

  .dashboard-page .reports-hero{
    flex:0 0 86px !important;
    min-height:86px !important;
    padding:18px 28px !important;
  }

  .dashboard-page .reports-hero h1{
    font-size:30px !important;
  }

  .dashboard-page .reports-kpi{
    min-width:246px !important;
    justify-content:center !important;
    padding:10px 18px !important;
    font-size:14px !important;
  }

  .dashboard-page .dashboard-board{
    height:100% !important;
    border-radius:22px !important;
  }

  .dashboard-page .dashboard-board-head{
    flex:0 0 78px !important;
    padding:16px 22px !important;
  }

  .dashboard-page .dashboard-board-head h2{
    font-size:21px !important;
  }

  .dashboard-page .dashboard-board-head p{
    font-size:13px !important;
  }

  .dashboard-page .dashboard-board-body{
    padding:12px !important;
  }

  .dashboard-page .dashboard-grid{
    max-height: min(560px, calc(100vh - 206px)) !important;
  }

  .dashboard-page .dashboard-chart-card{
    padding:12px 12px 10px !important;
    border-radius:18px !important;
  }

  .dashboard-page .dashboard-chart-title{
    font-size:13px !important;
  }

  .dashboard-page .dashboard-expand{
    font-size:10px !important;
    padding:5px 11px !important;
  }
}

@media (max-width:1000px){
  .dashboard-page{
    height:auto !important;
    min-height:0 !important;
    overflow:visible !important;
  }

  .dashboard-page .reports-shell,
  .dashboard-page .reports-body,
  .dashboard-page .dashboard-board,
  .dashboard-page .dashboard-board-body{
    overflow:visible !important;
  }

  .dashboard-page .dashboard-grid{
    height:auto !important;
    grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    grid-template-rows:none !important;
  }

  .dashboard-page .dashboard-chart-card{
    min-height:280px !important;
    height:auto !important;
  }

  .dashboard-page .dashboard-canvas{
    min-height:220px !important;
  }
}

@media (max-width:700px){
  .dashboard-page .dashboard-grid{
    grid-template-columns:1fr !important;
  }

  .dashboard-page .dashboard-chart-card{
    min-height:300px !important;
  }
}

/* =========================================================
   AHDIS Final Motion Clamp
   Loaded at the end of every page through footer.php.
   This is intentionally last so page-level styles cannot
   reintroduce bounce, pop, slide, scale, or layout motion.
   ========================================================= */

*,
*::before,
*::after{
  animation:none !important;
  animation-delay:0s !important;
  animation-duration:0s !important;
  animation-iteration-count:1 !important;
  scroll-behavior:auto !important;
  transition:none !important;
}

html,
body,
.app-shell,
.app-main,
.page-content,
.content,
.content-area,
.page,
.page-body,
.dashboard-page,
.reports-page,
.reports-graph-page,
.export-page,
.view-page,
.import-page,
.account-wrap,
.am-form,
.am-page,
.ewrap,
.reports-shell,
.dashboard-board,
.dashboard-chart-card,
.import-card,
.exp-card,
.view-page .page-card,
.account-card,
.form-card,
.section-card,
.panel,
.card,
.stat-card,
.report-card,
.report-btn,
.view-btn,
.btn,
.app-btn,
button,
input,
select,
textarea{
  transform:none !important;
  animation:none !important;
}

.page-transition,
.page-enter,
.page-load,
.content-enter,
.card-enter,
.fade-in,
.slide-in,
.reveal,
.motion,
.animate,
.animated,
.bounce,
.pop,
.zoom,
.pulse,
.float,
.floating{
  transform:none !important;
  animation:none !important;
  transition:none !important;
}

.app-main,
.page-content,
.dashboard-page,
.reports-page,
.import-page,
.account-wrap,
.am-form,
.reports-shell,
.dashboard-board,
.dashboard-chart-card,
.import-card,
.exp-card,
.account-card,
.form-card,
.section-card,
.panel,
.card,
.stat-card,
.report-card{
  transition:none !important;
}

.sidebar{
  transition:width 180ms ease !important;
}

.sidebar *,
.sidebar *::before,
.sidebar *::after{
  transform:none !important;
  animation:none !important;
  transition-property:background-color, color, border-color, box-shadow, opacity, width !important;
  transition-duration:120ms !important;
  transition-timing-function:ease !important;
}

.sb-label,
.sb-section-label,
.sb-user{
  transition:opacity 120ms ease !important;
}

.nav-item,
.nav-link,
.menu-item,
.sidebar .sb-nav > a.sb-link,
button,
.btn,
.app-btn,
.account-btn,
.report-btn,
.view-btn,
.export-btn{
  transform:none !important;
  animation:none !important;
  transition-property:background-color, color, border-color, box-shadow, opacity !important;
  transition-duration:120ms !important;
  transition-timing-function:ease !important;
}

.nav-item:hover,
.nav-link:hover,
.menu-item:hover,
.sidebar .sb-nav > a.sb-link:hover,
.sidebar .sb-nav > a.sb-link:active,
.sidebar .sb-nav > a.sb-link:focus,
.sidebar .sb-nav > a.sb-link.active,
button:hover,
button:active,
.btn:hover,
.btn:active,
.app-btn:hover,
.app-btn:active,
.account-btn:hover,
.account-btn:active,
.report-btn:hover,
.report-btn:active,
.view-btn:hover,
.view-btn:active,
.export-btn:hover,
.export-btn:active,
.card:hover,
.panel:hover,
.section-card:hover,
.form-card:hover,
.stat-card:hover,
.report-card:hover,
.dashboard-chart-card:hover,
.import-card:hover,
.account-card:hover{
  transform:none !important;
  animation:none !important;
}
</style>
