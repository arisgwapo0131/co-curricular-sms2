<?php
// ============================================================
//  REPORTS.PHP — Organization Performance & Analytics Reports
//  Standard organizational analytics with CSV & Print Export
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_any_permission(['reports.view.system', 'reports.view.institutional', 'reports.view.org'], null, 'dashboard.php');

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)$_SESSION['user_id'];

// ── Determine Adviser Club Scope ─────────────────────────────
$adviser_club_id = 0;
$adviser_club_name = 'All Organizations (Campus Wide)';
$adviser_club_code = 'BCP';

if ($sess_role === 'club_adviser') {
    $assigned_clubs = get_user_assigned_clubs($conn, $user_id, $sess_role);
    if (!empty($assigned_clubs)) {
        $adviser_club_id = (int)$assigned_clubs[0]['id'];
        $adviser_club_name = $assigned_clubs[0]['name'];
        $adviser_club_code = $assigned_clubs[0]['code'];
    }
}

// ── Aggregate Statistics for Dashboard Cards ────────────────
$stats = [];
if ($adviser_club_id > 0) {
    $stats['total_events']       = (int)$conn->query("SELECT COUNT(*) AS c FROM events WHERE club_id = $adviser_club_id AND deleted_at IS NULL")->fetch_assoc()['c'];
    $stats['approved_events']    = (int)$conn->query("SELECT COUNT(*) AS c FROM events WHERE club_id = $adviser_club_id AND status IN ('Approved','Upcoming','Completed') AND deleted_at IS NULL")->fetch_assoc()['c'];
    $stats['total_members']      = (int)$conn->query("SELECT COUNT(*) AS c FROM club_memberships WHERE club_id = $adviser_club_id AND status='Active'")->fetch_assoc()['c'];
    $stats['total_attendance']   = (int)$conn->query("SELECT COUNT(*) AS c FROM attendance_logs al JOIN events e ON e.id = al.event_id WHERE e.club_id = $adviser_club_id")->fetch_assoc()['c'];
    $stats['total_disbursed']    = (float)$conn->query("SELECT COALESCE(SUM(amount),0) AS s FROM budget_requests WHERE club_id = $adviser_club_id AND status='Disbursed'")->fetch_assoc()['s'];
    $stats['total_achievements'] = (int)$conn->query("SELECT COUNT(*) AS c FROM achievements WHERE club_id = $adviser_club_id AND status='Verified'")->fetch_assoc()['c'];
    $stats['total_registrations']= (int)$conn->query("SELECT COUNT(*) AS c FROM event_registrations er JOIN events e ON e.id = er.event_id WHERE e.club_id = $adviser_club_id")->fetch_assoc()['c'];
    $stats['total_vol_hours']    = (float)$conn->query("SELECT COALESCE(SUM(hours),0) AS s FROM volunteer_logs WHERE club_id = $adviser_club_id AND status='Verified'")->fetch_assoc()['s'];
    $stats['total_volunteers']   = (int)$conn->query("SELECT COUNT(DISTINCT user_id) AS c FROM volunteer_logs WHERE club_id = $adviser_club_id AND status='Verified'")->fetch_assoc()['c'];
    $stats['total_reqs']         = (int)$conn->query("SELECT COUNT(*) AS c FROM budget_requests WHERE club_id = $adviser_club_id")->fetch_assoc()['c'];
    $stats['total_applications'] = (int)$conn->query("SELECT COUNT(*) AS c FROM club_applications WHERE club_id = $adviser_club_id")->fetch_assoc()['c'];
    $stats['total_messages']     = (int)$conn->query("SELECT COUNT(*) AS c FROM chat_messages cm JOIN chat_groups cg ON cg.id = cm.group_id WHERE cg.club_id = $adviser_club_id")->fetch_assoc()['c'];
    $stats['total_users']        = 0;
    $stats['total_clubs']        = 1;
    $stats['total_elections']    = 0;
    $stats['total_votes']        = 0;
    $stats['total_logs']         = 0;
} else {
    $stats['total_events']       = (int)$conn->query("SELECT COUNT(*) AS c FROM events WHERE deleted_at IS NULL")->fetch_assoc()['c'];
    $stats['approved_events']    = (int)$conn->query("SELECT COUNT(*) AS c FROM events WHERE status IN ('Approved','Upcoming','Completed') AND deleted_at IS NULL")->fetch_assoc()['c'];
    $stats['total_members']      = (int)$conn->query("SELECT COUNT(*) AS c FROM club_memberships WHERE status='Active'")->fetch_assoc()['c'];
    $stats['total_attendance']   = (int)$conn->query("SELECT COUNT(*) AS c FROM attendance_logs")->fetch_assoc()['c'];
    $stats['total_disbursed']    = (float)$conn->query("SELECT COALESCE(SUM(amount),0) AS s FROM budget_requests WHERE status='Disbursed'")->fetch_assoc()['s'];
    $stats['total_achievements'] = (int)$conn->query("SELECT COUNT(*) AS c FROM achievements WHERE status='Verified'")->fetch_assoc()['c'];
    $stats['total_registrations']= (int)$conn->query("SELECT COUNT(*) AS c FROM event_registrations")->fetch_assoc()['c'];
    $stats['total_users']        = (int)$conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
    $stats['total_clubs']        = (int)$conn->query("SELECT COUNT(*) AS c FROM clubs WHERE status='Active' AND deleted_at IS NULL")->fetch_assoc()['c'];
    $stats['total_elections']    = (int)$conn->query("SELECT COUNT(*) AS c FROM elections")->fetch_assoc()['c'];
    $stats['total_votes']        = (int)$conn->query("SELECT COUNT(*) AS c FROM election_votes")->fetch_assoc()['c'];
    $stats['total_logs']         = (int)$conn->query("SELECT COUNT(*) AS c FROM audit_logs")->fetch_assoc()['c'];
    $stats['total_reqs']         = (int)$conn->query("SELECT COUNT(*) AS c FROM budget_requests")->fetch_assoc()['c'];
    $stats['total_vol_hours']    = (float)$conn->query("SELECT COALESCE(SUM(hours),0) AS s FROM volunteer_logs WHERE status='Verified'")->fetch_assoc()['s'];
    $stats['total_volunteers']   = (int)$conn->query("SELECT COUNT(DISTINCT user_id) AS c FROM volunteer_logs WHERE status='Verified'")->fetch_assoc()['c'];
    $stats['total_applications'] = (int)$conn->query("SELECT COUNT(*) AS c FROM club_applications")->fetch_assoc()['c'];
    $stats['total_messages']     = (int)$conn->query("SELECT COUNT(*) AS c FROM chat_messages")->fetch_assoc()['c'];
}

$page_titles = [
    'super_admin' => 'Super Admin Reports & Institutional Analytics',
    'admin'       => 'Administrative Performance Reports & Analytics',
    'staff'       => 'Office of Student Affairs (OSA) Performance Reports & Analytics',
    'ssc'         => 'Supreme Student Council (SSC) Governance Reports & Analytics',
    'club_adviser'=> 'Organization Reports & Analytics'
];
$current_page_title = $page_titles[$sess_role] ?? 'Performance Reports & Analytics';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($current_page_title) ?> – BCP Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <style>
  /* ── Clean & Polished Reports Page Styles ─────────────────── */
  .content {
    flex: 1;
    display: flex;
    flex-direction: column;
  }
  .content-body {
    flex: 1;
  }
  .footer {
    margin-top: auto;
    flex-shrink: 0;
  }
  .page-title-bar {
    background: #ffffff;
    padding: 20px 24px;
    border-bottom: 1px solid #e8edf4;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
  }
  .page-title {
    margin: 0;
    font-size: 1.35rem;
    font-weight: 800;
    color: #063469;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: -0.01em;
  }
  .org-scope-badge {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    padding: 7px 18px;
    border-radius: 24px;
    font-size: 0.82rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 1px 3px rgba(37,99,235,0.06);
  }

  /* 1. Sleek Compact Metrics Cards (Even Padding & Layout - Consistent Sizes) */
  .metrics-grid {
    display: grid !important;
    grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
    gap: 14px !important;
    margin-bottom: 22px !important;
    align-items: stretch !important;
  }
  .metric-card {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 12px !important;
    padding: 14px 16px !important;
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04) !important;
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease !important;
    min-width: 0 !important;
    width: 100% !important;
    height: 100% !important;
    min-height: 76px !important;
    box-sizing: border-box !important;
  }
  .metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08) !important;
    border-color: #cbd5e1 !important;
  }
  .metric-icon-wrap {
    width: 44px !important;
    height: 44px !important;
    border-radius: 10px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 1.15rem !important;
    flex-shrink: 0 !important;
  }
  .metric-info {
    display: flex !important;
    flex-direction: column !important;
    justify-content: center !important;
    flex: 1 1 0 !important;
    min-width: 0 !important;
    overflow: hidden !important;
  }
  .metric-val {
    font-size: 1.35rem !important;
    font-weight: 800 !important;
    color: #0f172a !important;
    line-height: 1.15 !important;
    white-space: nowrap !important;
  }
  .metric-lbl {
    font-size: 0.72rem !important;
    font-weight: 700 !important;
    color: #64748b !important;
    text-transform: uppercase !important;
    letter-spacing: 0.04em !important;
    margin-top: 3px !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
  }

  /* 2. Modern Report Catalog Table */
  .report-catalog-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    overflow: hidden;
    margin-bottom: 24px;
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.04);
  }
  .catalog-header {
    padding: 18px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    background: #ffffff;
  }
  .catalog-title {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 9px;
  }
  .catalog-sub {
    font-size: 0.79rem;
    color: #64748b;
    margin-top: 4px;
    line-height: 1.4;
  }
  .catalog-count-badge {
    background: #f8fafc;
    color: #334155;
    border: 1px solid #e2e8f0;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 0.76rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .catalog-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.84rem;
    text-align: left;
    table-layout: auto;
  }
  .catalog-table thead th {
    background: #f8fafc;
    color: #475569;
    padding: 12px 16px;
    font-weight: 700;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 2px solid #e2e8f0;
  }
  .catalog-table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    color: #334155;
    transition: background 0.15s ease;
  }
  .catalog-table tbody tr {
    cursor: pointer;
    transition: all 0.15s ease;
  }
  .catalog-table tbody tr:hover td {
    background: #f8faff;
  }
  .catalog-table tbody tr.active-row td {
    background: #eff6ff;
  }
  .catalog-table tbody tr.active-row {
    box-shadow: inset 4px 0 0 #2563eb;
  }
  .catalog-table tbody tr:last-child td {
    border-bottom: none;
  }

  /* Catalog Row Elements */
  .module-cell {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .module-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
  }
  .module-name-wrap {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
  }
  .module-title {
    font-size: 0.88rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.25;
    word-break: normal;
    overflow-wrap: break-word;
  }
  .module-tag {
    display: inline-block;
    font-size: 0.65rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 4px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    width: fit-content;
  }
  .tag-blue { background: #dbeafe; color: #1d4ed8; }
  .tag-green { background: #dcfce7; color: #15803d; }
  .tag-amber { background: #fef3c7; color: #b45309; }
  .tag-purple { background: #ede9fe; color: #6d28d9; }
  .tag-slate { background: #f1f5f9; color: #334155; }
  .tag-rose { background: #ffe4e6; color: #be123c; }
  .tag-cyan { background: #cffafe; color: #0e7490; }
  .tag-indigo { background: #e0e7ff; color: #4338ca; }

  .admin-metrics-grid {
    display: grid !important;
    grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
    gap: 14px !important;
    margin-bottom: 24px !important;
    align-items: stretch !important;
  }
  @media (max-width: 1300px) {
    .admin-metrics-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    }
  }
  @media (max-width: 768px) {
    .admin-metrics-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
  }
  @media (max-width: 480px) {
    .admin-metrics-grid {
      grid-template-columns: 1fr !important;
    }
  }

  .desc-text {
    font-size: 0.80rem;
    color: #64748b;
    line-height: 1.45;
    overflow-wrap: break-word;
  }
  .stat-badge-cell {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.76rem;
    font-weight: 600;
    color: #334155;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 5px 10px;
    border-radius: 7px;
    line-height: 1.3;
    overflow-wrap: break-word;
  }

  .fmt-pills-row {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
  }
  .fmt-pill {
    display: inline-flex;
    align-items: center;
    font-size: 0.68rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 5px;
    background: #f8fafc;
    color: #475569;
    border: 1px solid #e2e8f0;
    white-space: nowrap;
    line-height: 1.3;
  }

  .btn-gen-row {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 0.80rem;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(37,99,235,0.2);
    transition: all 0.15s ease;
    white-space: nowrap;
  }
  .btn-gen-row:hover {
    background: #1d4ed8;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(37,99,235,0.28);
  }
  .btn-gen-row.is-loading {
    background: #64748b;
    cursor: wait;
    box-shadow: none;
  }
  .btn-gen-row.is-active {
    background: #059669;
    box-shadow: 0 2px 6px rgba(5,150,105,0.2);
  }

  /* Responsive layout for tablets and mobile: transforms table rows into clean cards with zero horizontal scroll */
  @media (max-width: 920px) {
    .catalog-header {
      flex-direction: column;
      align-items: flex-start;
      gap: 12px;
      padding: 16px;
    }
    .catalog-table thead {
      display: none;
    }
    .catalog-table,
    .catalog-table tbody {
      display: block;
      width: 100%;
    }
    .catalog-table tbody {
      padding: 12px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .catalog-table tbody tr {
      display: grid;
      grid-template-columns: 1fr auto;
      grid-template-areas:
        "mod-title  mod-title"
        "mod-desc   mod-desc"
        "mod-scope  mod-fmt"
        "mod-action mod-action";
      gap: 10px 12px;
      align-items: center;
      background: #ffffff;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      padding: 16px;
      box-shadow: 0 1px 4px rgba(0,0,0,0.03);
      box-sizing: border-box;
      width: 100%;
    }
    .catalog-table tbody tr:hover td {
      background: transparent;
    }
    .catalog-table tbody tr:hover {
      border-color: #93c5fd;
      background: #f8faff;
    }
    .catalog-table tbody tr.active-row td {
      background: transparent;
    }
    .catalog-table tbody tr.active-row {
      border-color: #2563eb;
      background: #eff6ff;
      box-shadow: 0 3px 10px rgba(37,99,235,0.12);
    }
    .catalog-table tbody td {
      display: block;
      padding: 0;
      border: none;
      width: auto;
    }
    .catalog-table tbody td:nth-child(1) {
      grid-area: mod-title;
    }
    .catalog-table tbody td:nth-child(2) {
      grid-area: mod-desc;
    }
    .catalog-table tbody td:nth-child(3) {
      grid-area: mod-scope;
    }
    .catalog-table tbody td:nth-child(4) {
      grid-area: mod-fmt;
      text-align: right;
    }
    .catalog-table tbody td:nth-child(5) {
      grid-area: mod-action;
      padding-top: 10px;
      border-top: 1px dashed #e2e8f0;
      text-align: right;
    }
    .catalog-table tbody td:nth-child(5) .btn-gen-row {
      width: 100%;
      justify-content: center;
      padding: 10px 16px;
      font-size: 0.85rem;
    }
  }

  @media (max-width: 560px) {
    .catalog-table tbody tr {
      grid-template-columns: 1fr;
      grid-template-areas:
        "mod-title"
        "mod-desc"
        "mod-scope"
        "mod-fmt"
        "mod-action";
    }
    .catalog-table tbody td:nth-child(4) {
      text-align: left;
    }
  }

  /* 3. Report Output Modal Popup */
  .report-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.68);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 24px;
    box-sizing: border-box;
    opacity: 0;
    transition: opacity 0.2s ease-in-out;
  }
  .report-modal-overlay.active {
    display: flex !important;
    opacity: 1;
  }
  .report-modal-dialog {
    background: #ffffff;
    border-radius: 18px;
    border: 1px solid rgba(226, 232, 240, 0.9);
    width: 100%;
    max-width: 1180px;
    max-height: 88vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(0,0,0,0.05);
    overflow: hidden;
    transform: scale(0.96) translateY(10px);
    transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .report-modal-overlay.active .report-modal-dialog {
    transform: scale(1) translateY(0);
  }
  .report-output-header {
    background: #0f172a;
    padding: 18px 24px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    flex-shrink: 0;
  }
  .report-output-header h3 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .report-body {
    padding: 24px;
    overflow-y: auto;
    flex: 1;
    max-height: calc(88vh - 80px);
    background: #fff;
  }

  /* Formal Institutional Document Styles */
  .doc-letterhead {
    display: flex;
    align-items: center;
    gap: 18px;
    padding-bottom: 16px;
    margin-bottom: 16px;
    border-bottom: 2px solid #0f172a;
  }
  .doc-letterhead-logo {
    height: 60px;
    width: auto;
    object-fit: contain;
    flex-shrink: 0;
  }
  .doc-letterhead-text {
    flex: 1;
    min-width: 0;
  }
  .doc-letterhead-title {
    font-size: 1.12rem;
    font-weight: 900;
    color: #0f172a;
    letter-spacing: -0.01em;
    text-transform: uppercase;
    line-height: 1.2;
    margin: 0;
  }
  .doc-letterhead-sub {
    font-size: 0.76rem;
    color: #475569;
    font-weight: 600;
    margin-top: 3px;
    line-height: 1.35;
  }
  .doc-meta-matrix {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 8px 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 20px;
    font-size: 0.78rem;
  }
  .doc-meta-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }
  .doc-meta-lbl {
    font-size: 0.68rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }
  .doc-meta-val {
    font-weight: 700;
    color: #0f172a;
  }

  /* Formal Section Dividers */
  .doc-section-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.86rem;
    font-weight: 800;
    color: #0f172a;
    margin-top: 22px;
    margin-bottom: 12px;
    padding-bottom: 6px;
    border-bottom: 1.5px solid #e2e8f0;
    text-transform: uppercase;
    letter-spacing: 0.03em;
  }
  .doc-section-header i {
    color: #2563eb;
  }

  /* Signatures Block */
  .doc-signatures-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    margin-top: 28px;
    padding-top: 20px;
    border-top: 1.5px dashed #cbd5e1;
  }
  @media (max-width: 768px) {
    .doc-signatures-grid {
      grid-template-columns: 1fr;
      gap: 14px;
    }
  }
  .doc-sign-box {
    text-align: center;
    padding: 12px 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
  }
  .doc-sign-role-title {
    font-size: 0.68rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    margin-bottom: 28px;
    letter-spacing: 0.03em;
  }
  .doc-sign-line {
    border-top: 1.5px solid #0f172a;
    padding-top: 6px;
    font-size: 0.80rem;
    font-weight: 800;
    color: #0f172a;
  }
  .doc-sign-desig {
    font-size: 0.69rem;
    color: #64748b;
    margin-top: 2px;
  }

  /* Executive Digest / Intelligent Narrative Banner */
  .exec-digest-banner {
    background: linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%);
    border: 1px solid #bfdbfe;
    border-radius: 14px;
    padding: 18px 20px;
    margin-bottom: 22px;
    display: flex;
    gap: 16px;
    align-items: flex-start;
    box-shadow: 0 1px 3px rgba(37,99,235,0.06);
  }
  .exec-digest-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: #2563eb;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
    box-shadow: 0 4px 10px rgba(37,99,235,0.25);
  }
  .exec-digest-content {
    flex: 1;
    min-width: 0;
  }
  .exec-digest-title {
    font-size: 0.98rem;
    font-weight: 800;
    color: #1e3a8a;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .exec-digest-text {
    font-size: 0.84rem;
    color: #334155;
    line-height: 1.55;
    margin-bottom: 12px;
  }
  .exec-findings-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 8px 16px;
    padding-top: 10px;
    border-top: 1px solid rgba(191, 219, 254, 0.7);
  }
  .exec-finding-item {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 0.79rem;
    font-weight: 600;
    color: #1e293b;
    line-height: 1.4;
  }
  .exec-finding-item i {
    color: #16a34a;
    font-size: 0.82rem;
    margin-top: 2px;
    flex-shrink: 0;
  }

  /* Breakdown Summary Card */
  .report-breakdown-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 18px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(15,23,42,0.03);
  }
  .breakdown-card-title {
    font-size: 0.88rem;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .report-breakdown-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.80rem;
  }
  .report-breakdown-table th {
    background: #f8fafc;
    color: #475569;
    padding: 8px 12px;
    font-size: 0.72rem;
    text-transform: uppercase;
    font-weight: 700;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
  }
  .report-breakdown-table td {
    padding: 9px 12px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
  }
  .report-breakdown-table tr:last-child td {
    border-bottom: none;
  }
  .breakdown-pill-count {
    background: #f1f5f9;
    color: #1e293b;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 0.75rem;
    display: inline-block;
  }
  .breakdown-pill-share {
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 0.75rem;
    display: inline-block;
  }
  .breakdown-status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
  }
  .breakdown-status-badge.status-cleared,
  .breakdown-status-badge.status-compliant,
  .breakdown-status-badge.status-certified,
  .breakdown-status-badge.status-active-roster,
  .breakdown-status-badge.status-accredited,
  .breakdown-status-badge.status-verified-qr,
  .breakdown-status-badge.status-paperless-standard,
  .breakdown-status-badge.status-certified-audit {
    background: #dcfce7;
    color: #15803d;
  }
  .breakdown-status-badge.status-in-review,
  .breakdown-status-badge.status-pending-scale {
    background: #fef3c7;
    color: #b45309;
  }
  .breakdown-status-badge.status-audited,
  .breakdown-status-badge.status-active,
  .breakdown-status-badge.status-tier-1---full-access,
  .breakdown-status-badge.status-tier-2---governance,
  .breakdown-status-badge.status-tier-3---org-endorsement,
  .breakdown-status-badge.status-tier-4---portal-access {
    background: #e0e7ff;
    color: #4338ca;
  }

  .report-section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
    margin-top: 14px;
    flex-wrap: wrap;
    gap: 8px;
  }
  .report-section-title {
    font-size: 0.88rem;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .report-search-input {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 0.78rem;
    color: #1e293b;
    outline: none;
    transition: border-color 0.15s ease;
    width: 220px;
  }
  .report-search-input:focus {
    border-color: #2563eb;
  }

  /* Summary KPI Highlights in Report */
  .report-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 12px;
    margin-bottom: 22px;
  }
  .report-summary-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .report-summary-box .box-icon {
    width: 38px; height: 38px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.95rem; flex-shrink: 0;
  }
  .report-summary-box .box-val { font-size: 1.25rem; font-weight: 800; color: #0f172a; line-height: 1.2; }
  .report-summary-box .box-lbl { font-size: 0.70rem; font-weight: 700; color: #64748b; text-transform: uppercase; }

  /* Report Table Styles */
  .report-table-wrap {
    width: 100%;
    overflow-x: auto;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    margin-bottom: 20px;
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 #f8fafc;
  }
  .report-table-wrap::-webkit-scrollbar {
    height: 5px;
  }
  .report-table-wrap::-webkit-scrollbar-track {
    background: #f8fafc;
    border-radius: 4px;
  }
  .report-table-wrap::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
  }
  .report-table-wrap::-webkit-scrollbar-button {
    display: none;
    width: 0;
    height: 0;
  }
  .report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
    text-align: left;
  }
  .report-table th {
    background: #f1f5f9;
    color: #334155;
    padding: 10px 14px;
    font-weight: 700;
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border-bottom: 1.5px solid #cbd5e1;
    overflow-wrap: break-word;
  }
  .report-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    overflow-wrap: break-word;
  }
  .report-table tr:hover td {
    background: #f8fafc;
  }
  .report-table tr:last-child td {
    border-bottom: none;
  }

  /* Export Action Buttons */
  .btn-export-csv {
    background: #059669;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
  }
  .btn-export-csv:hover { background: #047857; }

  .btn-print {
    background: #334155;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
  }
  .btn-print:hover { background: #1e293b; }

  .btn-close-report {
    background: rgba(255,255,255,0.15);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.25);
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
  }
  .btn-close-report:hover {
    background: rgba(255,255,255,0.25);
  }

  /* Official Footer Note */
  .report-official-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
    font-size: 0.75rem;
    color: #64748b;
    flex-wrap: wrap;
    gap: 8px;
  }

  @media print {
    body { overflow: visible !important; }
    .sidebar, .topbar, .page-title-bar, .metrics-grid,
    .report-catalog-card, .btn-print, .btn-export-csv, .btn-close-report, .footer { display: none !important; }
    .report-modal-overlay {
      position: static !important;
      display: block !important;
      width: 100% !important;
      height: auto !important;
      background: none !important;
      backdrop-filter: none !important;
      padding: 0 !important;
      opacity: 1 !important;
      z-index: auto !important;
    }
    .report-modal-dialog {
      box-shadow: none !important;
      border: none !important;
      max-width: 100% !important;
      max-height: none !important;
      overflow: visible !important;
      transform: none !important;
      display: block !important;
    }
    .report-output-header {
      background: #1e293b !important;
      color: #fff !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .report-body {
      overflow: visible !important;
      max-height: none !important;
      padding: 16px 0 !important;
    }
    .report-table-wrap {
      overflow: visible !important;
      border: 1px solid #cbd5e1 !important;
    }
    .main { margin: 0 !important; padding: 0 !important; width: 100% !important; }
    .content { padding: 0 !important; }
  }

  @media (max-width: 1200px) {
    .metrics-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
      gap: 12px !important;
    }
  }
  @media (max-width: 640px) {
    .page-title-bar {
      padding: 14px 16px;
    }
    .metrics-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      gap: 10px !important;
    }
    .metric-card {
      padding: 12px 14px !important;
    }
  }
  </style>
</head>
<body>
<?php $APP_ROOT = '../'; $ACTIVE_NAV = 'reports'; require_once __DIR__ . '/../shared/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap" id="topbarSearchWrap">
        <i class="fa-solid fa-magnifying-glass search-icon"></i>
        <input type="text" placeholder="Search modules, events, clubs..." autocomplete="off" />
        <button type="button" class="search-clear-btn" aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <button class="topbar-chat-btn" id="chatFabBtn" title="Inter-Club Messenger" type="button"><i class="fa-solid fa-comment-dots"></i><span class="chat-unread-badge" id="chatUnreadBadge" style="display:none;"></span></button>
      <?php if (($sess_role ?? $_SESSION['role'] ?? '') === 'student'): ?>
      <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code" type="button"><i class="fa-solid fa-qrcode"></i></button>
      <?php endif; ?>
      <a href="account.php" class="avatar" id="avatarBtn" title="Account Settings">
        <?php if (!empty($sess_pic) && file_exists(__DIR__ . '/../uploads/avatars/' . $sess_pic)): ?>
          <img src="../uploads/avatars/<?= htmlspecialchars($sess_pic) ?>" alt="Profile"/>
        <?php else: ?>
          <?= $sess_initial ?>
        <?php endif; ?>
      </a>
    </div>
  </div>

  <div class="content">

    <!-- Page Title Bar (Clean Heading Only - No Extra Infos or Buttons) -->
    <div class="page-title-bar">
      <h2 class="page-title">
        <i class="fa-solid fa-chart-column" style="color:#2563eb;"></i>
        <?= htmlspecialchars($current_page_title) ?>
      </h2>
    </div>

    <div class="content-body">

      <!-- 1. Sleek Compact Metrics Row (Evenly Padded, Distinct Cards) -->
      <?php if ($sess_role === 'super_admin' || $sess_role === 'admin'): ?>
      <div class="metrics-grid admin-metrics-grid">
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-id-card"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_applications'] ?></div>
            <div class="metric-lbl">Club Registration Portal</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#dcfce7; color:#16a34a;"><i class="fa-solid fa-users"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_members'] ?></div>
            <div class="metric-lbl">Student Club Memberships</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#e0f2fe; color:#0284c7;"><i class="fa-solid fa-check-to-slot"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_elections'] ?></div>
            <div class="metric-lbl">Club Officer Elections</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#e0e7ff; color:#4338ca;"><i class="fa-solid fa-calendar-check"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_events'] ?></div>
            <div class="metric-lbl">Event &amp; Activity Logs</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-clipboard-check"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_attendance'] ?></div>
            <div class="metric-lbl">Attendance Tracker</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#fce7f3; color:#db2777;"><i class="fa-solid fa-trophy"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_achievements'] ?></div>
            <div class="metric-lbl">Club Achievement Records</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#d1fae5; color:#059669;"><i class="fa-solid fa-file-invoice-dollar"></i></div>
          <div class="metric-info">
            <div class="metric-val">₱<?= number_format($stats['total_disbursed'], 2) ?></div>
            <div class="metric-lbl">Budget Requests</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#ccfbf1; color:#0f766e;"><i class="fa-solid fa-comments"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_messages'] ?></div>
            <div class="metric-lbl">Inter-Club Communication</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#ede9fe; color:#6d28d9;"><i class="fa-solid fa-hand-holding-heart"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= number_format($stats['total_vol_hours'], 1) ?>h</div>
            <div class="metric-lbl">Volunteer Hour Tracking</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#f1f5f9; color:#334155;"><i class="fa-solid fa-building-columns"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_clubs'] ?></div>
            <div class="metric-lbl">Club Directory</div>
          </div>
        </div>
      </div>

      <?php elseif ($sess_role === 'staff'): ?>
      <div class="metrics-grid">
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-id-card"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_applications'] ?></div>
            <div class="metric-lbl">Club Registration Portal</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#dcfce7; color:#16a34a;"><i class="fa-solid fa-users"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_members'] ?></div>
            <div class="metric-lbl">Student Club Memberships</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#e0e7ff; color:#4338ca;"><i class="fa-solid fa-calendar-days"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_events'] ?></div>
            <div class="metric-lbl">Event &amp; Activity Logs</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-clipboard-user"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_attendance'] ?></div>
            <div class="metric-lbl">Attendance Tracker</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#fce7f3; color:#db2777;"><i class="fa-solid fa-trophy"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_achievements'] ?></div>
            <div class="metric-lbl">Club Achievement Records</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#ede9fe; color:#6d28d9;"><i class="fa-solid fa-hand-holding-heart"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= number_format($stats['total_vol_hours'], 1) ?> hrs</div>
            <div class="metric-lbl">Volunteer Hour Tracking</div>
          </div>
        </div>
      </div>

      <?php elseif ($sess_role === 'ssc'): ?>
      <div class="metrics-grid">
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-id-card"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_applications'] ?></div>
            <div class="metric-lbl">Club Registration Portal</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#dcfce7; color:#16a34a;"><i class="fa-solid fa-users"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_members'] ?></div>
            <div class="metric-lbl">Student Club Memberships</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#e0f2fe; color:#0284c7;"><i class="fa-solid fa-check-to-slot"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_elections'] ?></div>
            <div class="metric-lbl">Club Officer Elections</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#e0e7ff; color:#4338ca;"><i class="fa-solid fa-calendar-check"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_events'] ?></div>
            <div class="metric-lbl">Event &amp; Activity Logs</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-clipboard-check"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_attendance'] ?></div>
            <div class="metric-lbl">Attendance Tracker</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#d1fae5; color:#059669;"><i class="fa-solid fa-file-invoice-dollar"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_reqs'] ?></div>
            <div class="metric-lbl">Budget Requests</div>
          </div>
        </div>
      </div>

      <?php else: ?>
      <div class="metrics-grid">
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#dbeafe; color:#2563eb;"><i class="fa-solid fa-id-card"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_applications'] ?></div>
            <div class="metric-lbl">Club Registration Portal</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#dcfce7; color:#16a34a;"><i class="fa-solid fa-users"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_members'] ?></div>
            <div class="metric-lbl">Student Club Memberships</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#e0e7ff; color:#4338ca;"><i class="fa-solid fa-calendar-days"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_events'] ?></div>
            <div class="metric-lbl">Event &amp; Activity Logs</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-clipboard-check"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_attendance'] ?></div>
            <div class="metric-lbl">Attendance Tracker</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#fce7f3; color:#db2777;"><i class="fa-solid fa-trophy"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_achievements'] ?></div>
            <div class="metric-lbl">Club Achievement Records</div>
          </div>
        </div>
        <div class="metric-card">
          <div class="metric-icon-wrap" style="background:#d1fae5; color:#059669;"><i class="fa-solid fa-file-invoice-dollar"></i></div>
          <div class="metric-info">
            <div class="metric-val"><?= $stats['total_reqs'] ?></div>
            <div class="metric-lbl">Budget Requests</div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- 2. Reports Table Catalog -->
      <div class="report-catalog-card">
        <div class="catalog-header">
          <div>
            <div class="catalog-title">
              <i class="fa-solid fa-layer-group" style="color:#2563eb;"></i>
              Co-Curricular Microservices &amp; Reports
            </div>
            <div class="catalog-sub">
              Official microservice records, executive audit summaries, and operational reports ready for instant generation, CSV export, or printing.
            </div>
          </div>
          <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <?php if (!empty($adviser_club_name)): ?>
            <span class="org-scope-badge">
              <i class="fa-solid fa-building-columns"></i>
              <span><?= htmlspecialchars($adviser_club_name) ?> (<?= htmlspecialchars($adviser_club_code) ?>)</span>
            </span>
            <?php endif; ?>
            <span class="catalog-count-badge">
              <i class="fa-solid fa-cube" style="color:#2563eb;"></i> 
              <?php
                if ($sess_role === 'super_admin' || $sess_role === 'admin' || $sess_role === 'ssc') echo '10 Microservices + Summary';
                elseif ($sess_role === 'staff') echo '8 Microservices + Summary';
                else echo '8 Microservices + Summary';
              ?>
            </span>
          </div>
        </div>

        <div class="table-wrap">
          <table class="catalog-table" id="reportCatalogTable">
            <thead>
              <tr>
                <th style="width: 26%;">Report Module</th>
                <th style="width: 34%;">Coverage &amp; Included Records</th>
                <th style="width: 18%;">Current Scope</th>
                <th style="width: 10%;">Output</th>
                <th style="width: 12%; text-align: right;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($sess_role === 'super_admin' || $sess_role === 'admin'): ?>
              <!-- Admin Microservice 1: Club Registration Portal -->
              <tr id="row-club_registration_portal" data-type="club_registration_portal" onclick="handleRowClick(event, 'club_registration_portal')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#dbeafe; color:#1d4ed8;">
                      <i class="fa-solid fa-id-card"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Club Registration Portal</span>
                      <span class="module-tag tag-blue">Registration</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Student organization applications, multi-tier endorsements (Adviser &amp; SSC), and membership approval status logs.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-address-card" style="color:#1d4ed8;"></i>
                    <?= $stats['total_applications'] ?> Applications Logged
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="club_registration_portal" onclick="generateReportType('club_registration_portal', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Microservice 2: Student Club Memberships -->
              <tr id="row-student_club_memberships" data-type="student_club_memberships" onclick="handleRowClick(event, 'student_club_memberships')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#dcfce7; color:#15803d;">
                      <i class="fa-solid fa-users"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Student Club Memberships</span>
                      <span class="module-tag tag-green">Memberships</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Active student memberships, executive leadership designations, degree programs, and recruitment trends.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-users-gear" style="color:#15803d;"></i>
                    <?= $stats['total_members'] ?> Active Members
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="student_club_memberships" onclick="generateReportType('student_club_memberships', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Microservice 3: Club Officer Elections -->
              <tr id="row-club_officer_elections" data-type="club_officer_elections" onclick="handleRowClick(event, 'club_officer_elections')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#e0f2fe; color:#0284c7;">
                      <i class="fa-solid fa-check-to-slot"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Club Officer Elections</span>
                      <span class="module-tag tag-blue">Governance</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Student governance elections, candidate filings, audited democratic ballot counts, and certification statuses.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-envelope-open-text" style="color:#0284c7;"></i>
                    <?= $stats['total_elections'] ?> Elections (<?= $stats['total_votes'] ?> Votes)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="club_officer_elections" onclick="generateReportType('club_officer_elections', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Microservice 4: Event & Activity Logs -->
              <tr id="row-event_activity_logs" data-type="event_activity_logs" onclick="handleRowClick(event, 'event_activity_logs')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#e0e7ff; color:#4338ca;">
                      <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Event &amp; Activity Logs</span>
                      <span class="module-tag tag-indigo">Activities</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Multi-tier event proposals, SSC endorsements, administrative clearance permits, and calendar schedules.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-calendar-days" style="color:#4338ca;"></i>
                    <?= $stats['total_events'] ?> Proposals (<?= $stats['approved_events'] ?> Cleared)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="event_activity_logs" onclick="generateReportType('event_activity_logs', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Microservice 5: Attendance Tracker -->
              <tr id="row-attendance_tracker" data-type="attendance_tracker" onclick="handleRowClick(event, 'attendance_tracker')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#fef3c7; color:#b45309;">
                      <i class="fa-solid fa-clipboard-user"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Attendance Tracker</span>
                      <span class="module-tag tag-amber">Attendance</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Campus-wide event turnout, QR scan validation logs, manual check-in overrides, and participation rates.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-clipboard-check" style="color:#b45309;"></i>
                    <?= $stats['total_attendance'] ?> Verified Logs
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="attendance_tracker" onclick="generateReportType('attendance_tracker', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Microservice 6: Club Achievement Records -->
              <tr id="row-club_achievement_records" data-type="club_achievement_records" onclick="handleRowClick(event, 'club_achievement_records')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#ffe4e6; color:#be123c;">
                      <i class="fa-solid fa-trophy"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Club Achievement Records</span>
                      <span class="module-tag tag-rose">Honors</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Student and organization competition achievements, verification statuses, awards tally, and honors registry.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-award" style="color:#be123c;"></i>
                    <?= $stats['total_achievements'] ?> Verified Honors
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="club_achievement_records" onclick="generateReportType('club_achievement_records', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Microservice 7: Budget Requests -->
              <tr id="row-budget_requests" data-type="budget_requests" onclick="handleRowClick(event, 'budget_requests')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#d1fae5; color:#059669;">
                      <i class="fa-solid fa-file-invoice-dollar"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Budget Requests</span>
                      <span class="module-tag tag-green">Finances</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Council and organization fund allocations, expense requisitions, disbursements, and release records.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-money-bill-transfer" style="color:#059669;"></i>
                    <?= $stats['total_reqs'] ?> Requisitions (₱<?= number_format($stats['total_disbursed'], 2) ?>)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="budget_requests" onclick="generateReportType('budget_requests', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Microservice 8: Inter-club Communication -->
              <tr id="row-inter_club_communication" data-type="inter_club_communication" onclick="handleRowClick(event, 'inter_club_communication')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#ccfbf1; color:#0f766e;">
                      <i class="fa-solid fa-comments"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Inter-club Communication</span>
                      <span class="module-tag tag-cyan">Messaging</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Inter-club chat group telemetry, council broadcast notices, executive channels, and active student messaging.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-comment-dots" style="color:#0f766e;"></i>
                    <?= $stats['total_messages'] ?> Messages Logged
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="inter_club_communication" onclick="generateReportType('inter_club_communication', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Microservice 9: Volunteer Hour Tracking -->
              <tr id="row-volunteer_hour_tracking" data-type="volunteer_hour_tracking" onclick="handleRowClick(event, 'volunteer_hour_tracking')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#ede9fe; color:#6d28d9;">
                      <i class="fa-solid fa-hand-holding-heart"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Volunteer Hour Tracking</span>
                      <span class="module-tag tag-purple">Community</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Student volunteer service hours, community engagement logs, verifier signatures, and civic impact tracking.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-clock-rotate-left" style="color:#6d28d9;"></i>
                    <?= number_format($stats['total_vol_hours'], 1) ?> Hours (<?= $stats['total_volunteers'] ?> Logs)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="volunteer_hour_tracking" onclick="generateReportType('volunteer_hour_tracking', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Microservice 10: Club Directory -->
              <tr id="row-club_directory" data-type="club_directory" onclick="handleRowClick(event, 'club_directory')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#f1f5f9; color:#334155;">
                      <i class="fa-solid fa-building-columns"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Club Directory</span>
                      <span class="module-tag tag-slate">Directory</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Accredited student organizations roster, faculty adviser appointments, membership scale, and council affiliations.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-users-viewfinder" style="color:#334155;"></i>
                    <?= $stats['total_clubs'] ?> Active Clubs
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="club_directory" onclick="generateReportType('club_directory', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Admin Master Summary: Comprehensive Co-Curricular Summary -->
              <tr id="row-comprehensive" data-type="comprehensive" onclick="handleRowClick(event, 'comprehensive')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#0f172a; color:#ffffff;">
                      <i class="fa-solid fa-file-waveform"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Comprehensive Co-Curricular Summary</span>
                      <span class="module-tag tag-slate">Executive</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Master consolidated executive performance review synthesizing all co-curricular microservices, accreditation, and institutional compliance.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-layer-group" style="color:#0f172a;"></i>
                    Institutional Master Registry
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="comprehensive" onclick="generateReportType('comprehensive', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <?php elseif ($sess_role === 'staff'): ?>
              <!-- Staff Microservice 1: Club Registration Portal -->
              <tr id="row-club_registration_portal" data-type="club_registration_portal" onclick="handleRowClick(event, 'club_registration_portal')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#dbeafe; color:#1d4ed8;">
                      <i class="fa-solid fa-id-card"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Club Registration Portal</span>
                      <span class="module-tag tag-blue">Registration</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    OSA student organization applications, endorsement tracking, and admission clearances.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-address-card" style="color:#1d4ed8;"></i>
                    <?= $stats['total_applications'] ?> Applications Logged
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="club_registration_portal" onclick="generateReportType('club_registration_portal', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Staff Microservice 2: Student Club Memberships -->
              <tr id="row-student_club_memberships" data-type="student_club_memberships" onclick="handleRowClick(event, 'student_club_memberships')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#dcfce7; color:#16a34a;">
                      <i class="fa-solid fa-users"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Student Club Memberships</span>
                      <span class="module-tag tag-green">Memberships</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Active student leaders, designated club officers, academic programs, and verified memberships.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-users" style="color:#16a34a;"></i>
                    <?= $stats['total_members'] ?> Active Members
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="student_club_memberships" onclick="generateReportType('student_club_memberships', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Staff Microservice 3: Event & Activity Logs -->
              <tr id="row-event_activity_logs" data-type="event_activity_logs" onclick="handleRowClick(event, 'event_activity_logs')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#e0e7ff; color:#4338ca;">
                      <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Event &amp; Activity Logs</span>
                      <span class="module-tag tag-indigo">Activities</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Student affairs activity permits, multi-tier approvals, venue allocations, and calendar schedules.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-calendar-days" style="color:#4338ca;"></i>
                    <?= $stats['total_events'] ?> Activities (<?= $stats['approved_events'] ?> Cleared)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="event_activity_logs" onclick="generateReportType('event_activity_logs', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Staff Microservice 4: Attendance Tracker -->
              <tr id="row-attendance_tracker" data-type="attendance_tracker" onclick="handleRowClick(event, 'attendance_tracker')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#fef3c7; color:#b45309;">
                      <i class="fa-solid fa-clipboard-user"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Attendance Tracker</span>
                      <span class="module-tag tag-amber">Attendance</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Verified QR scan records, student turnout logs, and campus-wide participation analytics.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-clipboard-check" style="color:#b45309;"></i>
                    <?= $stats['total_attendance'] ?> Verified Turnout Logs
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="attendance_tracker" onclick="generateReportType('attendance_tracker', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Staff Microservice 5: Club Achievement Records -->
              <tr id="row-club_achievement_records" data-type="club_achievement_records" onclick="handleRowClick(event, 'club_achievement_records')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#ffe4e6; color:#be123c;">
                      <i class="fa-solid fa-trophy"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Club Achievement Records</span>
                      <span class="module-tag tag-rose">Honors</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Verified competition titles, institutional recognitions, student award certifications, and commendations.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-award" style="color:#be123c;"></i>
                    <?= $stats['total_achievements'] ?> Verified Honors
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="club_achievement_records" onclick="generateReportType('club_achievement_records', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Staff Microservice 6: Inter-club Communication -->
              <tr id="row-inter_club_communication" data-type="inter_club_communication" onclick="handleRowClick(event, 'inter_club_communication')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#ccfbf1; color:#0f766e;">
                      <i class="fa-solid fa-comments"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Inter-club Communication</span>
                      <span class="module-tag tag-cyan">Messaging</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Student affairs messaging channels, club communications, and organizational chat group activity.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-comment-dots" style="color:#0f766e;"></i>
                    <?= $stats['total_messages'] ?> Messages Logged
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="inter_club_communication" onclick="generateReportType('inter_club_communication', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Staff Microservice 7: Volunteer Hour Tracking -->
              <tr id="row-volunteer_hour_tracking" data-type="volunteer_hour_tracking" onclick="handleRowClick(event, 'volunteer_hour_tracking')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#ede9fe; color:#6d28d9;">
                      <i class="fa-solid fa-hand-holding-heart"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Volunteer Hour Tracking</span>
                      <span class="module-tag tag-purple">Community</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Community service initiatives, validated student volunteer hours, and outreach documentation.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-clock" style="color:#6d28d9;"></i>
                    <?= number_format($stats['total_vol_hours'], 1) ?> Hours (<?= $stats['total_volunteers'] ?> Records)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="volunteer_hour_tracking" onclick="generateReportType('volunteer_hour_tracking', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Staff Microservice 8: Club Directory -->
              <tr id="row-club_directory" data-type="club_directory" onclick="handleRowClick(event, 'club_directory')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#f1f5f9; color:#334155;">
                      <i class="fa-solid fa-building-columns"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Club Directory</span>
                      <span class="module-tag tag-slate">Accreditation</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Accredited student organizations, faculty advisers, recognized charters, and compliance records.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-users-viewfinder" style="color:#334155;"></i>
                    <?= $stats['total_clubs'] ?> Active Organizations
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="club_directory" onclick="generateReportType('club_directory', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Staff Master Summary: Comprehensive Co-Curricular Summary -->
              <tr id="row-comprehensive" data-type="comprehensive" onclick="handleRowClick(event, 'comprehensive')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#0f172a; color:#ffffff;">
                      <i class="fa-solid fa-file-waveform"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Comprehensive Co-Curricular Summary</span>
                      <span class="module-tag tag-slate">Executive</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Office of Student Affairs consolidated report on co-curricular compliance, activities, volunteerism, and achievements.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-layer-group" style="color:#0f172a;"></i>
                    OSA Master Executive Summary
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="comprehensive" onclick="generateReportType('comprehensive', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <?php elseif ($sess_role === 'ssc'): ?>
              <!-- SSC Microservice 1: Club Registration Portal -->
              <tr id="row-club_registration_portal" data-type="club_registration_portal" onclick="handleRowClick(event, 'club_registration_portal')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#dbeafe; color:#1d4ed8;">
                      <i class="fa-solid fa-id-card"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Club Registration Portal</span>
                      <span class="module-tag tag-blue">Registration</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Council-endorsed club applications, student credentials, and multi-tier registration tracking.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-address-card" style="color:#1d4ed8;"></i>
                    <?= $stats['total_applications'] ?> Applications Logged
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="club_registration_portal" onclick="generateReportType('club_registration_portal', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- SSC Microservice 2: Student Club Memberships -->
              <tr id="row-student_club_memberships" data-type="student_club_memberships" onclick="handleRowClick(event, 'student_club_memberships')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#dcfce7; color:#16a34a;">
                      <i class="fa-solid fa-users"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Student Club Memberships</span>
                      <span class="module-tag tag-green">Constituency</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Council-recognized club rosters, active constituents, student leader listings, and memberships.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-users" style="color:#16a34a;"></i>
                    <?= $stats['total_members'] ?> Constituents
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="student_club_memberships" onclick="generateReportType('student_club_memberships', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- SSC Microservice 3: Club Officer Elections -->
              <tr id="row-club_officer_elections" data-type="club_officer_elections" onclick="handleRowClick(event, 'club_officer_elections')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#e0f2fe; color:#0284c7;">
                      <i class="fa-solid fa-check-to-slot"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Club Officer Elections</span>
                      <span class="module-tag tag-blue">Governance</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Democratic student elections, voter turnout statistics, candidate tallies, and electoral certifications.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-envelope-open-text" style="color:#0284c7;"></i>
                    <?= $stats['total_elections'] ?> Elections (<?= $stats['total_votes'] ?> Votes)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="club_officer_elections" onclick="generateReportType('club_officer_elections', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- SSC Microservice 4: Event & Activity Logs -->
              <tr id="row-event_activity_logs" data-type="event_activity_logs" onclick="handleRowClick(event, 'event_activity_logs')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#e0e7ff; color:#4338ca;">
                      <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Event &amp; Activity Logs</span>
                      <span class="module-tag tag-indigo">Proposals</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Supreme Student Council endorsed proposals, club event clearances, and calendar approvals.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-calendar-days" style="color:#4338ca;"></i>
                    <?= $stats['total_events'] ?> Proposals (<?= $stats['approved_events'] ?> Cleared)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="event_activity_logs" onclick="generateReportType('event_activity_logs', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- SSC Microservice 5: Attendance Tracker -->
              <tr id="row-attendance_tracker" data-type="attendance_tracker" onclick="handleRowClick(event, 'attendance_tracker')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#fef3c7; color:#b45309;">
                      <i class="fa-solid fa-clipboard-user"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Attendance Tracker</span>
                      <span class="module-tag tag-amber">Turnout</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Council and campus event turnout, QR validation check-in timestamps, and participation ratios.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-clipboard-check" style="color:#b45309;"></i>
                    <?= $stats['total_attendance'] ?> Turnout Records
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="attendance_tracker" onclick="generateReportType('attendance_tracker', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- SSC Microservice 6: Club Achievement Records -->
              <tr id="row-club_achievement_records" data-type="club_achievement_records" onclick="handleRowClick(event, 'club_achievement_records')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#ffe4e6; color:#be123c;">
                      <i class="fa-solid fa-trophy"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Club Achievement Records</span>
                      <span class="module-tag tag-rose">Honors</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Inter-collegiate competition medals, academic accolades, organization awards, and verified achievements.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-award" style="color:#be123c;"></i>
                    <?= $stats['total_achievements'] ?> Verified Honors
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="club_achievement_records" onclick="generateReportType('club_achievement_records', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- SSC Microservice 7: Budget Requests -->
              <tr id="row-budget_requests" data-type="budget_requests" onclick="handleRowClick(event, 'budget_requests')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#d1fae5; color:#059669;">
                      <i class="fa-solid fa-file-invoice-dollar"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Budget Requests</span>
                      <span class="module-tag tag-green">Finances</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Student council fund requests, allocations, endorsed club budget submissions, and release records.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-money-bill-transfer" style="color:#059669;"></i>
                    <?= $stats['total_reqs'] ?> Requisitions (₱<?= number_format($stats['total_disbursed'], 2) ?>)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="budget_requests" onclick="generateReportType('budget_requests', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- SSC Microservice 8: Inter-club Communication -->
              <tr id="row-inter_club_communication" data-type="inter_club_communication" onclick="handleRowClick(event, 'inter_club_communication')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#ccfbf1; color:#0f766e;">
                      <i class="fa-solid fa-comments"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Inter-club Communication</span>
                      <span class="module-tag tag-cyan">Messaging</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Council inter-club broadcast notices, governance discussion threads, and representative communication.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-comment-dots" style="color:#0f766e;"></i>
                    <?= $stats['total_messages'] ?> Messages Logged
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="inter_club_communication" onclick="generateReportType('inter_club_communication', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- SSC Microservice 9: Volunteer Hour Tracking -->
              <tr id="row-volunteer_hour_tracking" data-type="volunteer_hour_tracking" onclick="handleRowClick(event, 'volunteer_hour_tracking')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#ede9fe; color:#6d28d9;">
                      <i class="fa-solid fa-hand-holding-heart"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Volunteer Hour Tracking</span>
                      <span class="module-tag tag-purple">Civic Service</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Council civic engagements, validated volunteer service hours, outreach drives, and student volunteers.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-clock-rotate-left" style="color:#6d28d9;"></i>
                    <?= number_format($stats['total_vol_hours'], 1) ?> Hours (<?= $stats['total_volunteers'] ?> Logs)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="volunteer_hour_tracking" onclick="generateReportType('volunteer_hour_tracking', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- SSC Microservice 10: Club Directory -->
              <tr id="row-club_directory" data-type="club_directory" onclick="handleRowClick(event, 'club_directory')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#dcfce7; color:#15803d;">
                      <i class="fa-solid fa-building-columns"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Club Directory</span>
                      <span class="module-tag tag-green">Clubs</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Chartered student organizations, recognized club officers, faculty advisers, and council representatives.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-users-viewfinder" style="color:#15803d;"></i>
                    <?= $stats['total_clubs'] ?> Active Clubs
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="club_directory" onclick="generateReportType('club_directory', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- SSC Master Summary: Comprehensive Governance Summary -->
              <tr id="row-comprehensive" data-type="comprehensive" onclick="handleRowClick(event, 'comprehensive')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#0f172a; color:#ffffff;">
                      <i class="fa-solid fa-file-waveform"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Comprehensive Governance Summary</span>
                      <span class="module-tag tag-slate">Executive</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Council executive performance synthesis combining events, club oversight, finances, elections, and civic impact.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-layer-group" style="color:#0f172a;"></i>
                    SSC Consolidated Scorecard
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="comprehensive" onclick="generateReportType('comprehensive', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <?php else: ?>
              <!-- Adviser Microservice 1: Club Registration Portal -->
              <tr id="row-club_registration_portal" data-type="club_registration_portal" onclick="handleRowClick(event, 'club_registration_portal')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#dbeafe; color:#1d4ed8;">
                      <i class="fa-solid fa-id-card"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Club Registration Portal</span>
                      <span class="module-tag tag-blue">Registration</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Membership applications submitted to your student club, applicant details, and endorsement statuses.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-address-card" style="color:#1d4ed8;"></i>
                    <?= $stats['total_applications'] ?> Applications Logged
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="club_registration_portal" onclick="generateReportType('club_registration_portal', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Adviser Microservice 2: Student Club Memberships -->
              <tr id="row-student_club_memberships" data-type="student_club_memberships" onclick="handleRowClick(event, 'student_club_memberships')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#dcfce7; color:#16a34a;">
                      <i class="fa-solid fa-users-gear"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Student Club Memberships</span>
                      <span class="module-tag tag-green">Roster</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Active club roster, elected student officers, academic programs, and verified memberships.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-users" style="color:#16a34a;"></i>
                    <?= $stats['total_members'] ?> Active Members
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="student_club_memberships" onclick="generateReportType('student_club_memberships', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Adviser Microservice 3: Event & Activity Logs -->
              <tr id="row-event_activity_logs" data-type="event_activity_logs" onclick="handleRowClick(event, 'event_activity_logs')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#e0e7ff; color:#4338ca;">
                      <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Event &amp; Activity Logs</span>
                      <span class="module-tag tag-blue">Events</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Event participation, scheduled dates, approved venues, and event attendance turnout records.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-calendar-days" style="color:#4338ca;"></i>
                    <?= $stats['total_events'] ?> Events (<?= $stats['approved_events'] ?> Approved)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="event_activity_logs" onclick="generateReportType('event_activity_logs', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Adviser Microservice 4: Attendance Tracker -->
              <tr id="row-attendance_tracker" data-type="attendance_tracker" onclick="handleRowClick(event, 'attendance_tracker')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#fef3c7; color:#d97706;">
                      <i class="fa-solid fa-clipboard-user"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Attendance Tracker</span>
                      <span class="module-tag tag-amber">Attendance</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    QR code check-in timestamps, verification methods, attendee student numbers, and event participation.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-clipboard-check" style="color:#d97706;"></i>
                    <?= $stats['total_attendance'] ?> Verified Logs
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="attendance_tracker" onclick="generateReportType('attendance_tracker', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Adviser Microservice 5: Club Achievement Records -->
              <tr id="row-club_achievement_records" data-type="club_achievement_records" onclick="handleRowClick(event, 'club_achievement_records')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#ffe4e6; color:#be123c;">
                      <i class="fa-solid fa-trophy"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Club Achievement Records</span>
                      <span class="module-tag tag-rose">Honors</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Student and organization competition achievements, verification statuses, awards tally, and honors registry.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-award" style="color:#be123c;"></i>
                    <?= $stats['total_achievements'] ?> Verified Honors
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="club_achievement_records" onclick="generateReportType('club_achievement_records', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Adviser Microservice 6: Budget Requests -->
              <tr id="row-budget_requests" data-type="budget_requests" onclick="handleRowClick(event, 'budget_requests')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#d1fae5; color:#059669;">
                      <i class="fa-solid fa-file-invoice-dollar"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Budget Requests</span>
                      <span class="module-tag tag-green">Finances</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Organization budget proposals, expense requisitions, endorsement history, and disbursement releases.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-money-bill-transfer" style="color:#059669;"></i>
                    <?= $stats['total_reqs'] ?> Requisitions (₱<?= number_format($stats['total_disbursed'], 2) ?>)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="budget_requests" onclick="generateReportType('budget_requests', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Adviser Microservice 7: Inter-club Communication -->
              <tr id="row-inter_club_communication" data-type="inter_club_communication" onclick="handleRowClick(event, 'inter_club_communication')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#ccfbf1; color:#0f766e;">
                      <i class="fa-solid fa-comments"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Inter-club Communication</span>
                      <span class="module-tag tag-cyan">Messaging</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Organization messenger channels, member announcements, executive threads, and collaborative messaging.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-comment-dots" style="color:#0f766e;"></i>
                    <?= $stats['total_messages'] ?> Messages Logged
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="inter_club_communication" onclick="generateReportType('inter_club_communication', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Adviser Microservice 8: Volunteer Hour Tracking -->
              <tr id="row-volunteer_hour_tracking" data-type="volunteer_hour_tracking" onclick="handleRowClick(event, 'volunteer_hour_tracking')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#ede9fe; color:#6d28d9;">
                      <i class="fa-solid fa-hand-holding-heart"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Volunteer Hour Tracking</span>
                      <span class="module-tag tag-purple">Community</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Organization civic volunteer hours, outreach drives, community services, and volunteer rosters.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-clock-rotate-left" style="color:#6d28d9;"></i>
                    <?= number_format($stats['total_vol_hours'], 1) ?> Hours (<?= $stats['total_volunteers'] ?> Logs)
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="volunteer_hour_tracking" onclick="generateReportType('volunteer_hour_tracking', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>

              <!-- Adviser Master Summary: Comprehensive Organization Summary -->
              <tr id="row-comprehensive" data-type="comprehensive" onclick="handleRowClick(event, 'comprehensive')">
                <td>
                  <div class="module-cell">
                    <div class="module-icon" style="background:#0f172a; color:#ffffff;">
                      <i class="fa-solid fa-file-waveform"></i>
                    </div>
                    <div class="module-name-wrap">
                      <span class="module-title">Comprehensive Organization Summary</span>
                      <span class="module-tag tag-slate">Executive</span>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="desc-text">
                    Consolidated executive operational summary combining activities, members, and organizational records.
                  </div>
                </td>
                <td>
                  <span class="stat-badge-cell">
                    <i class="fa-solid fa-layer-group" style="color:#0f172a;"></i>
                    Consolidated Summary
                  </span>
                </td>
                <td>
                  <div class="fmt-pills-row">
                    <span class="fmt-pill">Table</span>
                    <span class="fmt-pill">CSV</span>
                    <span class="fmt-pill">PDF</span>
                  </div>
                </td>
                <td style="text-align: right;">
                  <button type="button" class="btn-gen-row" data-type="comprehensive" onclick="generateReportType('comprehensive', this, event)">
                    <i class="fa-solid fa-play"></i> Generate
                  </button>
                </td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div><!-- /content-body -->
  </div><!-- /content -->

  <!-- 3. Report Output Modal Dialog Popup -->
  <div class="report-modal-overlay" id="reportModalOverlay" onclick="handleReportBackdropClick(event)">
    <div class="report-modal-dialog" id="reportOutput" onclick="event.stopPropagation()">
      <div class="report-output-header">
        <div>
          <h3 id="reportOutputTitle"><i class="fa-solid fa-file-lines"></i> <span>Report</span></h3>
          <div style="font-size:0.75rem; color:#94a3b8; margin-top:3px;" id="reportOutputSubtitle">
            <?php
              if ($sess_role === 'super_admin') {
                echo 'Root Institutional Authority &amp; System Administration';
              } elseif ($sess_role === 'admin') {
                echo 'Institutional System Administration &amp; Audit Registry';
              } elseif ($sess_role === 'staff') {
                echo 'Office of Student Affairs (OSA) &amp; Student Operations Registry';
              } elseif ($sess_role === 'ssc') {
                echo 'Supreme Student Council (SSC) &amp; Student Governance Records';
              } else {
                echo 'Organization: ' . htmlspecialchars($adviser_club_name ?? 'Bestlink College') . ' (' . htmlspecialchars($adviser_club_code ?? 'BCP') . ')';
              }
            ?>
          </div>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <button type="button" class="btn-export-csv" onclick="exportReportToCSV()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
          <button type="button" class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / PDF</button>
          <button type="button" class="btn-close-report" onclick="closeReportOutput()" title="Close report popup"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>
      </div>
      <div class="report-body" id="reportBody">
        <!-- Loaded dynamically -->
      </div>
    </div>
  </div>

  <div class="footer">eLearning Commons &copy; 2026</div>
</div><!-- /main -->

<?php require_once __DIR__ . '/../shared/qr_modal.php'; ?>

<div id="toast" class="toast-notification" style="display:none;"></div>
<script src="../js/dashboard.js"></script>
<script>
let selectedReportType = '';
let currentReportData = null;

const reportNames = {
  // 10 Co-Curricular Microservices
  'club_registration_portal': 'Club Registration Portal Executive Summary',
  'student_club_memberships': 'Student Club Memberships Executive Summary',
  'club_officer_elections': 'Club Officer Elections & Governance Summary',
  'event_activity_logs': 'Event & Activity Logs Executive Summary',
  'attendance_tracker': 'Attendance Tracker & Turnout Audit Summary',
  'club_achievement_records': 'Club Achievement Records & Honors Summary',
  'budget_requests': 'Budget Requests & Financial Allocations Summary',
  'inter_club_communication': 'Inter-club Communication & Collaboration Summary',
  'volunteer_hour_tracking': 'Volunteer Hour Tracking & Civic Engagement Summary',
  'club_directory': 'Club Directory & Accredited Organizations Summary',

  // Master Comprehensive Summary
  'comprehensive': 'Comprehensive Co-Curricular Performance Summary',

  // Legacy aliases
  'system_usage': 'System Usage & Telemetry Summary',
  'organization_summary': 'Club Directory & Accredited Organizations Summary',
  'event_proposals_summary': 'Event & Activity Logs Executive Summary',
  'financial_summary': 'Event & Activity Logs Executive Summary',
  'attendance_summary': 'Attendance Tracker & Turnout Audit Summary',
  'election_summary': 'Club Officer Elections & Governance Summary',
  'achievement_summary': 'Club Achievement Records & Honors Summary',
  'budget_summary': 'Budget Requests & Financial Allocations Summary',
  'volunteer_summary': 'Volunteer Hour Tracking & Civic Engagement Summary',
  'certificate_summary': 'Certificate Governance & Policy Summary',
  'user_access_summary': 'User Directory & RBAC Security Summary',
  'activity_events': 'Event & Activity Logs Executive Summary',
  'membership_engagement': 'Student Club Memberships Executive Summary',
  'attendance_analytics': 'Attendance Tracker & Turnout Audit Summary',
  'achievements_honors': 'Club Achievement Records & Honors Summary',
  'budget_financial': 'Budget Requests & Financial Allocations Summary',
  'club_registration': 'Club Registration Portal Executive Summary',
  'communication_summary': 'Inter-club Communication & Collaboration Summary'
};

function handleRowClick(event, type) {
  // If user clicked inside the button itself, let the button onclick handler handle it
  if (event.target.closest('.btn-gen-row')) return;
  const btn = document.querySelector(`.btn-gen-row[data-type="${type}"]`);
  generateReportType(type, btn, event);
}

async function generateReportType(type, btnEl, event) {
  if (event) event.stopPropagation();
  selectedReportType = type;

  // Highlight active row
  document.querySelectorAll('#reportCatalogTable tbody tr').forEach(r => r.classList.remove('active-row'));
  const activeRow = document.getElementById('row-' + type);
  if (activeRow) activeRow.classList.add('active-row');

  // Update button state
  document.querySelectorAll('.btn-gen-row').forEach(b => {
    b.classList.remove('is-active', 'is-loading');
    b.innerHTML = '<i class="fa-solid fa-play"></i> Generate';
  });

  if (btnEl) {
    btnEl.classList.add('is-loading');
    btnEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating...';
  }

  const overlay = document.getElementById('reportModalOverlay');
  const body = document.getElementById('reportBody');

  // Pop up the modal dialog immediately
  overlay.classList.add('active');
  document.body.style.overflow = 'hidden';
  document.getElementById('reportOutputTitle').querySelector('span').textContent = reportNames[selectedReportType] || 'Executive Report';

  body.innerHTML = `
    <div style="text-align:center; padding:55px 20px; color:#64748b;">
      <i class="fa-solid fa-spinner fa-spin fa-2x" style="color:#2563eb; margin-bottom:14px;"></i>
      <div style="font-weight:700; font-size:1rem; color:#0f172a; margin-bottom:4px;">Compiling executive report summary...</div>
      <div style="font-size:0.82rem; color:#64748b;">Fetching verified data from the database and building dynamic summaries.</div>
    </div>`;

  try {
    const fd = new FormData();
    fd.append('action', 'get_report');
    fd.append('report_type', selectedReportType);
    const res = await fetch('../shared/report_actions.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (btnEl) {
      btnEl.classList.remove('is-loading');
      btnEl.classList.add('is-active');
      btnEl.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> Regenerate';
    }

    if (!data.success) {
      body.innerHTML = `<div style="padding:28px; color:#dc2626; font-weight:600; text-align:center;"><i class="fa-solid fa-triangle-exclamation fa-2x" style="margin-bottom:10px; display:block;"></i>${data.message}</div>`;
      return;
    }

    currentReportData = data;
    renderReport(data);

  } catch (err) {
    if (btnEl) {
      btnEl.classList.remove('is-loading');
      btnEl.innerHTML = '<i class="fa-solid fa-play"></i> Generate';
    }
    body.innerHTML = `<div style="padding:28px; color:#dc2626; font-weight:600; text-align:center;"><i class="fa-solid fa-triangle-exclamation fa-2x" style="margin-bottom:10px; display:block;"></i>An error occurred while generating the report. Please try again.</div>`;
  }
}

function closeReportOutput() {
  const overlay = document.getElementById('reportModalOverlay');
  if (overlay) overlay.classList.remove('active');
  document.body.style.overflow = '';
  document.querySelectorAll('#reportCatalogTable tbody tr').forEach(r => r.classList.remove('active-row'));
  document.querySelectorAll('.btn-gen-row').forEach(b => {
    b.classList.remove('is-active', 'is-loading');
    b.innerHTML = '<i class="fa-solid fa-play"></i> Generate';
  });
}

function handleReportBackdropClick(event) {
  if (event.target === document.getElementById('reportModalOverlay')) {
    closeReportOutput();
  }
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    const overlay = document.getElementById('reportModalOverlay');
    if (overlay && overlay.classList.contains('active')) {
      closeReportOutput();
    }
  }
});

function renderReport(rpt) {
  const body = document.getElementById('reportBody');
  let html = '';

  const clubName = rpt.club ? rpt.club.name : 'Bestlink College of the Philippines';
  const clubCode = rpt.club ? rpt.club.code : 'BCP';
  const refCode = 'BCP-RPT-' + Math.floor(100000 + Math.random() * 900000);

  // 1. Formal Institutional Letterhead
  html += `
    <div class="doc-letterhead">
      <img src="../images/BCP_LOGO.png" alt="Bestlink College of the Philippines" class="doc-letterhead-logo" />
      <div class="doc-letterhead-text">
        <div class="doc-letterhead-title">Bestlink College of the Philippines</div>
        <div class="doc-letterhead-sub">Office of Student Affairs &amp; Services &bull; Supreme Student Council Institutional Records</div>
        <div style="font-size:0.72rem; color:#64748b; margin-top:2px;">Co-Curricular Performance, Student Governance &amp; Accreditation Registry</div>
      </div>
    </div>`;

  // 2. Document Meta Matrix
  html += `
    <div class="doc-meta-matrix">
      <div class="doc-meta-item">
        <span class="doc-meta-lbl">Report Title</span>
        <span class="doc-meta-val">${rpt.title || 'Executive Performance Summary'}</span>
      </div>
      <div class="doc-meta-item">
        <span class="doc-meta-lbl">Target Scope / Organization</span>
        <span class="doc-meta-val">${clubName} (${clubCode})</span>
      </div>
      <div class="doc-meta-item">
        <span class="doc-meta-lbl">Reporting Cycle</span>
        <span class="doc-meta-val">Academic Year 2025–2026 (Semester II)</span>
      </div>
      <div class="doc-meta-item">
        <span class="doc-meta-lbl">Date &amp; Time Generated</span>
        <span class="doc-meta-val">${rpt.date_generated || new Date().toLocaleString()}</span>
      </div>
      <div class="doc-meta-item">
        <span class="doc-meta-lbl">Document Reference No.</span>
        <span class="doc-meta-val" style="font-family:monospace; color:#2563eb;">${refCode}</span>
      </div>
      <div class="doc-meta-item">
        <span class="doc-meta-lbl">Classification Status</span>
        <span class="doc-meta-val" style="color:#059669;"><i class="fa-solid fa-shield-check"></i> Official Institutional Record</span>
      </div>
    </div>`;

  // 3. Section I: Executive Briefing & Key Insights
  if (rpt.executive_summary) {
    const es = rpt.executive_summary;
    html += `
      <div class="doc-section-header">
        <i class="fa-solid fa-file-signature"></i>
        <span>Section I: Executive Summary &amp; Operational Digest</span>
      </div>
      <div class="exec-digest-banner">
        <div class="exec-digest-icon">
          <i class="fa-solid fa-wand-magic-sparkles"></i>
        </div>
        <div class="exec-digest-content">
          <div class="exec-digest-title">
            <span>${es.headline || 'Executive Summary & Operational Digest'}</span>
          </div>
          <div class="exec-digest-text">${es.narrative || ''}</div>
          ${es.key_findings && es.key_findings.length ? `
            <div class="exec-findings-list">
              ${es.key_findings.map(f => `
                <div class="exec-finding-item">
                  <i class="fa-solid fa-circle-check"></i>
                  <span>${f}</span>
                </div>
              `).join('')}
            </div>
          ` : ''}
        </div>
      </div>`;
  }

  // 4. Section II: Key Performance Indicators & Benchmark Scorecard
  if (rpt.summary_cards && rpt.summary_cards.length) {
    html += `
      <div class="doc-section-header">
        <i class="fa-solid fa-gauge-high"></i>
        <span>Section II: Institutional KPI Scorecard &amp; Metrics</span>
      </div>
      <div class="report-summary-grid">`;
    rpt.summary_cards.forEach(c => {
      html += `
        <div class="report-summary-box">
          <div class="box-icon" style="background:${c.color}15; color:${c.color};">
            <i class="fa-solid fa-${c.icon}"></i>
          </div>
          <div>
            <div class="box-val">${c.value}</div>
            <div class="box-lbl">${c.label}</div>
          </div>
        </div>`;
    });
    html += '</div>';
  }

  // 5. Section III: Categorical Performance & Distribution Breakdown
  if (rpt.breakdown && rpt.breakdown.rows && rpt.breakdown.rows.length) {
    const bd = rpt.breakdown;
    html += `
      <div class="doc-section-header">
        <i class="fa-solid fa-chart-pie"></i>
        <span>Section III: Categorical Performance &amp; Distribution Breakdown</span>
      </div>
      <div class="report-breakdown-card">
        <div class="breakdown-card-title">
          <i class="fa-solid fa-layer-group" style="color:#2563eb;"></i>
          <span>${bd.title || 'Category & Stage Distribution'}</span>
        </div>
        <div class="table-wrap" style="margin-bottom:0; border:none; box-shadow:none;">
          <table class="report-breakdown-table">
            <thead>
              <tr>
                ${bd.headers.map(h => `<th>${h}</th>`).join('')}
              </tr>
            </thead>
            <tbody>
              ${bd.rows.map(r => {
                const statusCls = (r.status || '').toLowerCase().replace(/[^a-z0-9]/g, '-');
                return `
                  <tr>
                    <td style="font-weight:700; color:#0f172a;">${r.category}</td>
                    <td><span class="breakdown-pill-count">${r.count}</span></td>
                    <td><span class="breakdown-pill-share">${r.share}</span></td>
                    <td><span class="breakdown-status-badge status-${statusCls}">${r.status}</span></td>
                  </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>
      </div>`;
  }

  // 6. Section IV: Master Detailed Summary Ledger
  if (rpt.columns && rpt.columns.length) {
    html += `
      <div class="doc-section-header">
        <i class="fa-solid fa-table-list"></i>
        <span>Section IV: Consolidated Activity &amp; Records Ledger</span>
      </div>
      <div class="report-section-heading">
        <div class="report-section-title">
          <i class="fa-solid fa-list-check" style="color:#2563eb;"></i>
          <span>Master Ledger Summary Records</span>
          <span style="font-size:0.75rem; font-weight:600; color:#64748b; margin-left:6px;">(${rpt.data ? rpt.data.length : 0} Total Entries)</span>
        </div>
        <div>
          <input type="text" class="report-search-input" placeholder="Search report rows..." onkeyup="filterRenderedTable(this.value)" />
        </div>
      </div>
      <div class="table-wrap report-table-wrap">
        <table class="report-table" id="renderedReportTable">
          <thead>
            <tr>
              ${rpt.columns.map(col => `<th>${col}</th>`).join('')}
            </tr>
          </thead>
          <tbody>`;

    if (!rpt.data || !rpt.data.length) {
      html += `<tr><td colspan="${rpt.columns.length}" style="text-align:center; padding:24px; color:#94a3b8;">No records found for this reporting period.</td></tr>`;
    } else {
      rpt.data.forEach(row => {
        html += '<tr>';
        row.forEach(cell => {
          html += `<td>${cell !== null && cell !== undefined ? cell : ''}</td>`;
        });
        html += '</tr>';
      });
    }

    html += `
          </tbody>
        </table>
      </div>`;
  }

  // 7. Section V: Institutional Certification & Sign-Offs
  html += `
    <div class="doc-section-header">
      <i class="fa-solid fa-stamp"></i>
      <span>Section V: Institutional Verification &amp; Certification Sign-Offs</span>
    </div>
    <div class="doc-signatures-grid">
      <div class="doc-sign-box">
        <div class="doc-sign-role-title">Prepared By</div>
        <div class="doc-sign-line">Student Governance Officer</div>
        <div class="doc-sign-desig">Records &amp; Documentation Committee</div>
      </div>
      <div class="doc-sign-box">
        <div class="doc-sign-role-title">Reviewed &amp; Endorsed By</div>
        <div class="doc-sign-line">Faculty Club Adviser / SSC Lead</div>
        <div class="doc-sign-desig">Co-Curricular Affairs Supervision</div>
      </div>
      <div class="doc-sign-box">
        <div class="doc-sign-role-title">Approved &amp; Certified By</div>
        <div class="doc-sign-line">Director / Dean of Student Affairs</div>
        <div class="doc-sign-desig">Office of Student Affairs &amp; Services (OSAS)</div>
      </div>
    </div>`;

  // 8. Official Document Seal & Footer
  html += `
    <div class="report-official-footer" style="margin-top:20px;">
      <div>
        <strong>Bestlink College of the Philippines</strong> &bull; Official Institutional Performance Document
      </div>
      <div>
        Generated on: <strong>${rpt.date_generated || new Date().toLocaleString()}</strong> &bull; Ref: <code>${refCode}</code>
      </div>
    </div>`;

  body.innerHTML = html;
}

function filterRenderedTable(query) {
  const q = (query || '').toLowerCase().trim();
  const table = document.getElementById('renderedReportTable');
  if (!table) return;
  const rows = table.querySelectorAll('tbody tr');
  rows.forEach(r => {
    const text = r.textContent.toLowerCase();
    r.style.display = text.includes(q) ? '' : 'none';
  });
}

function exportReportToCSV() {
  if (!currentReportData || !currentReportData.columns || !currentReportData.data) {
    alert('Please generate a report first before exporting.');
    return;
  }

  const cols = currentReportData.columns;
  const rows = currentReportData.data;

  let csv = [];
  // Header row
  csv.push(cols.map(c => `"${c.replace(/"/g, '""')}"`).join(','));

  // Data rows
  rows.forEach(r => {
    csv.push(r.map(val => `"${String(val || '').replace(/"/g, '""')}"`).join(','));
  });

  const csvContent = 'data:text/csv;charset=utf-8,\uFEFF' + encodeURIComponent(csv.join('\r\n'));
  const link = document.createElement('a');
  link.setAttribute('href', csvContent);
  const fname = (currentReportData.title || 'Report').replace(/[^a-zA-Z0-9]/g, '_') + '_' + Date.now() + '.csv';
  link.setAttribute('download', fname);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}
</script>
</body>
</html>
