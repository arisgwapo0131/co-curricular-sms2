<?php
// ============================================================
//  BUDGET.PHP — Budget & Financial Management Module
//  3-Stage Approval Pipeline: Adviser -> SSC -> Admin
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/security.php';
require_once __DIR__ . '/../shared/notification_actions.php';
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_pic     = $_SESSION['profile_pic'] ?? null;
$user_id      = (int)($_SESSION['user_id'] ?? 0);

$is_admin_view = in_array($sess_role, ['admin', 'super_admin'], true);
$is_ssc_view   = in_array($sess_role, ['ssc', 'staff'], true);

if ($sess_role === 'student') {
    header('Location: dashboard.php');
    exit;
}
require_any_permission(['budget.disburse.admin', 'budget.review.ssc', 'budget.endorse.adviser', 'budget.create.own', 'budget.view.all']);
if (!headers_sent()) { header('Content-Type: text/html; charset=UTF-8'); }

// Fetch user clubs for new request dropdown
$user_clubs = get_user_assigned_clubs($conn, $user_id, $sess_role);
$adviser_club_ids = array_map('intval', array_column($user_clubs, 'id'));

// Fetch budget requests based on role
$where = 'WHERE br.deleted_at IS NULL';
if ($sess_role === 'club_adviser') {
    if (!empty($adviser_club_ids)) {
        $where .= " AND br.club_id IN (" . implode(',', $adviser_club_ids) . ")";
    } else {
        $where .= " AND 1=0"; // Strict isolation
    }
}
// admin, super_admin, ssc, staff see all active (non-deleted) requests

$budget_requests = $conn->query(
    "SELECT br.id, br.club_id, br.title, br.description, br.line_items, br.amount, br.recommended_amount, br.final_approved_amount, br.disbursement_reference, br.disbursed_at, br.disbursed_by, br.status, br.notes, br.created_at, br.updated_at,
            c.name AS club_name, c.code AS club_code,
            u.first_name, u.last_name, u.email,
            du.first_name AS disburser_first, du.last_name AS disburser_last
     FROM budget_requests br
     JOIN clubs c ON c.id = br.club_id
     JOIN users u ON u.id = br.requested_by
     LEFT JOIN users du ON du.id = br.disbursed_by
     $where ORDER BY br.created_at DESC"
)->fetch_all(MYSQLI_ASSOC);

// Metrics calculation
if ($is_admin_view) {
    // Budget & Financial Administration (Admin / Super Admin Metric Cards)
    // Dynamic configured budget from system_settings or default institutional cap
    $cfg_res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'configured_budget' LIMIT 1");
    $configured_budget = ($cfg_res && $cfg_res->num_rows > 0) ? (float)$cfg_res->fetch_row()[0] : 1000000.00;
    if ($configured_budget <= 0) $configured_budget = 1000000.00;

    $pending_admin_count    = 0;
    $pending_admin_amount   = 0.0;
    $approved_budget_total  = 0.0;
    $disbursed_amount_total = 0.0;
    $rejected_amount_total  = 0.0;
    $total_released         = 0.0;

    foreach ($budget_requests as $req) {
        $st    = $req['status'];
        $amt   = (float)$req['amount'];
        $rec   = !empty($req['recommended_amount']) ? (float)$req['recommended_amount'] : $amt;
        $final = !empty($req['final_approved_amount']) ? (float)$req['final_approved_amount'] : $rec;

        if ($st === 'Pending Admin' || $st === 'Approved') {
            $pending_admin_count++;
            $pending_admin_amount += $rec;
            if ($st === 'Approved') {
                $approved_budget_total += $final;
            }
        } elseif ($st === 'Disbursed') {
            $approved_budget_total  += $final;
            $disbursed_amount_total += $final;
            $total_released         += $final;
        } elseif ($st === 'Rejected') {
            $rejected_amount_total += $amt;
        }
    }

    $remaining_available_funds = max(0.0, $configured_budget - $total_released);

} elseif ($is_ssc_view) {
    $pending_ssc_review     = 0;   // Requests waiting in SSC queue.
    $total_under_review     = 0.0; // Total peso value in current SSC queue.
    $recommended_this_month = 0.0; // Amount SSC forwarded / endorsed.
    $rejected_requests      = 0;   // Requests rejected by SSC.
    $pending_admin          = 0;   // Requests awaiting final administration.
    $total_disbursed        = 0.0; // Amount already released.

    $cur_m = date('m');
    $cur_y = date('Y');

    foreach ($budget_requests as $req) {
        $st   = $req['status'];
        $amt  = (float)$req['amount'];
        $rec  = !empty($req['recommended_amount']) ? (float)$req['recommended_amount'] : $amt;
        $disb = !empty($req['final_approved_amount']) ? (float)$req['final_approved_amount'] : (!empty($req['recommended_amount']) ? (float)$req['recommended_amount'] : $amt);

        if ($st === 'Pending SSC') {
            $pending_ssc_review++;
            $total_under_review += $amt;
        } elseif ($st === 'Pending Admin' || $st === 'Approved') {
            $pending_admin++;
            $req_m = date('m', strtotime($req['updated_at'] ?: $req['created_at']));
            $req_y = date('Y', strtotime($req['updated_at'] ?: $req['created_at']));
            if ($req_m === $cur_m && $req_y === $cur_y) {
                $recommended_this_month += $rec;
            }
        } elseif ($st === 'Disbursed') {
            $total_disbursed += $disb;
            $req_m = date('m', strtotime($req['updated_at'] ?: $req['created_at']));
            $req_y = date('Y', strtotime($req['updated_at'] ?: $req['created_at']));
            if ($req_m === $cur_m && $req_y === $cur_y) {
                $recommended_this_month += $rec;
            }
        } elseif ($st === 'Rejected') {
            $rejected_requests++;
        }
    }
} else {
    $total_requested = 0;
    $pending_count   = 0;
    $disbursed_total = 0;
    $rejected_count  = 0;
    foreach ($budget_requests as $req) {
        $total_requested += (float)$req['amount'];
        if (in_array($req['status'], ['Pending Adviser', 'Pending SSC', 'Pending Admin', 'Approved', 'Returned'])) {
            $pending_count++;
        } elseif ($req['status'] === 'Disbursed') {
            $disbursed_total += (float)($req['final_approved_amount'] ?: $req['amount']);
        } elseif ($req['status'] === 'Rejected') {
            $rejected_count++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Budget &amp; Finance Management — BCP Co-Curricular Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="../vendor/fontawesome/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <meta name="csrf-token" content="<?= csrf_token() ?>"/>
  <script src="../js/page-loader.js"></script>
  <style>
    /* Clean, Modern Professional Budget UI */
    body, input, button, select, textarea {
      font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    .budget-section-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 22px 24px;
      margin-bottom: 24px;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 1px 2px rgba(15, 23, 42, 0.02);
    }
    
    /* 3-Stage Pipeline Stepper */
    .wf-stepper-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
      padding-bottom: 12px;
      border-bottom: 1px solid #f1f5f9;
    }
    .wf-stepper-title {
      font-size: 0.82rem;
      font-weight: 700;
      color: #1a3a8c;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .wf-pipeline {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 16px;
      position: relative;
    }
    .wf-card {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 16px 18px;
      display: flex;
      align-items: flex-start;
      gap: 14px;
      transition: all 0.2s ease;
      position: relative;
    }
    .wf-card.active {
      background: #eff6ff;
      border-color: #93c5fd;
      box-shadow: 0 4px 14px rgba(37, 99, 235, 0.08);
    }
    .wf-card.done {
      background: #f0fdf4;
      border-color: #bbf7d0;
    }
    .wf-badge {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.9rem;
      flex-shrink: 0;
      background: #e2e8f0;
      color: #64748b;
      font-weight: 700;
    }
    .wf-card.active .wf-badge {
      background: #1a3a8c;
      color: #ffffff;
    }
    .wf-card.done .wf-badge {
      background: #16a34a;
      color: #ffffff;
    }
    .wf-info {
      flex: 1;
      min-width: 0;
    }
    .wf-stage-tag {
      font-size: 0.68rem;
      font-weight: 700;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-bottom: 3px;
    }
    .wf-card.active .wf-stage-tag {
      color: #2563eb;
    }
    .wf-card.done .wf-stage-tag {
      color: #16a34a;
    }
    .wf-name {
      font-size: 0.88rem;
      font-weight: 700;
      color: #0f172a;
      line-height: 1.35;
      margin-bottom: 4px;
    }
    .wf-desc {
      font-size: 0.76rem;
      color: #64748b;
      line-height: 1.4;
    }

    /* KPI Metrics Grid */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px;
      margin-bottom: 0;
      width: 100%;
    }
    .kpi-grid.kpi-grid-ssc {
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 12px;
      width: 100%;
    }
    @media (max-width: 1320px) {
      .kpi-grid.kpi-grid-ssc {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
      }
    }
    @media (max-width: 860px) {
      .kpi-grid.kpi-grid-ssc {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
      }
    }
    @media (max-width: 560px) {
      .kpi-grid.kpi-grid-ssc {
        grid-template-columns: 1fr;
        gap: 10px;
      }
    }
    .kpi-box {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 14px 16px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 1px 2px rgba(15, 23, 42, 0.02);
      transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
      position: relative;
      min-width: 0;
      box-sizing: border-box;
      overflow: hidden;
    }
    .kpi-box:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 24px -4px rgba(15, 23, 42, 0.08), 0 6px 12px -4px rgba(15, 23, 42, 0.03);
      border-color: #cbd5e1;
    }
    .kpi-top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 8px;
      margin-bottom: 8px;
    }
    .kpi-label {
      font-size: 0.68rem;
      font-weight: 700;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      line-height: 1.25;
      min-width: 0;
      word-break: break-word;
    }
    .kpi-icon-wrap {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.85rem;
      flex-shrink: 0;
    }
    .kpi-icon-blue    { background: #eff6ff; color: #2563eb; }
    .kpi-icon-amber   { background: #fffbeb; color: #d97706; }
    .kpi-icon-green   { background: #f0fdf4; color: #16a34a; }
    .kpi-icon-red     { background: #fef2f2; color: #dc2626; }
    .kpi-icon-indigo  { background: #e0e7ff; color: #4338ca; }
    .kpi-icon-purple  { background: #f3e8ff; color: #7e22ce; }
    
    .kpi-num {
      font-size: clamp(1.15rem, 1.25vw, 1.42rem);
      font-weight: 700;
      color: #0f172a;
      line-height: 1.2;
      letter-spacing: -0.02em;
      font-feature-settings: "tnum";
      font-variant-numeric: tabular-nums;
      display: flex;
      align-items: baseline;
      flex-wrap: wrap;
      gap: 2px;
      margin: 4px 0 2px;
      word-break: break-word;
      min-width: 0;
    }
    .kpi-currency {
      font-size: 0.95rem;
      font-weight: 600;
      color: #475569;
      margin-right: 1px;
    }
    .kpi-subtext {
      font-size: 0.72rem;
      color: #64748b;
      font-weight: 500;
      margin-top: 6px;
      display: flex;
      align-items: center;
      gap: 5px;
      line-height: 1.3;
      white-space: normal;
      word-break: break-word;
    }
    .kpi-subtext i {
      font-size: 0.72rem;
      opacity: 0.75;
      flex-shrink: 0;
    }

    /* Ledger Table & Toolbar */
    .ledger-container {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 2px 8px rgba(15, 23, 42, 0.02);
    }
    .ledger-header {
      padding: 20px 24px;
      background: #ffffff;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 16px;
    }
    .ledger-title-row {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .ledger-title-icon {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      background: #eff6ff;
      color: #1a3a8c;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.05rem;
      flex-shrink: 0;
    }
    .ledger-title h3 {
      margin: 0;
      font-size: 1.1rem;
      font-weight: 700;
      color: #0f172a;
      letter-spacing: -0.01em;
      line-height: 1.3;
    }
    .ledger-title p {
      margin: 2px 0 0;
      font-size: 0.82rem;
      color: #64748b;
      line-height: 1.4;
    }
    .ledger-actions-bar {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }
    .search-input-wrap {
      position: relative;
      min-width: 250px;
    }
    .search-input-wrap i {
      position: absolute;
      left: 13px;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
      font-size: 0.85rem;
      pointer-events: none;
    }
    .search-input-wrap input {
      width: 100%;
      height: 38px;
      padding: 0 14px 0 36px;
      font-size: 0.84rem;
      font-weight: 500;
      color: #1e293b;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      background: #ffffff;
      transition: all 0.2s ease;
    }
    .search-input-wrap input::placeholder {
      color: #94a3b8;
      font-weight: 400;
    }
    .search-input-wrap input:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    
    .filter-select-wrap {
      position: relative;
      display: inline-block;
    }
    .filter-select {
      height: 38px;
      padding: 0 34px 0 13px;
      font-size: 0.84rem;
      font-weight: 600;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      background: #ffffff;
      color: #334155;
      cursor: pointer;
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      transition: all 0.2s ease;
    }
    .filter-select-wrap::after {
      content: "\f078";
      font-family: "Font Awesome 6 Free";
      font-weight: 900;
      font-size: 0.65rem;
      color: #64748b;
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      pointer-events: none;
    }
    .filter-select:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    .btn-create-req {
      height: 38px;
      background: #1a3a8c;
      color: #ffffff;
      font-weight: 600;
      font-size: 0.84rem;
      padding: 0 16px;
      border-radius: 8px;
      border: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
      transition: all 0.18s ease;
      white-space: nowrap;
    }
    .btn-create-req:hover {
      background: #2563eb;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
    }

    /* Desktop Table & Rows Layout */
    @media (min-width: 769px) {
      .table-wrap.budget-table {
        overflow-x: auto;
        width: 100%;
      }
      .table-wrap.budget-table table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
      }
      .table-wrap.budget-table thead tr {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
      }
      .table-wrap.budget-table th {
        padding: 10px 10px;
        font-size: 0.68rem;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        white-space: nowrap;
      }
      .table-wrap.budget-table th:first-child,
      .table-wrap.budget-table td:first-child {
        padding-left: 16px;
      }
      .table-wrap.budget-table th:last-child,
      .table-wrap.budget-table td:last-child {
        padding-right: 16px;
      }
      .table-wrap.budget-table tbody tr {
        border-bottom: 1px solid #f1f5f9;
        transition: background 0.15s ease;
      }
      .table-wrap.budget-table tbody tr:last-child {
        border-bottom: none;
      }
      .table-wrap.budget-table tbody tr:hover {
        background: #f8fafc;
      }
      .table-wrap.budget-table td {
        padding: 9px 10px;
        vertical-align: middle;
        color: #334155;
        font-size: 0.81rem;
      }
      .table-wrap.budget-table::-webkit-scrollbar {
        height: 6px;
      }
      .table-wrap.budget-table::-webkit-scrollbar-track {
        background: #f8fafc;
      }
      .table-wrap.budget-table::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
      }
    }

    /* Uniform Ledger Pagination Layout */
    .ledger-container .pagination-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      padding: 14px 20px;
      margin-top: 0;
      border-top: 1px solid #e2e8f0;
      background: #ffffff;
      border-bottom-left-radius: 12px;
      border-bottom-right-radius: 12px;
    }
    .ledger-container .pagination-info {
      display: none !important;
    }
    .ledger-container .pagination-controls {
      display: inline-flex;
      align-items: center;
      justify-content: flex-end;
      gap: 14px;
      flex-wrap: wrap;
    }

    /* ── Budget Ledger Table: Single-line clean layout ── */
    #budgetLedgerTable {
      width: 100% !important;
      min-width: 0 !important;
      max-width: 100% !important;
      border-collapse: collapse;
    }
    #budgetLedgerTable th,
    #budgetLedgerTable td {
      padding: 9px 12px !important;
      font-size: 0.78rem !important;
      vertical-align: middle !important;
      white-space: nowrap !important;
      word-break: normal !important;
    }
    #budgetLedgerTable th {
      padding: 9px 10px !important;
      font-size: 0.70rem !important;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.03em;
      color: #475569;
      white-space: nowrap;
      background: #f8fafc;
      border-bottom: 2px solid #e2e8f0;
    }
    #budgetLedgerTable .admin-act-btn-group {
      display: inline-flex !important;
      align-items: center !important;
      justify-content: flex-end !important;
      gap: 3px !important;
      flex-wrap: nowrap !important;
      width: 100%;
    }
    #budgetLedgerTable .admin-tbl-act-btn {
      width: 26px;
      height: 26px;
      padding: 0;
      border-radius: 5px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 0.72rem;
      border: none;
      cursor: pointer;
      transition: all 0.15s ease;
      flex-shrink: 0;
      text-decoration: none;
      line-height: 1;
    }
    #budgetLedgerTable .admin-tbl-act-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
      filter: brightness(1.1);
    }
    #budgetLedgerTable .admin-tbl-act-btn:disabled,
    #budgetLedgerTable .admin-tbl-act-btn.btn-disabled {
      background: #e2e8f0 !important;
      color: #94a3b8 !important;
      border: 1px solid #cbd5e1 !important;
      cursor: not-allowed !important;
      opacity: 0.65 !important;
      transform: none !important;
      box-shadow: none !important;
    }
    #budgetLedgerTable .admin-tbl-act-btn:disabled:hover,
    #budgetLedgerTable .admin-tbl-act-btn.btn-disabled:hover {
      transform: none !important;
      box-shadow: none !important;
      filter: none !important;
    }

    .empty-state-cell {
      text-align: center;
      padding: 56px 24px !important;
    }
    .empty-state-icon {
      width: 56px;
      height: 56px;
      border-radius: 14px;
      background: #f1f5f9;
      color: #94a3b8;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 14px;
      font-size: 1.4rem;
    }
    .empty-state-title {
      margin: 0 0 5px;
      color: #334155;
      font-size: 0.95rem;
      font-weight: 600;
    }
    .empty-state-desc {
      margin: 0;
      font-size: 0.82rem;
      color: #64748b;
      max-width: 360px;
      margin: 0 auto;
      line-height: 1.4;
    }

    .org-code-chip {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: #e0e7ff;
      color: #1e40af;
      font-size: 0.68rem;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 6px;
      letter-spacing: 0.02em;
      margin-bottom: 3px;
    }
    .org-name-text {
      font-weight: 600;
      color: #0f172a;
      font-size: 0.88rem;
      line-height: 1.3;
    }
    .requester-meta {
      font-size: 0.75rem;
      color: #64748b;
      margin-top: 2px;
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .req-title-text {
      font-weight: 600;
      color: #0f172a;
      font-size: 0.9rem;
      line-height: 1.35;
      margin-bottom: 2px;
    }
    .req-desc-excerpt {
      font-size: 0.78rem;
      color: #64748b;
      max-width: 260px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      line-height: 1.3;
    }
    .req-amount-val {
      font-size: 0.98rem;
      font-weight: 700;
      color: #0f172a;
      white-space: nowrap;
      font-feature-settings: "tnum";
      font-variant-numeric: tabular-nums;
    }

    /* Status Badges */
    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 0.72rem;
      font-weight: 600;
      white-space: nowrap;
    }
    .status-pill-adviser { background: #fffbeb; color: #b45309; border: 1px solid #fef3c7; }
    .status-pill-ssc     { background: #f5f3ff; color: #6d28d9; border: 1px solid #ede9fe; }
    .status-pill-admin   { background: #fdf2f8; color: #be185d; border: 1px solid #fce7f3; }
    .status-pill-disbursed { background: #f0fdf4; color: #15803d; border: 1px solid #dcfce7; }
    .status-pill-rejected  { background: #fef2f2; color: #b91c1c; border: 1px solid #fee2e2; }

    /* Action Buttons */
    .action-btn-group {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 6px;
    }
    .act-btn {
      height: 32px;
      padding: 0 11px;
      font-size: 0.75rem;
      font-weight: 600;
      border-radius: 7px;
      border: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      transition: all 0.15s ease;
      white-space: nowrap;
    }
    .act-btn-approve {
      background: #16a34a;
      color: #ffffff;
    }
    .act-btn-approve:hover {
      background: #15803d;
    }
    .act-btn-review {
      background: #1a3a8c;
      color: #ffffff;
    }
    .act-btn-review:hover {
      background: #2563eb;
    }
    .act-btn-edit {
      background: #d97706;
      color: #ffffff;
    }
    .act-btn-edit:hover {
      background: #b45309;
    }
    .act-btn-reject {
      background: #dc2626;
      color: #ffffff;
    }
    .act-btn-reject:hover {
      background: #b91c1c;
    }
    .act-btn-return {
      background: #d97706;
      color: #ffffff;
    }
    .act-btn-return:hover {
      background: #b45309;
    }
    .act-btn-override {
      background: #7c3aed;
      color: #ffffff;
    }
    .act-btn-override:hover {
      background: #6d28d9;
    }
    .act-btn-view {
      background: #f1f5f9;
      color: #475569;
      border: 1px solid #cbd5e1;
    }
    .act-btn-view:hover {
      background: #e2e8f0;
      color: #0f172a;
    }
    .act-btn:disabled,
    .act-btn.btn-disabled {
      background: #e2e8f0 !important;
      color: #94a3b8 !important;
      border: 1px solid #cbd5e1 !important;
      cursor: not-allowed !important;
      box-shadow: none !important;
      transform: none !important;
      pointer-events: auto !important;
      opacity: 0.65;
    }

    /* Stage Tags & Badges */
    .stage-tag {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 9px;
      border-radius: 6px;
      font-size: 0.72rem;
      font-weight: 700;
      white-space: nowrap;
      letter-spacing: 0.02em;
    }
    .stage-tag-ssc { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .stage-tag-admin { background: #fdf4ff; color: #a21caf; border: 1px solid #f5d0fe; }
    .stage-tag-disbursed { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
    .stage-tag-rejected { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

    .adviser-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      background: #ecfdf5;
      color: #047857;
      border: 1px solid #a7f3d0;
      border-radius: 6px;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 3px 8px;
      white-space: nowrap;
    }

    .days-pending-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: 0.78rem;
      font-weight: 600;
      color: #475569;
      background: #f1f5f9;
      padding: 3px 8px;
      border-radius: 6px;
    }
    .days-pending-badge.days-urgent {
      background: #fef2f2;
      color: #b91c1c;
      border: 1px solid #fecaca;
    }

    /* Itemization Breakdown Card & Table */
    .itemization-card {
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      overflow: hidden;
      margin: 16px 0;
      background: #ffffff;
    }
    .itemization-header {
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
      padding: 11px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .itemization-title {
      font-size: 0.82rem;
      font-weight: 700;
      color: #1e293b;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .itemization-count-chip {
      font-size: 0.7rem;
      font-weight: 700;
      background: #e2e8f0;
      color: #475569;
      padding: 2px 8px;
      border-radius: 12px;
    }
    .itemization-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.84rem;
    }
    .itemization-table th {
      background: #f1f5f9;
      padding: 9px 14px;
      font-size: 0.72rem;
      font-weight: 700;
      color: #475569;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      border-bottom: 1px solid #cbd5e1;
    }
    .itemization-table td {
      padding: 10px 14px;
      border-bottom: 1px solid #f1f5f9;
      color: #334155;
      vertical-align: middle;
    }
    .itemization-table tbody tr:hover {
      background: #f8fafc;
    }
    .itemization-foot-row td {
      background: #f8fafc;
      border-top: 2px solid #cbd5e1;
      border-bottom: none;
      padding: 12px 14px;
    }

    /* Modal Layouts */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(4px);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      padding: 20px;
    }
    .modal-dialog {
      background: #ffffff;
      border-radius: 16px;
      width: 100%;
      max-width: 580px;
      overflow: hidden;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
      animation: modalFadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes modalFadeIn {
      from { opacity: 0; transform: scale(0.96) translateY(8px); }
      to { opacity: 1; transform: scale(1) translateY(0); }
    }
    .modal-header-solid {
      background: #1a3a8c;
      color: #ffffff;
      padding: 18px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .modal-header-solid h3 {
      margin: 0;
      font-size: 1.05rem;
      font-weight: 700;
      color: #ffffff;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .modal-close-btn {
      background: none;
      border: none;
      color: #ffffff;
      opacity: 0.85;
      font-size: 1.15rem;
      cursor: pointer;
      transition: opacity 0.15s;
    }
    .modal-close-btn:hover {
      opacity: 1;
    }
    .modal-body-pad {
      padding: 24px;
      max-height: calc(85vh - 120px);
      overflow-y: auto;
    }
    .modal-footer-pad {
      padding: 14px 24px;
      background: #f8fafc;
      border-top: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
    }

    .form-group-custom {
      margin-bottom: 16px;
    }
    .form-group-custom label {
      display: block;
      font-size: 0.78rem;
      font-weight: 600;
      color: #334155;
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    .form-control-custom {
      width: 100%;
      padding: 9px 13px;
      font-size: 0.88rem;
      font-weight: 500;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      background: #ffffff;
      color: #0f172a;
      transition: all 0.2s;
    }
    .form-control-custom:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    /* Currency Input Group */
    .currency-input-wrap {
      position: relative;
      display: flex;
      align-items: center;
    }
    .currency-prefix {
      position: absolute;
      left: 14px;
      font-weight: 700;
      color: #64748b;
      font-size: 0.95rem;
      pointer-events: none;
    }
    .currency-input-wrap input {
      padding-left: 32px !important;
      font-weight: 600;
      font-size: 0.95rem;
    }

    @media (max-width: 992px) {
      .kpi-grid:not(.kpi-grid-ssc) { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .wf-pipeline { grid-template-columns: 1fr; }
    }
    @media (max-width: 576px) {
      .kpi-grid:not(.kpi-grid-ssc) { grid-template-columns: 1fr; }
    }

    /* Mobile Responsive Card Transformation */
    @media (max-width: 768px) {
      .table-wrap.budget-table {
        overflow: visible;
      }
      .table-wrap.budget-table table,
      .table-wrap.budget-table thead,
      .table-wrap.budget-table tbody,
      .table-wrap.budget-table th,
      .table-wrap.budget-table td,
      .table-wrap.budget-table tr {
        display: block;
      }
      .table-wrap.budget-table thead tr {
        position: absolute;
        top: -9999px;
        left: -9999px;
      }
      .table-wrap.budget-table tbody tr.budget-data-row {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        margin-bottom: 16px;
        padding: 16px 18px;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
      }
      .table-wrap.budget-table tbody tr.budget-data-row td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 9px 0;
        border: none;
        border-bottom: 1px solid #f1f5f9;
        text-align: right;
        font-size: 0.84rem;
        min-height: 38px;
      }
      .table-wrap.budget-table tbody tr.budget-data-row td:last-child {
        border-bottom: none;
        padding-top: 14px;
        justify-content: flex-end;
      }
      .table-wrap.budget-table tbody tr.budget-data-row td::before {
        content: attr(data-label);
        font-weight: 700;
        color: #64748b;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        text-align: left;
        margin-right: 14px;
        flex-shrink: 0;
      }
      .table-wrap.budget-table tbody tr.budget-data-row td[data-label="Action"] .action-btn-group {
        width: 100%;
        justify-content: flex-end;
      }
      .table-wrap.budget-table tbody tr.budget-data-row td[data-label="Organization"],
      .table-wrap.budget-table tbody tr.budget-data-row td[data-label="Request Title"] {
        flex-direction: column;
        align-items: flex-end;
        text-align: right;
      }
    }
  </style>
</head>
<body>

  <?php $APP_ROOT = '../'; $ACTIVE_NAV = 'budget'; require_once __DIR__ . '/../shared/sidebar.php'; ?>

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
        <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button"><i class="fa-solid fa-qrcode"></i></button>
        <?php endif; ?>
        <a href="../dashboard/account.php" class="avatar" id="avatarBtn" title="Account Settings">
          <?php if (!empty($sess_pic) && file_exists(__DIR__ . '/../uploads/avatars/' . $sess_pic)): ?>
            <img src="../uploads/avatars/<?= htmlspecialchars($sess_pic) ?>" alt="Profile"/>
          <?php else: ?>
            <?= $sess_initial ?>
          <?php endif; ?>
        </a>
      </div>
    </div>

    <div class="content">
      <div class="page-title-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; margin-bottom: 20px;">
        <div style="display:flex; align-items:center; gap:12px;">
          <div style="width:42px; height:42px; border-radius:11px; background:#eff6ff; color:#1d4ed8; display:flex; align-items:center; justify-content:center; font-size:1.15rem; border:1px solid #bfdbfe;">
            <i class="fa-solid fa-file-invoice-dollar"></i>
          </div>
          <div>
            <h2 class="page-title" style="margin:0; font-size:1.25rem; font-weight:800; color:#0f172a;">
              <?= $is_admin_view ? 'Budget &amp; Financial Administration' : ($is_ssc_view ? 'SSC Budget Review &amp; Endorsement' : 'Budget &amp; Financial Management') ?>
            </h2>
            <p style="margin:2px 0 0; font-size:0.78rem; color:#64748b; font-weight:500;">
              Organizational Requisitions &bull; 3-Stage Approval Pipeline &bull; Itemized Disbursement Ledger (<?= count($budget_requests) ?> Active Requisitions)
            </p>
          </div>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
          <?php if (in_array($sess_role, ['club_adviser', 'admin', 'super_admin', 'staff'], true) || can('budget.create.own')): ?>
          <button class="btn-create-req" onclick="openNewRequestModal()" style="height:40px; padding:0 18px; border-radius:10px; font-weight:700;">
            <i class="fa-solid fa-plus"></i> New Budget Requisition
          </button>
          <?php endif; ?>
        </div>
      </div>

      <div class="content-body">

        <!-- KPI Metrics Summary Grid -->
        <?php if ($is_admin_view): ?>
        <div class="kpi-grid kpi-grid-ssc">
          <!-- Card 1: Pending Admin Disbursement -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Pending Admin Disbursement</span>
              <div class="kpi-icon-wrap kpi-icon-amber"><i class="fa-solid fa-hourglass-half"></i></div>
            </div>
            <div class="kpi-num"><?= $pending_admin_count ?> <span style="font-size:0.75rem; font-weight:600; color:#64748b;">(₱<?= number_format($pending_admin_amount, 2) ?>)</span></div>
            <div class="kpi-subtext"><i class="fa-solid fa-clock"></i> Requests ready for final release.</div>
          </div>

          <!-- Card 2: Approved Budget -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Approved Budget</span>
              <div class="kpi-icon-wrap kpi-icon-blue"><i class="fa-solid fa-stamp"></i></div>
            </div>
            <div class="kpi-num"><span class="kpi-currency">₱</span><?= number_format($approved_budget_total, 2) ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-circle-check"></i> Final approved value.</div>
          </div>

          <!-- Card 3: Disbursed -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Disbursed</span>
              <div class="kpi-icon-wrap kpi-icon-green"><i class="fa-solid fa-money-bill-transfer"></i></div>
            </div>
            <div class="kpi-num"><span class="kpi-currency">₱</span><?= number_format($disbursed_amount_total, 2) ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-hand-holding-dollar"></i> Released amount.</div>
          </div>

          <!-- Card 4: Rejected -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Rejected</span>
              <div class="kpi-icon-wrap kpi-icon-red"><i class="fa-solid fa-circle-xmark"></i></div>
            </div>
            <div class="kpi-num"><span class="kpi-currency">₱</span><?= number_format($rejected_amount_total, 2) ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-ban"></i> Rejected amount.</div>
          </div>

          <!-- Card 5: Total Released -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Total Released</span>
              <div class="kpi-icon-wrap kpi-icon-indigo"><i class="fa-solid fa-vault"></i></div>
            </div>
            <div class="kpi-num"><span class="kpi-currency">₱</span><?= number_format($total_released, 2) ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-receipt"></i> Cumulative disbursement.</div>
          </div>

          <!-- Card 6: Remaining / Available Funds -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Remaining / Available Funds</span>
              <div class="kpi-icon-wrap kpi-icon-purple"><i class="fa-solid fa-piggy-bank"></i></div>
            </div>
            <div class="kpi-num"><span class="kpi-currency">₱</span><?= number_format($remaining_available_funds, 2) ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-coins"></i> Available configured budget.</div>
          </div>
        </div>
        <?php elseif ($is_ssc_view): ?>
        <div class="kpi-grid kpi-grid-ssc">
          <!-- Card 1: Pending SSC Review -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Pending SSC Review</span>
              <div class="kpi-icon-wrap kpi-icon-amber"><i class="fa-solid fa-hourglass-half"></i></div>
            </div>
            <div class="kpi-num"><?= $pending_ssc_review ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-clock"></i> Requests waiting in SSC queue.</div>
          </div>

          <!-- Card 2: Total Under SSC Review -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Total Under SSC Review</span>
              <div class="kpi-icon-wrap kpi-icon-blue"><i class="fa-solid fa-coins"></i></div>
            </div>
            <div class="kpi-num"><span class="kpi-currency">₱</span><?= number_format($total_under_review, 2) ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-calculator"></i> Total peso value in current SSC queue.</div>
          </div>

          <!-- Card 3: Recommended This Month -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Recommended This Month</span>
              <div class="kpi-icon-wrap kpi-icon-indigo"><i class="fa-solid fa-thumbs-up"></i></div>
            </div>
            <div class="kpi-num"><span class="kpi-currency">₱</span><?= number_format($recommended_this_month, 2) ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-forward-step"></i> Amount SSC forwarded / endorsed.</div>
          </div>

          <!-- Card 4: Rejected Requests -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Rejected Requests</span>
              <div class="kpi-icon-wrap kpi-icon-red"><i class="fa-solid fa-circle-xmark"></i></div>
            </div>
            <div class="kpi-num"><?= $rejected_requests ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-triangle-exclamation"></i> Requests rejected by SSC.</div>
          </div>

          <!-- Card 5: Pending Admin -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Pending Admin</span>
              <div class="kpi-icon-wrap kpi-icon-purple"><i class="fa-solid fa-building-columns"></i></div>
            </div>
            <div class="kpi-num"><?= $pending_admin ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-user-shield"></i> Requests awaiting final administration.</div>
          </div>

          <!-- Card 6: Total Disbursed -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Total Disbursed</span>
              <div class="kpi-icon-wrap kpi-icon-green"><i class="fa-solid fa-circle-check"></i></div>
            </div>
            <div class="kpi-num"><span class="kpi-currency">₱</span><?= number_format($total_disbursed, 2) ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-hand-holding-dollar"></i> Amount already released.</div>
          </div>
        </div>
        <?php else: ?>
        <div class="kpi-grid">
          <!-- Total Requested -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Total Requested</span>
              <div class="kpi-icon-wrap kpi-icon-blue"><i class="fa-solid fa-coins"></i></div>
            </div>
            <div class="kpi-num"><span class="kpi-currency">₱</span><?= number_format($total_requested, 2) ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-file-invoice"></i> All submitted requisitions</div>
          </div>

          <!-- Pending Approvals -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Pending Approvals</span>
              <div class="kpi-icon-wrap kpi-icon-amber"><i class="fa-solid fa-hourglass-half"></i></div>
            </div>
            <div class="kpi-num"><?= $pending_count ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-arrows-spin"></i> Awaiting pipeline review</div>
          </div>

          <!-- Total Disbursed -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Total Disbursed</span>
              <div class="kpi-icon-wrap kpi-icon-green"><i class="fa-solid fa-circle-check"></i></div>
            </div>
            <div class="kpi-num"><span class="kpi-currency">₱</span><?= number_format($disbursed_total, 2) ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-hand-holding-dollar"></i> Successfully released funds</div>
          </div>

          <!-- Rejected Requests -->
          <div class="kpi-box">
            <div class="kpi-top">
              <span class="kpi-label">Rejected Requests</span>
              <div class="kpi-icon-wrap kpi-icon-red"><i class="fa-solid fa-circle-xmark"></i></div>
            </div>
            <div class="kpi-num"><?= $rejected_count ?></div>
            <div class="kpi-subtext"><i class="fa-solid fa-circle-info"></i> Returned with feedback</div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Ledger & Requisitions Management Container -->
        <div class="ledger-container">
          <div class="ledger-header">
            <div class="ledger-title">
              <div class="ledger-title-row">
                <div class="ledger-title-icon">
                  <i class="fa-solid <?= $is_ssc_view ? 'fa-list-check' : 'fa-receipt' ?>"></i>
                </div>
                <div>
                  <h3><?= $is_ssc_view ? 'SSC Budget Review Queue' : 'Requisitions &amp; Disbursals Ledger' ?></h3>
                  <p><?= $is_ssc_view ? 'Vetting, recommendation revision, and administrative endorsement queue for student organization requisitions.' : 'Real-time audit log of all organizational budget allocations and disbursements.' ?></p>
                </div>
              </div>
            </div>
            
            <div class="ledger-actions-bar">
              <!-- Live Search Filter -->
              <div class="search-input-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="budgetSearchInput" placeholder="Search title, club, requester..." onkeyup="filterLedgerTable()"/>
              </div>

              <!-- Status Filter Dropdown -->
              <div class="filter-select-wrap">
                <select class="filter-select" id="budgetStatusFilter" onchange="filterLedgerTable()">
                  <option value="ALL">All Statuses (<?= count($budget_requests) ?>)</option>
                  <option value="Pending Adviser">Stage 0: Pending Adviser Endorsement</option>
                  <option value="Pending SSC">Stage 1: Pending SSC Review</option>
                  <option value="Pending Admin">Stage 2: Pending Admin Clearance</option>
                  <option value="Approved">Approved (Awaiting Release)</option>
                  <option value="Disbursed">Stage 3: Disbursed (Released)</option>
                  <option value="Rejected">Rejected</option>
                  <option value="Returned">Returned for Revision</option>
                </select>
              </div>

              <?php if (in_array($sess_role, ['club_adviser', 'admin', 'super_admin', 'staff'], true) || can('budget.create.own')): ?>
              <button class="btn-create-req" onclick="openNewRequestModal()">
                <i class="fa-solid fa-plus"></i> New Requisition
              </button>
              <?php endif; ?>
            </div>
          </div>

          <!-- Responsive Table -->
          <div class="table-wrap budget-table">
            <table id="budgetLedgerTable" class="table-wide" data-page-size="10">
              <thead>
                <?php if ($is_admin_view): ?>
                <tr>
                  <th style="width:17%;">Request</th>
                  <th style="width:19%;">Organization</th>
                  <th style="width:13%;">Amount</th>
                  <th style="width:14%;">SSC Recommendation</th>
                  <th style="width:12%;">Admin Status</th>
                  <th style="width:11%;">Submitted</th>
                  <th style="width:14%; text-align:right;">Action</th>
                </tr>
                <?php elseif ($is_ssc_view): ?>
                <tr>
                  <th style="white-space:nowrap; width:100px;">Request No.</th>
                  <th style="min-width:130px; max-width:180px;">Organization</th>
                  <th style="min-width:150px; max-width:200px;">Request Title</th>
                  <th style="white-space:nowrap; width:105px;">Requested Amount</th>
                  <th style="white-space:nowrap; width:105px;">Submitted By</th>
                  <th style="white-space:nowrap; width:95px;">Submitted Date</th>
                  <th style="white-space:nowrap; width:85px;">Adviser Status</th>
                  <th style="white-space:nowrap; width:105px;">SSC Status</th>
                  <th style="white-space:nowrap; width:120px;">Current Stage</th>
                  <th style="white-space:nowrap; width:85px;">Days Pending</th>
                  <th style="white-space:nowrap; width:85px; text-align:right;">Action</th>
                </tr>
                <?php else: ?>
                <tr>
                  <th style="width:65px; white-space:nowrap;">ID</th>
                  <th style="width:200px; white-space:nowrap;">Organization &amp; Requester</th>
                  <th style="width:220px; white-space:nowrap;">Requisition Details</th>
                  <th style="width:110px; white-space:nowrap;">Requested</th>
                  <th style="width:140px; white-space:nowrap;">Stage / Status</th>
                  <th style="width:180px; white-space:nowrap;">Review Notes</th>
                  <th style="width:190px; white-space:nowrap; text-align:right;">Actions</th>
                </tr>
                <?php endif; ?>
              </thead>
              <tbody id="budgetTableBody">
                <?php if (empty($budget_requests)): ?>
                  <tr id="emptyRow">
                    <td colspan="<?= $is_ssc_view ? 11 : 7 ?>" class="empty-state-cell" style="text-align:center; padding:48px 16px;">
                      <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; width:100%; margin:0 auto;">
                        <div class="empty-state-icon" style="margin:0 auto 12px; display:inline-flex; align-items:center; justify-content:center;">
                          <i class="fa-solid fa-folder-open"></i>
                        </div>
                        <h4 class="empty-state-title" style="margin:0 0 6px 0; text-align:center; font-weight:700; width:100%;">No Budget Requisitions Found</h4>
                        <p class="empty-state-desc" style="margin:0 auto; text-align:center; max-width:380px; width:100%;">There are currently no budget proposals filed under this category.</p>
                      </div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($budget_requests as $req): ?>
                    <?php
                      $status = $req['status'];
                      $pill_class = match($status) {
                          'Pending Adviser' => 'status-pill-adviser',
                          'Pending SSC'     => 'status-pill-ssc',
                          'Pending Admin'   => 'status-pill-admin',
                          'Approved'        => 'status-pill-admin',
                          'Disbursed'       => 'status-pill-disbursed',
                          'Rejected'        => 'status-pill-rejected',
                          default           => 'status-pill-adviser',
                      };
                      $pill_icon = match($status) {
                          'Pending Adviser' => '<i class="fa-solid fa-clock"></i>',
                          'Pending SSC'     => '<i class="fa-solid fa-file-signature"></i>',
                          'Pending Admin'   => '<i class="fa-solid fa-user-shield"></i>',
                          'Approved'        => '<i class="fa-solid fa-stamp"></i>',
                          'Disbursed'       => '<i class="fa-solid fa-circle-check"></i>',
                          'Rejected'        => '<i class="fa-solid fa-circle-xmark"></i>',
                          default           => '<i class="fa-solid fa-clock"></i>',
                      };

                      $can_approve  = false;
                      $can_edit_ssc = false;
                      $can_reject   = false;

                      if ($sess_role === 'club_adviser') {
                          if ($status === 'Pending Adviser' || $status === 'Returned') {
                              $can_approve = true; // Endorse to SSC
                              $can_reject  = true;
                          }
                      } elseif ($is_ssc_view) {
                          if ($status === 'Pending SSC') {
                              $can_approve  = true;  // Forward / Endorse to Admin
                              $can_reject   = true;  // Reject
                              $can_edit_ssc = true;  // Audit recommended amount
                          }
                      } elseif ($is_admin_view) {
                          if ($status === 'Pending Admin' || $status === 'Approved') {
                              $can_approve = true;   // Disburse / Release funds
                              $can_reject  = true;   // Reject
                          }
                          if ($status === 'Pending SSC') {
                              $can_edit_ssc = true;  // Admin audit override
                              $can_reject   = true;
                          }
                      }

                      $safe_title = htmlspecialchars(addslashes($req['title']));
                      $safe_desc  = htmlspecialchars(addslashes($req['description'] ?? ''));
                      $safe_notes = htmlspecialchars(addslashes($req['notes'] ?? ''));
                      $date_str   = date('M d, Y', strtotime($req['created_at']));
                      $ref_no     = 'REQ-' . date('Y', strtotime($req['created_at'])) . '-' . str_pad($req['id'], 4, '0', STR_PAD_LEFT);
                      $days_pending = max(0, (int)floor((time() - strtotime($req['created_at'])) / 86400));
                      $days_pending_str = $days_pending === 0 ? 'Today' : ($days_pending === 1 ? '1 day' : $days_pending . ' days');

                      $ssc_status_lbl = match($status) {
                          'Pending Adviser' => 'Awaiting Adviser',
                          'Pending SSC'     => 'Pending Review',
                          'Pending Admin'   => 'Endorsed to Admin',
                          'Approved'        => 'Approved by Admin',
                          'Disbursed'       => 'Disbursed',
                          'Rejected'        => 'Rejected',
                          default           => $status,
                      };

                      $current_stage_lbl = match($status) {
                          'Pending Adviser' => 'Stage 0: Adviser Endorse',
                          'Pending SSC'     => 'Stage 1: SSC Review',
                          'Pending Admin'   => 'Stage 2: Admin Clearance',
                          'Approved'        => 'Stage 2: Approved',
                          'Disbursed'       => 'Stage 3: Released',
                          'Rejected'        => 'Closed: Rejected',
                          'Returned'        => 'Returned for Revision',
                          default           => $status,
                      };

                      $stage_tag_class = match($status) {
                          'Pending SSC'   => 'stage-tag-ssc',
                          'Pending Admin' => 'stage-tag-admin',
                          'Approved'      => 'stage-tag-admin',
                          'Disbursed'     => 'stage-tag-disbursed',
                          'Rejected'      => 'stage-tag-rejected',
                          default         => 'stage-tag-ssc',
                      };
                    ?>
                    <tr class="budget-data-row" 
                        data-status="<?= htmlspecialchars($status) ?>"
                        data-search="<?= htmlspecialchars(strtolower($ref_no . ' ' . $req['title'] . ' ' . $req['club_name'] . ' ' . $req['club_code'] . ' ' . $req['first_name'] . ' ' . $req['last_name'])) ?>">
                      
                      <?php if ($is_admin_view): ?>
                        <!-- 1. Request -->
                        <td data-label="Request">
                          <strong style="color:#1a3a8c; font-size:0.8rem; font-family:monospace;"><?= htmlspecialchars($ref_no) ?></strong>
                          <div style="font-weight:700; font-size:0.83rem; color:#0f172a; margin-top:2px; line-height:1.25;"><?= htmlspecialchars($req['title']) ?></div>
                          <div class="req-desc-excerpt" style="font-size:0.73rem; max-width:180px; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($req['description'] ?? '') ?>">
                            <?= htmlspecialchars($req['description'] ?: 'No description specified.') ?>
                          </div>
                        </td>

                        <!-- 2. Organization -->
                        <td data-label="Organization">
                          <div class="org-code-chip"><?= htmlspecialchars($req['club_code']) ?></div>
                          <div class="org-name-text" style="font-size:0.8rem; line-height:1.2; font-weight:600;"><?= htmlspecialchars($req['club_name']) ?></div>
                          <div style="font-size:0.73rem; color:#64748b; margin-top:2px;">
                            <i class="fa-solid fa-user" style="font-size:0.65rem;"></i> <?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?>
                          </div>
                        </td>

                        <!-- 3. Amount -->
                        <td data-label="Amount">
                          <div class="req-amount-val" style="font-size:0.88rem; white-space:nowrap;">₱<?= number_format((float)$req['amount'], 2) ?></div>
                          <?php if (!empty($req['final_approved_amount'])): ?>
                            <div style="font-size:0.72rem; color:#15803d; font-weight:700; margin-top:2px; white-space:nowrap;" title="Final Released Amount">
                              Final: ₱<?= number_format((float)$req['final_approved_amount'], 2) ?>
                            </div>
                          <?php endif; ?>
                        </td>

                        <!-- 4. SSC Recommendation -->
                        <td data-label="SSC Recommendation">
                          <?php if (!empty($req['recommended_amount'])): ?>
                            <div style="font-weight:700; color:#6d28d9; font-size:0.84rem; white-space:nowrap;">
                              ₱<?= number_format((float)$req['recommended_amount'], 2) ?>
                            </div>
                            <span style="font-size:0.68rem; background:#ede9fe; color:#6d28d9; padding:2px 6px; border-radius:4px; font-weight:700; display:inline-block; margin-top:2px; white-space:nowrap;">
                              <i class="fa-solid fa-check-double"></i> Vetted
                            </span>
                          <?php else: ?>
                            <span style="font-size:0.75rem; color:#94a3b8; font-style:italic;">Awaiting SSC</span>
                          <?php endif; ?>
                        </td>

                        <!-- 5. Admin Status -->
                        <td data-label="Admin Status">
                          <span class="status-pill <?= $pill_class ?>" style="font-size:0.7rem; padding:3px 8px; white-space:nowrap;">
                            <?= $pill_icon ?> <?= htmlspecialchars($status) ?>
                          </span>
                        </td>

                        <!-- 6. Submitted -->
                        <td data-label="Submitted">
                          <span style="font-size:0.78rem; color:#475569; font-weight:500; white-space:nowrap;"><?= $date_str ?></span>
                        </td>

                        <!-- 7. Action -->
                        <td data-label="Action" style="text-align:right;">
                          <div class="admin-act-btn-group">
                            <!-- 1. Disburse / Release -->
                            <?php if (in_array($status, ['Pending Admin', 'Approved'])): ?>
                              <button type="button" class="admin-tbl-act-btn" style="background:#16a34a; color:#fff;" 
                                      onclick="promptApprove(<?= $req['id'] ?>, '<?= $safe_title ?>', 'Pending Admin', <?= (float)$req['amount'] ?>, <?= (float)($req['recommended_amount'] ?? $req['amount']) ?>)" 
                                      title="Disburse and release funds" aria-label="Disburse">
                                <i class="fa-solid fa-check"></i>
                              </button>
                            <?php else: ?>
                              <button type="button" class="admin-tbl-act-btn btn-disabled" disabled 
                                      title="Disbursement unavailable (Status: <?= htmlspecialchars($status) ?>)" aria-label="Disburse Disabled">
                                <i class="fa-solid fa-check"></i>
                              </button>
                            <?php endif; ?>

                            <!-- 2. Return -->
                            <?php if (in_array($status, ['Pending Admin', 'Pending SSC', 'Approved'])): ?>
                              <button type="button" class="admin-tbl-act-btn" style="background:#f59e0b; color:#fff;" 
                                      onclick="openAdminReturnModal(<?= $req['id'] ?>, '<?= $safe_title ?>')" 
                                      title="Return requisition for revision" aria-label="Return">
                                <i class="fa-solid fa-rotate-left"></i>
                              </button>
                            <?php else: ?>
                              <button type="button" class="admin-tbl-act-btn btn-disabled" disabled 
                                      title="<?= $status === 'Returned' ? 'Already Returned for Revision' : 'Return unavailable (Status: ' . htmlspecialchars($status) . ')' ?>" aria-label="Return Disabled">
                                <i class="fa-solid fa-rotate-left"></i>
                              </button>
                            <?php endif; ?>

                            <!-- 3. Reject -->
                            <?php if (in_array($status, ['Pending Adviser', 'Pending SSC', 'Pending Admin', 'Approved'])): ?>
                              <button type="button" class="admin-tbl-act-btn" style="background:#dc2626; color:#fff;" 
                                      onclick="promptReject(<?= $req['id'] ?>, '<?= $safe_title ?>')" 
                                      title="Reject requisition" aria-label="Reject">
                                <i class="fa-solid fa-xmark"></i>
                              </button>
                            <?php else: ?>
                              <button type="button" class="admin-tbl-act-btn btn-disabled" disabled 
                                      title="<?= $status === 'Rejected' ? 'Already Rejected' : 'Rejection unavailable (Status: ' . htmlspecialchars($status) . ')' ?>" aria-label="Reject Disabled">
                                <i class="fa-solid fa-xmark"></i>
                              </button>
                            <?php endif; ?>

                            <!-- 4. Override -->
                            <button type="button" class="admin-tbl-act-btn" style="background:#7c3aed; color:#fff;" 
                                    onclick="openAdminBudgetOverrideModal(<?= htmlspecialchars(json_encode($req), ENT_QUOTES) ?>)" 
                                    title="Administrative Override (Requires Reason &amp; Re-Authentication)" aria-label="Override">
                              <i class="fa-solid fa-bolt"></i>
                            </button>

                            <!-- 5. Details -->
                            <button type="button" class="admin-tbl-act-btn" style="background:#f1f5f9; color:#334155; border:1px solid #cbd5e1;" 
                                    onclick="viewRequisitionDetails(<?= htmlspecialchars(json_encode($req), ENT_QUOTES) ?>)" 
                                    title="View Complete Disbursement Details" aria-label="Details">
                              <i class="fa-solid fa-eye"></i>
                            </button>
                          </div>
                        </td>

                      <?php elseif ($is_ssc_view): ?>
                        <!-- 1. Request No. -->
                        <td data-label="Request No.">
                          <strong style="color:#1a3a8c; font-size:0.78rem; font-family:monospace; white-space:nowrap;"><?= htmlspecialchars($ref_no) ?></strong>
                        </td>

                        <!-- 2. Organization -->
                        <td data-label="Organization">
                          <div class="org-code-chip"><?= htmlspecialchars($req['club_code']) ?></div>
                          <div class="org-name-text" style="font-size:0.8rem; line-height:1.2; font-weight:600;"><?= htmlspecialchars($req['club_name']) ?></div>
                        </td>

                        <!-- 3. Request Title -->
                        <td data-label="Request Title">
                          <div class="req-title-text" style="font-size:0.83rem; font-weight:700; line-height:1.25; margin-bottom:2px;"><?= htmlspecialchars($req['title']) ?></div>
                          <div class="req-desc-excerpt" style="font-size:0.73rem; max-width:180px; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($req['description'] ?? '') ?>">
                            <?= htmlspecialchars($req['description'] ?: 'No description specified.') ?>
                          </div>
                        </td>

                        <!-- 4. Requested Amount -->
                        <td data-label="Requested Amount">
                          <div class="req-amount-val" style="font-size:0.88rem; white-space:nowrap;">&#8369;<?= number_format((float)$req['amount'], 2) ?></div>
                          <?php if (!empty($req['recommended_amount']) && (float)$req['recommended_amount'] != (float)$req['amount']): ?>
                            <div style="font-size:0.7rem; color:#6d28d9; font-weight:700; margin-top:2px; white-space:nowrap;" title="SSC Recommended Amount">
                              Rec: &#8369;<?= number_format((float)$req['recommended_amount'], 2) ?>
                            </div>
                          <?php endif; ?>
                        </td>

                        <!-- 5. Submitted By -->
                        <td data-label="Submitted By">
                          <div style="font-weight:600; color:#0f172a; font-size:0.8rem; white-space:nowrap;" title="<?= htmlspecialchars($req['email'] ?? '') ?>"><?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?></div>
                        </td>

                        <!-- 6. Submitted Date -->
                        <td data-label="Submitted Date">
                          <span style="font-size:0.78rem; color:#475569; font-weight:500; white-space:nowrap;"><?= $date_str ?></span>
                        </td>

                        <!-- 7. Adviser Status -->
                        <td data-label="Adviser Status">
                          <?php if ($status === 'Pending Adviser'): ?>
                            <span style="font-size:0.69rem; padding:2px 7px; border-radius:999px; background:#fef3c7; color:#b45309; font-weight:700; white-space:nowrap;">
                              <i class="fa-solid fa-clock"></i> Pending
                            </span>
                          <?php else: ?>
                            <span class="adviser-badge" style="font-size:0.69rem; padding:2px 7px; white-space:nowrap;" title="Endorsed by Faculty Club Adviser">
                              <i class="fa-solid fa-circle-check"></i> Endorsed
                            </span>
                          <?php endif; ?>
                        </td>

                        <!-- 8. SSC Status -->
                        <td data-label="SSC Status">
                          <span class="status-pill <?= $pill_class ?>" style="font-size:0.69rem; padding:2px 8px; white-space:nowrap;">
                            <?= $pill_icon ?> <?= htmlspecialchars($ssc_status_lbl) ?>
                          </span>
                        </td>

                        <!-- 9. Current Stage -->
                        <td data-label="Current Stage">
                          <span class="stage-tag <?= $stage_tag_class ?>" style="font-size:0.69rem; padding:2px 7px; white-space:nowrap;">
                            <?= htmlspecialchars($current_stage_lbl) ?>
                          </span>
                        </td>

                        <!-- 10. Days Pending -->
                        <td data-label="Days Pending">
                          <span class="days-pending-badge <?= $days_pending > 7 ? 'days-urgent' : '' ?>" style="font-size:0.7rem; padding:2px 6px; white-space:nowrap;">
                            <i class="fa-regular fa-clock"></i> <?= $days_pending_str ?>
                          </span>
                        </td>

                        <!-- 11. Action -->
                        <td data-label="Action" style="text-align:right;">
                          <div class="action-btn-group" style="justify-content:flex-end; gap:4px;">
                            <?php if ($status === 'Pending SSC'): ?>
                              <button type="button" class="act-btn act-btn-review" style="height:28px; padding:0 8px; font-size:0.72rem; white-space:nowrap;" 
                                      onclick="openSSCReviewModal(<?= htmlspecialchars(json_encode($req), ENT_QUOTES) ?>)" 
                                      title="Review line items, revise recommendation, or endorse/reject">
                                <i class="fa-solid fa-file-signature"></i> Review
                              </button>
                            <?php else: ?>
                              <button type="button" class="act-btn act-btn-review btn-disabled" disabled style="height:28px; padding:0 8px; font-size:0.72rem; white-space:nowrap;" 
                                      title="Review phase completed (Status: <?= htmlspecialchars($status) ?>)">
                                <i class="fa-solid fa-file-signature"></i> Review
                              </button>
                            <?php endif; ?>
                            <button type="button" class="act-btn act-btn-view" style="height:28px; padding:0 8px; font-size:0.72rem; white-space:nowrap;" 
                                    onclick="openSSCReviewModal(<?= htmlspecialchars(json_encode($req), ENT_QUOTES) ?>)" 
                                    title="View itemization breakdown and audit trail">
                              <i class="fa-solid fa-eye"></i> View
                            </button>
                          </div>
                        </td>

                      <?php else: ?>
                        <!-- ID -->
                        <td style="white-space:nowrap; vertical-align:middle;">
                          <strong style="color:#64748b; font-size:0.8rem; font-family:monospace;">#<?= str_pad($req['id'], 4, '0', STR_PAD_LEFT) ?></strong>
                        </td>

                        <!-- Org & Requester -->
                        <td style="white-space:nowrap; vertical-align:middle;">
                          <div style="display:flex; align-items:center; gap:6px; max-width:210px;">
                            <span class="org-code-chip" style="margin:0; flex-shrink:0;"><?= htmlspecialchars($req['club_code']) ?></span>
                            <span style="font-weight:600; color:#0f172a; font-size:0.82rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($req['club_name']) ?> &bull; Requester: <?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?> &bull; <?= $date_str ?>">
                              <?= htmlspecialchars($req['club_name']) ?>
                            </span>
                          </div>
                        </td>

                        <!-- Requisition Details -->
                        <td style="white-space:nowrap; vertical-align:middle;">
                          <div style="max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:600; font-size:0.82rem; color:#1e293b;" title="<?= htmlspecialchars($req['title']) ?><?= !empty($req['description']) ? ' — ' . htmlspecialchars($req['description']) : '' ?>">
                            <?= htmlspecialchars($req['title']) ?>
                          </div>
                        </td>

                        <!-- Amount -->
                        <td style="white-space:nowrap; vertical-align:middle;">
                          <span class="req-amount-val" style="font-size:0.88rem; white-space:nowrap;">&#8369;<?= number_format((float)$req['amount'], 2) ?></span>
                          <?php if (!empty($req['recommended_amount']) && (float)$req['recommended_amount'] != (float)$req['amount']): ?>
                            <span style="font-size:0.72rem; color:#6d28d9; font-weight:700; margin-left:4px; white-space:nowrap;" title="SSC Recommended: &#8369;<?= number_format((float)$req['recommended_amount'], 2) ?>">(Rec: &#8369;<?= number_format((float)$req['recommended_amount'], 2) ?>)</span>
                          <?php endif; ?>
                          <?php if (!empty($req['final_approved_amount']) && (float)$req['final_approved_amount'] != (float)$req['amount']): ?>
                            <span style="font-size:0.72rem; color:#15803d; font-weight:700; margin-left:4px; white-space:nowrap;" title="Final Released: &#8369;<?= number_format((float)$req['final_approved_amount'], 2) ?>">(Rel: &#8369;<?= number_format((float)$req['final_approved_amount'], 2) ?>)</span>
                          <?php endif; ?>
                        </td>

                        <!-- Stage / Status -->
                        <td style="white-space:nowrap; vertical-align:middle;">
                          <span class="status-pill <?= $pill_class ?>" style="display:inline-flex; align-items:center; gap:4px; padding:3px 8px; font-size:0.72rem; white-space:nowrap;" title="Status: <?= htmlspecialchars($status) ?>">
                            <?= $pill_icon ?> <?= htmlspecialchars($status) ?>
                          </span>
                        </td>

                        <!-- Review Notes -->
                        <td style="white-space:nowrap; vertical-align:middle;">
                          <div style="max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:0.78rem; color:#475569;" title="<?= htmlspecialchars($req['notes'] ?: 'No review notes') ?>">
                            <?= htmlspecialchars($req['notes'] ?: '—') ?>
                          </div>
                        </td>

                        <!-- Actions -->
                        <td style="white-space:nowrap; vertical-align:middle; text-align:right;">
                          <div class="action-btn-group" style="justify-content:flex-end; gap:4px; display:inline-flex; align-items:center; flex-wrap:nowrap; white-space:nowrap;">
                            <?php if ($can_approve): ?>
                              <?php
                                $action_lbl = match($status) {
                                    'Pending Adviser', 'Returned' => 'Endorse',
                                    'Pending SSC'                 => 'Forward',
                                    'Pending Admin', 'Approved'   => 'Disburse',
                                    default                       => 'Approve',
                                };
                              ?>
                              <button type="button" class="act-btn act-btn-approve" style="height:28px; padding:0 8px; font-size:0.72rem; white-space:nowrap;" 
                                      onclick="promptApprove(<?= $req['id'] ?>, '<?= $safe_title ?>', '<?= $status ?>', <?= (float)$req['amount'] ?>, <?= (float)($req['recommended_amount'] ?? $req['amount']) ?>)" 
                                      title="<?= $action_lbl ?> Requisition">
                                <i class="fa-solid fa-check"></i> <?= $action_lbl ?>
                              </button>
                            <?php endif; ?>

                            <?php if (($is_admin_view || $is_ssc_view) && $can_edit_ssc): ?>
                              <button type="button" class="act-btn act-btn-edit" style="height:28px; padding:0 8px; font-size:0.72rem; white-space:nowrap;" 
                                      onclick="openEditModal(<?= $req['id'] ?>, '<?= $safe_desc ?>', '<?= $safe_notes ?>')" 
                                      title="Edit Line Items &amp; Notes">
                                <i class="fa-solid fa-pen-to-square"></i> Audit
                              </button>
                            <?php endif; ?>

                            <?php if ($can_reject): ?>
                              <button type="button" class="act-btn act-btn-reject" style="height:28px; padding:0 8px; font-size:0.72rem; white-space:nowrap;" 
                                      onclick="promptReject(<?= $req['id'] ?>, '<?= $safe_title ?>')" 
                                      title="Reject Requisition">
                                <i class="fa-solid fa-xmark"></i> Reject
                              </button>
                            <?php endif; ?>

                            <button type="button" class="act-btn act-btn-view" style="height:28px; padding:0 8px; font-size:0.72rem; white-space:nowrap;" 
                                    onclick="viewRequisitionDetails(<?= htmlspecialchars(json_encode($req), ENT_QUOTES) ?>)" 
                                    title="View Full Details">
                              <i class="fa-solid fa-eye"></i> View
                            </button>
                          </div>
                        </td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </div>
    <div class="footer">eLearning Commons &copy; 2026</div>
  </div>

  <!-- -------------------------------------------------------------------------
       MODAL 1: Submit New Budget Requisition (With Itemized Line Items)
  -------------------------------------------------------------------------- -->
  <div class="modal-overlay" id="newRequestModal">
    <div class="modal-dialog" style="max-width:680px;">
      <div class="modal-header-solid">
        <h3><i class="fa-solid fa-file-invoice-dollar" style="color:#facc15;"></i> Submit Budget Requisition</h3>
        <button class="modal-close-btn" onclick="closeModal('newRequestModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form id="newRequestForm" onsubmit="handleCreateRequest(event)">
        <div class="modal-body-pad" style="max-height:76vh; overflow-y:auto;">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
            <div class="form-group-custom">
              <label>Host Organization <span style="color:#ef4444;">*</span></label>
              <select name="club_id" class="form-control-custom" required>
                <?php foreach ($user_clubs as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['code']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group-custom">
              <label>Requested Amount (PHP) <span style="color:#ef4444;">*</span></label>
              <div class="currency-input-wrap">
                <span class="currency-prefix">&#8369;</span>
                <input type="number" step="0.01" min="1" name="amount" id="newReqAmountInput" class="form-control-custom" required placeholder="0.00" style="font-weight:700; color:#1a3a8c;"/>
              </div>
            </div>
          </div>

          <div class="form-group-custom">
            <label>Requisition Title <span style="color:#ef4444;">*</span></label>
            <input type="text" name="title" class="form-control-custom" required placeholder="e.g. IT Week Technical Workshop Supplies &amp; Speaker Tokens"/>
          </div>

          <!-- Dynamic Itemized Breakdown Builder -->
          <div class="form-group-custom" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
              <label style="margin:0; font-size:0.78rem; font-weight:800; color:#1a3a8c; text-transform:uppercase; letter-spacing:0.04em;">
                <i class="fa-solid fa-list-ol"></i> Itemized Cost Breakdown
              </label>
              <button type="button" onclick="addLineItemRow()" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; border-radius:6px; padding:4px 10px; font-size:0.73rem; font-weight:700; cursor:pointer;">
                <i class="fa-solid fa-plus"></i> Add Line Item
              </button>
            </div>
            <div id="lineItemsContainer" style="display:flex; flex-direction:column; gap:8px;">
              <div class="line-item-row" style="display:grid; grid-template-columns:1.4fr 1.6fr 70px 110px 32px; gap:8px; align-items:center;">
                <input type="text" class="form-control-custom li-name" placeholder="Item Name (e.g. Kits)" style="padding:7px 10px; font-size:0.8rem;" oninput="recalcLineItemsTotal()"/>
                <input type="text" class="form-control-custom li-desc" placeholder="Specs / Description" style="padding:7px 10px; font-size:0.8rem;"/>
                <input type="number" min="1" value="1" class="form-control-custom li-qty" placeholder="Qty" style="padding:7px 8px; font-size:0.8rem; text-align:center;" oninput="recalcLineItemsTotal()"/>
                <input type="number" step="0.01" min="0" class="form-control-custom li-cost" placeholder="Unit Cost" style="padding:7px 10px; font-size:0.8rem;" oninput="recalcLineItemsTotal()"/>
                <button type="button" onclick="removeLineItemRow(this)" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca; border-radius:6px; height:32px; cursor:pointer;" title="Remove item"><i class="fa-solid fa-trash-can" style="font-size:0.75rem;"></i></button>
              </div>
            </div>
            <input type="hidden" name="line_items" id="newReqLineItemsJson" value=""/>
          </div>

          <div class="form-group-custom" style="margin-bottom:0;">
            <label>Description &amp; Purpose Justification <span style="color:#ef4444;">*</span></label>
            <textarea name="description" rows="3" class="form-control-custom" required placeholder="Specify event purpose, target participants, and timeline justification..."></textarea>
          </div>
        </div>
        <div class="modal-footer-pad">
          <button type="button" onclick="closeModal('newRequestModal')" class="act-btn act-btn-view">Cancel</button>
          <button type="submit" class="act-btn act-btn-approve" id="submitReqBtn" style="padding:9px 18px;">
            <i class="fa-solid fa-paper-plane"></i> Submit Requisition
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- -------------------------------------------------------------------------
       MODAL 2: SSC Line-Item Audit & Edit
  -------------------------------------------------------------------------- -->
  <div class="modal-overlay" id="editModal">
    <div class="modal-dialog">
      <div class="modal-header-solid" style="background:#1a3a8c;">
        <h3><i class="fa-solid fa-pen-to-square" style="color:#facc15;"></i> SSC Line-Item Audit &amp; Review</h3>
        <button class="modal-close-btn" onclick="closeModal('editModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form id="editForm" onsubmit="handleEditRequest(event)">
        <input type="hidden" name="id" id="editId"/>
        <div class="modal-body-pad">
          <p style="font-size:0.83rem; color:#64748b; margin-top:0; margin-bottom:16px;">
            Update description or add official SSC audit notes before forwarding to Administration for disbursement.
          </p>
          <div class="form-group-custom">
            <label>Requisition Description</label>
            <textarea name="description" id="editDesc" rows="3" class="form-control-custom"></textarea>
          </div>
          <div class="form-group-custom" style="margin-bottom:0;">
            <label>SSC Review &amp; Audit Notes</label>
            <textarea name="notes" id="editNotes" rows="3" class="form-control-custom" placeholder="Add line-item recommendations or audit notes..."></textarea>
          </div>
        </div>
        <div class="modal-footer-pad">
          <button type="button" onclick="closeModal('editModal')" class="act-btn act-btn-view">Cancel</button>
          <button type="submit" class="act-btn act-btn-edit" style="padding:9px 18px;">
            <i class="fa-solid fa-floppy-disk"></i> Save Audit Notes
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- -------------------------------------------------------------------------
       MODAL 3: Requisition Full Details View
  -------------------------------------------------------------------------- -->
  <div class="modal-overlay" id="viewReqDetailsModal">
    <div class="modal-dialog">
      <div class="modal-header-solid">
        <h3><i class="fa-solid fa-file-lines" style="color:#facc15;"></i> Requisition Details</h3>
        <button class="modal-close-btn" onclick="closeModal('viewReqDetailsModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="modal-body-pad" id="viewReqDetailsBody">
        <!-- Rendered dynamically -->
      </div>
    </div>
  </div>

  <!-- -------------------------------------------------------------------------
       MODAL 4: Action Confirmation Dialog (Approval / Rejection)
  -------------------------------------------------------------------------- -->
  <div class="modal-overlay" id="actionPromptModal">
    <div class="modal-dialog" style="max-width:480px;">
      <div class="modal-header-solid" id="actionPromptHeader">
        <h3 id="actionPromptTitle"><i class="fa-solid fa-shield-halved"></i> Confirm Action</h3>
        <button class="modal-close-btn" onclick="closeModal('actionPromptModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="modal-body-pad">
        <p id="actionPromptMessage" style="font-size:0.9rem; color:#1e293b; line-height:1.45; margin-top:0; font-weight:600;"></p>
        
        <div id="actionPromptAmountContainer" style="display:none; margin-bottom:14px;">
          <label id="actionPromptAmountLabel" style="font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:6px; display:block;">Amount</label>
          <div class="currency-input-wrap">
            <span class="currency-prefix">&#8369;</span>
            <input type="number" step="0.01" min="1" id="actionPromptAmountInput" class="form-control-custom" placeholder="0.00"/>
          </div>
        </div>

        <div id="actionPromptRefContainer" style="display:none; margin-bottom:14px;">
          <label style="font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:6px; display:block;">Disbursement Reference / Voucher No. (Optional)</label>
          <input type="text" id="actionPromptRefInput" class="form-control-custom" placeholder="e.g. CHK-2026-0042 / VCH-8821"/>
        </div>

        <div class="form-group-custom" style="margin-bottom:0;">
          <label id="actionPromptInputLabel">Notes / Feedback (Optional)</label>
          <textarea id="actionPromptInput" rows="3" class="form-control-custom" placeholder="Provide notes or justification..."></textarea>
        </div>
      </div>
      <div class="modal-footer-pad">
        <button type="button" onclick="closeModal('actionPromptModal')" class="act-btn act-btn-view">Cancel</button>
        <button type="button" id="actionPromptConfirmBtn" class="act-btn act-btn-approve" style="padding:9px 18px;">
          Confirm Action
        </button>
      </div>
    </div>
  </div>

  <!-- -------------------------------------------------------------------------
       MODAL 5: SSC Budget Detail, Itemization & Review Modal
  -------------------------------------------------------------------------- -->
  <div class="modal-overlay" id="sscReviewModal">
    <div class="modal-dialog" style="max-width:760px;">
      <div class="modal-header-solid" style="background:#1a3a8c;">
        <h3><i class="fa-solid fa-file-invoice-dollar" style="color:#facc15;"></i> Budget Detail &amp; Itemization Review</h3>
        <button class="modal-close-btn" onclick="closeModal('sscReviewModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="modal-body-pad" id="sscReviewModalBody">
        <!-- Rendered dynamically -->
      </div>
    </div>
  </div>

  <!-- -------------------------------------------------------------------------
       MODAL 6: Admin Return Requisition for Revision Modal
  -------------------------------------------------------------------------- -->
  <div class="modal-overlay" id="adminReturnModal">
    <div class="modal-dialog" style="max-width:480px;">
      <div class="modal-header-solid" style="background:#d97706;">
        <h3><i class="fa-solid fa-rotate-left"></i> Return Requisition for Revision</h3>
        <button class="modal-close-btn" onclick="closeModal('adminReturnModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form id="adminReturnForm" onsubmit="handleAdminReturn(event)">
        <input type="hidden" name="id" id="adminReturnId"/>
        <div class="modal-body-pad">
          <p id="adminReturnTitleDisplay" style="font-size:0.9rem; color:#1e293b; line-height:1.45; margin-top:0; font-weight:700;"></p>
          <div class="form-group-custom" style="margin-bottom:0;">
            <label style="font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:6px; display:block;">
              Reason for Return &amp; Revision Instructions <span style="color:#ef4444;">*</span>
            </label>
            <textarea name="reason" id="adminReturnReason" rows="4" class="form-control-custom" required placeholder="Specify what line items need revision, additional documentation required, or budget adjustments needed..."></textarea>
          </div>
        </div>
        <div class="modal-footer-pad">
          <button type="button" onclick="closeModal('adminReturnModal')" class="act-btn act-btn-view">Cancel</button>
          <button type="submit" class="act-btn act-btn-return" id="adminReturnConfirmBtn" style="padding:9px 18px;">
            <i class="fa-solid fa-rotate-left"></i> Return Requisition
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- -------------------------------------------------------------------------
       MODAL 7: Admin Budget Override (Reason + Re-Authentication + Audit)
  -------------------------------------------------------------------------- -->
  <div class="modal-overlay" id="adminBudgetOverrideModal">
    <div class="modal-dialog" style="max-width:540px;">
      <div class="modal-header-solid" style="background:linear-gradient(135deg, #4f46e5 0%, #312e81 100%);">
        <h3><i class="fa-solid fa-bolt" style="color:#facc15;"></i> Administrative Budget Override</h3>
        <button class="modal-close-btn" onclick="closeModal('adminBudgetOverrideModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <form id="adminBudgetOverrideForm" onsubmit="handleAdminBudgetOverride(event)">
        <input type="hidden" name="id" id="overrideReqId"/>
        <div class="modal-body-pad">
          <div style="background:#eef2ff; border:1px solid #c7d2fe; border-radius:8px; padding:12px 14px; margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <span id="overrideRefCode" style="font-family:monospace; font-weight:700; color:#4338ca; font-size:0.85rem;"></span>
              <span id="overrideOrgChip" class="org-code-chip"></span>
            </div>
            <h4 id="overrideReqTitle" style="margin:4px 0 0; font-size:0.95rem; color:#1e1b4b; font-weight:700;"></h4>
            <div id="overrideCurrentStatus" style="font-size:0.75rem; color:#6366f1; margin-top:4px; font-weight:600;"></div>
          </div>

          <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:10px 12px; margin-bottom:16px; font-size:0.78rem; color:#92400e; display:flex; gap:8px; align-items:flex-start;">
            <i class="fa-solid fa-triangle-exclamation" style="margin-top:2px; font-size:0.9rem; flex-shrink:0;"></i>
            <div>
              <strong>Security Protocol:</strong> Any administrative override requires a stated justification, administrator password re-authentication, and creates an immutable audit event in the system log.
            </div>
          </div>

          <div class="form-group-custom">
            <label style="font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:6px; display:block;">Override Action Target <span style="color:#ef4444;">*</span></label>
            <select name="override_type" id="overrideTypeSelect" class="form-control-custom" onchange="toggleOverrideTypeFields()" required>
              <option value="disburse">Force Disburse &amp; Release Funds (Disbursed)</option>
              <option value="return">Force Return for Revision (Returned)</option>
              <option value="reject">Force Administrative Rejection (Rejected)</option>
            </select>
          </div>

          <div id="overrideDisburseFields">
            <div class="form-group-custom">
              <label style="font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:6px; display:block;">Final Disbursed Amount (₱) <span style="color:#ef4444;">*</span></label>
              <div class="currency-input-wrap">
                <span class="currency-prefix">&#8369;</span>
                <input type="number" step="0.01" min="1" name="override_amount" id="overrideAmountInput" class="form-control-custom" placeholder="0.00"/>
              </div>
            </div>

            <div class="form-group-custom">
              <label style="font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:6px; display:block;">Disbursement Reference / Voucher No. (Optional)</label>
              <input type="text" name="disbursement_reference" id="overrideRefInput" class="form-control-custom" placeholder="e.g. OVR-DISB-2026-0091"/>
            </div>
          </div>

          <div class="form-group-custom">
            <label style="font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:6px; display:block;">
              Administrative Reason / Justification <span style="color:#ef4444;">*</span>
            </label>
            <textarea name="reason" id="overrideReasonInput" rows="3" class="form-control-custom" required placeholder="Mandatory: Specify why this administrative override is authorized (e.g. emergency campus initiative clearance, revised executive council agreement)..."></textarea>
          </div>

          <div class="form-group-custom" style="margin-bottom:0;">
            <label style="font-size:0.8rem; font-weight:700; color:#b91c1c; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
              <i class="fa-solid fa-lock"></i> Re-Authentication: Administrator Password <span style="color:#ef4444;">*</span>
            </label>
            <input type="password" name="admin_password" id="overrideAdminPassword" class="form-control-custom" required placeholder="Enter your administrator password to authenticate" autocomplete="current-password"/>
          </div>
        </div>
        <div class="modal-footer-pad">
          <button type="button" onclick="closeModal('adminBudgetOverrideModal')" class="act-btn act-btn-view">Cancel</button>
          <button type="submit" class="act-btn act-btn-override" id="overrideSubmitBtn" style="padding:9px 18px;">
            <i class="fa-solid fa-bolt"></i> Authorize &amp; Execute Override
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    function escapeHtml(str) {
      if (str === null || str === undefined) return '';
      return String(str).replace(/[&<>"']/g, function (m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
      });
    }

    function addslashes(str) {
      if (!str) return '';
      return String(str).replace(/\\/g, '\\\\').replace(/\'/g, "\\'").replace(/\"/g, '\\"').replace(/\0/g, '\\0');
    }

    // Live Ledger Table Search & Filter
    function filterLedgerTable() {
      const q = document.getElementById('budgetSearchInput').value.toLowerCase().trim();
      const statusFilter = document.getElementById('budgetStatusFilter').value;
      const rows = document.querySelectorAll('.budget-data-row');
      let visibleCount = 0;

      rows.forEach(row => {
        const rowSearch = (row.dataset.search || row.textContent).toLowerCase();
        const rowStatus = row.dataset.status || '';

        const matchesQuery = !q || rowSearch.includes(q);
        const matchesStatus = statusFilter === 'ALL' || rowStatus === statusFilter;

        if (matchesQuery && matchesStatus) {
          row.removeAttribute('data-search-hidden');
          visibleCount++;
        } else {
          row.setAttribute('data-search-hidden', 'true');
        }
      });

      const emptyRow = document.getElementById('emptyRow');
      if (emptyRow) {
        emptyRow.style.display = visibleCount === 0 ? '' : 'none';
      }

      const tbl = document.getElementById('budgetLedgerTable');
      if (tbl && tbl._paginator) {
        tbl._paginator.currentPage = 1;
        tbl._paginator.render();
      }
    }

    function openNewRequestModal() {
      document.getElementById('newRequestModal').style.display = 'flex';
    }

    function openEditModal(id, desc, notes) {
      document.getElementById('editId').value = id;
      document.getElementById('editDesc').value = desc;
      document.getElementById('editNotes').value = notes;
      document.getElementById('editModal').style.display = 'flex';
    }

    function closeModal(id) {
      const el = document.getElementById(id);
      if (el) el.style.display = 'none';
    }

    // Helper to parse line items safely
    function getParsedLineItems(req) {
      let lineItems = [];
      if (req.line_items) {
        try {
          lineItems = typeof req.line_items === 'string' ? JSON.parse(req.line_items) : req.line_items;
        } catch(e) {
          console.warn('Could not parse line items JSON:', e);
        }
      }
      if (!Array.isArray(lineItems) || lineItems.length === 0) {
        lineItems = [
          {
            item: req.title || 'General Requisition Items',
            description: req.description || 'Program materials & supplies',
            qty: 1,
            unit_cost: parseFloat(req.amount),
            total: parseFloat(req.amount)
          }
        ];
      }
      return lineItems;
    }

    // View Requisition Details Modal (With Budget Detail and Itemization)
    function viewRequisitionDetails(req) {
      const body = document.getElementById('viewReqDetailsBody');
      const formattedAmount = '₱' + parseFloat(req.amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const formattedDate = new Date(req.created_at).toLocaleDateString('en-PH', { dateStyle: 'medium' });
      const refNo = 'REQ-' + new Date(req.created_at).getFullYear() + '-' + String(req.id).padStart(4, '0');
      const lineItems = getParsedLineItems(req);

      let tableRowsHtml = '';
      let grandTotal = 0;
      lineItems.forEach(it => {
        const itemTotal = parseFloat(it.total || (it.qty * it.unit_cost));
        grandTotal += itemTotal;
        tableRowsHtml += `
          <tr>
            <td><strong>${escapeHtml(it.item || 'Item')}</strong></td>
            <td style="color:#475569;">${escapeHtml(it.description || '—')}</td>
            <td style="text-align:center; font-weight:600;">${it.qty || 1}</td>
            <td style="text-align:right;">Php ${parseFloat(it.unit_cost || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
            <td style="text-align:right; font-weight:700; color:#0f172a;">Php ${itemTotal.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
          </tr>
        `;
      });

      body.innerHTML = `
        <div style="display:flex; flex-direction:column; gap:16px;">
          <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
            <div>
              <div style="display:flex; align-items:center; gap:8px;">
                <span class="org-code-chip">${escapeHtml(req.club_code)}</span>
                <span style="font-size:0.8rem; font-weight:700; color:#1a3a8c; font-family:monospace;">${refNo}</span>
              </div>
              <h4 style="margin:4px 0 0; font-size:1.1rem; color:#0f172a;">${escapeHtml(req.title)}</h4>
            </div>
            <div style="text-align:right;">
              <div style="font-size:0.75rem; color:#64748b; font-weight:700; text-transform:uppercase;">Requested Amount</div>
              <div style="font-size:1.3rem; font-weight:800; color:#1a3a8c;">${formattedAmount}</div>
            </div>
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; background:#f8fafc; padding:14px; border-radius:10px; border:1px solid #e2e8f0;">
            <div>
              <span style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Organization</span>
              <div style="font-size:0.85rem; font-weight:700; color:#0f172a; margin-top:2px;">${escapeHtml(req.club_name)}</div>
            </div>
            <div>
              <span style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Requested By</span>
              <div style="font-size:0.85rem; font-weight:600; color:#0f172a; margin-top:2px;">${escapeHtml(req.first_name + ' ' + req.last_name)}</div>
            </div>
            <div>
              <span style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Date Filed</span>
              <div style="font-size:0.85rem; font-weight:600; color:#0f172a; margin-top:2px;">${formattedDate}</div>
            </div>
            <div>
              <span style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase;">Current Stage</span>
              <div style="font-size:0.85rem; font-weight:700; color:#1a3a8c; margin-top:2px;">${escapeHtml(req.status)}</div>
            </div>
          </div>

          <!-- Official Disbursement Detail Section -->
          <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:10px; padding:16px;">
            <div style="font-size:0.85rem; font-weight:800; color:#1a3a8c; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
              <i class="fa-solid fa-file-invoice-dollar"></i> Disbursement detail
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap:12px 16px;">
              <div>
                <span style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase; display:block;">Requested Amount</span>
                <span style="font-size:1.05rem; font-weight:800; color:#0f172a;">${formattedAmount}</span>
              </div>
              <div>
                <span style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase; display:block;">SSC Recommended Amount</span>
                <span style="font-size:1.05rem; font-weight:800; color:${req.recommended_amount ? '#6d28d9' : '#64748b'};">
                  ${req.recommended_amount ? ('₱' + parseFloat(req.recommended_amount).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})) : '—'}
                </span>
              </div>
              <div>
                <span style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase; display:block;">Final Approved Amount</span>
                <span style="font-size:1.05rem; font-weight:800; color:${req.final_approved_amount ? '#15803d' : '#64748b'};">
                  ${req.final_approved_amount ? ('₱' + parseFloat(req.final_approved_amount).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})) : '—'}
                </span>
              </div>
              <div>
                <span style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase; display:block;">Disbursement Date</span>
                <span style="font-size:0.9rem; font-weight:600; color:#0f172a;">
                  ${req.disbursed_at ? new Date(req.disbursed_at).toLocaleDateString('en-PH', {dateStyle:'medium'}) : '—'}
                </span>
              </div>
              <div>
                <span style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase; display:block;">Disbursement Reference</span>
                <span style="font-size:0.9rem; font-family:monospace; font-weight:700; color:#1a3a8c;">
                  ${escapeHtml(req.disbursement_reference || '—')}
                </span>
              </div>
              <div>
                <span style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase; display:block;">Disbursed By</span>
                <span style="font-size:0.9rem; font-weight:600; color:#0f172a;">
                  ${req.disburser_first ? escapeHtml(req.disburser_first + ' ' + req.disburser_last) : (req.disbursed_by ? ('Admin #' + req.disbursed_by) : '—')}
                </span>
              </div>
            </div>
          </div>

          <!-- Budget Detail and Itemization Table -->
          <div class="itemization-card">
            <div class="itemization-header">
              <div class="itemization-title">
                <i class="fa-solid fa-receipt" style="color:#1a3a8c;"></i> Budget detail and itemization
              </div>
              <span class="itemization-count-chip">${lineItems.length} Entries</span>
            </div>
            <div style="overflow-x:auto;">
              <table class="itemization-table">
                <thead>
                  <tr>
                    <th style="min-width:120px;">Item</th>
                    <th style="min-width:160px;">Description</th>
                    <th style="text-align:center; width:60px;">Qty</th>
                    <th style="text-align:right; width:110px;">Unit Cost</th>
                    <th style="text-align:right; width:120px;">Total</th>
                  </tr>
                </thead>
                <tbody>
                  ${tableRowsHtml}
                </tbody>
                <tfoot>
                  <tr class="itemization-foot-row">
                    <td colspan="4" style="text-align:right; font-weight:700; text-transform:uppercase; font-size:0.76rem; letter-spacing:0.04em; color:#475569;">
                      Grand Total:
                    </td>
                    <td style="text-align:right; font-weight:800; font-size:1.05rem; color:#1a3a8c;">
                      Php ${grandTotal.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>

          <div>
            <span style="font-size:0.72rem; font-weight:800; color:#64748b; text-transform:uppercase;">General Justification &amp; Narrative</span>
            <p style="margin:6px 0 0; font-size:0.88rem; line-height:1.55; color:#334155; white-space:pre-line;">${escapeHtml(req.description || 'No description specified.')}</p>
          </div>

          <div>
            <span style="font-size:0.72rem; font-weight:800; color:#64748b; text-transform:uppercase;">Audit &amp; Review History</span>
            <p style="margin:6px 0 0; font-size:0.85rem; color:#475569; background:#f1f5f9; padding:10px 12px; border-radius:8px;">${escapeHtml(req.notes || 'No review notes logged yet.')}</p>
          </div>
        </div>
      `;

      document.getElementById('viewReqDetailsModal').style.display = 'flex';
    }

    // SSC Review & Endorsement Modal (With Budget Detail and Itemization)
    function openSSCReviewModal(req) {
      const modal = document.getElementById('sscReviewModal');
      const body = document.getElementById('sscReviewModalBody');
      const refNo = 'REQ-' + new Date(req.created_at).getFullYear() + '-' + String(req.id).padStart(4, '0');
      const formattedAmount = '₱' + parseFloat(req.amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const dateStr = new Date(req.created_at).toLocaleDateString('en-PH', { dateStyle: 'medium' });
      const lineItems = getParsedLineItems(req);

      let tableRowsHtml = '';
      let grandTotal = 0;
      lineItems.forEach(it => {
        const itemTotal = parseFloat(it.total || (it.qty * it.unit_cost));
        grandTotal += itemTotal;
        tableRowsHtml += `
          <tr>
            <td><strong>${escapeHtml(it.item || 'Item')}</strong></td>
            <td style="color:#475569;">${escapeHtml(it.description || '—')}</td>
            <td style="text-align:center; font-weight:600;">${it.qty || 1}</td>
            <td style="text-align:right;">Php ${parseFloat(it.unit_cost || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
            <td style="text-align:right; font-weight:700; color:#0f172a;">Php ${itemTotal.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
          </tr>
        `;
      });

      const isPendingSSC = (req.status === 'Pending SSC');
      const currentRecVal = (req.recommended_amount && parseFloat(req.recommended_amount) > 0) 
        ? parseFloat(req.recommended_amount).toFixed(2) 
        : parseFloat(req.amount).toFixed(2);

      body.innerHTML = `
        <div style="display:flex; flex-direction:column; gap:16px;">
          <!-- Header Meta Banner -->
          <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:1px solid #e2e8f0; padding-bottom:14px; flex-wrap:wrap; gap:12px;">
            <div>
              <div style="display:flex; align-items:center; gap:8px;">
                <span class="org-code-chip">${escapeHtml(req.club_code)}</span>
                <span style="font-size:0.82rem; font-weight:700; color:#1a3a8c; font-family:monospace;">${refNo}</span>
              </div>
              <h3 style="margin:4px 0 2px; font-size:1.12rem; color:#0f172a; font-weight:700;">${escapeHtml(req.title)}</h3>
              <div style="font-size:0.78rem; color:#64748b;">
                Organization: <strong style="color:#0f172a;">${escapeHtml(req.club_name)}</strong> &bull; Requested by <strong style="color:#0f172a;">${escapeHtml(req.first_name + ' ' + req.last_name)}</strong> on ${dateStr}
              </div>
            </div>
            <div style="text-align:right;">
              <div style="font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.04em;">Original Requested</div>
              <div style="font-size:1.35rem; font-weight:800; color:#1a3a8c;">${formattedAmount}</div>
              <span class="adviser-badge" style="margin-top:4px;"><i class="fa-solid fa-circle-check"></i> Endorsed by Adviser</span>
            </div>
          </div>

          <!-- Budget Detail and Itemization Table -->
          <div class="itemization-card">
            <div class="itemization-header">
              <div class="itemization-title">
                <i class="fa-solid fa-receipt" style="color:#1a3a8c;"></i> Budget detail and itemization
              </div>
              <span class="itemization-count-chip">${lineItems.length} Itemized Entries</span>
            </div>
            <div style="overflow-x:auto;">
              <table class="itemization-table">
                <thead>
                  <tr>
                    <th style="min-width:130px;">Item</th>
                    <th style="min-width:180px;">Description</th>
                    <th style="text-align:center; width:65px;">Qty</th>
                    <th style="text-align:right; width:115px;">Unit Cost</th>
                    <th style="text-align:right; width:125px;">Total</th>
                  </tr>
                </thead>
                <tbody>
                  ${tableRowsHtml}
                </tbody>
                <tfoot>
                  <tr class="itemization-foot-row">
                    <td colspan="4" style="text-align:right; font-weight:700; text-transform:uppercase; font-size:0.76rem; letter-spacing:0.04em; color:#475569;">
                      Grand Total:
                    </td>
                    <td style="text-align:right; font-weight:800; font-size:1.05rem; color:#1a3a8c;">
                      Php ${grandTotal.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>

          <!-- Description / Narrative -->
          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px;">
            <div style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:4px; letter-spacing:0.04em;">
              Requisition Purpose &amp; Justification
            </div>
            <div style="font-size:0.85rem; color:#334155; line-height:1.45;">
              ${escapeHtml(req.description || 'No additional narrative specified.')}
            </div>
          </div>

          ${isPendingSSC ? `
            <!-- SSC Review and Vetting Box -->
            <div style="background:#f0f7ff; border:1px solid #bfdbfe; border-radius:12px; padding:16px;">
              <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                <i class="fa-solid fa-sliders" style="color:#2563eb; font-size:1rem;"></i>
                <h4 style="margin:0; font-size:0.92rem; font-weight:700; color:#1e40af;">SSC Audit &amp; Recommendation Control</h4>
              </div>

              <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:12px;">
                <div>
                  <label style="font-size:0.75rem; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:5px; display:block;">Original Requested</label>
                  <div style="font-size:1.05rem; font-weight:700; color:#475569; padding:8px 12px; background:#ffffff; border:1px solid #cbd5e1; border-radius:8px;">
                    ${formattedAmount}
                  </div>
                </div>
                <div>
                  <label style="font-size:0.75rem; font-weight:700; color:#1a3a8c; text-transform:uppercase; margin-bottom:5px; display:block;">
                    SSC Recommended Amount (Php) <span style="color:#ef4444;">*</span>
                  </label>
                  <div class="currency-input-wrap">
                    <span class="currency-prefix">₱</span>
                    <input type="number" step="0.01" min="1" id="modalSSCAmount" class="form-control-custom" value="${currentRecVal}" style="font-weight:700; color:#1a3a8c; font-size:1.05rem;"/>
                  </div>
                </div>
              </div>

              <div class="form-group-custom" style="margin-bottom:0;">
                <label style="font-size:0.75rem; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:5px; display:block;">
                  SSC Review &amp; Audit Notes
                </label>
                <textarea id="modalSSCNotes" rows="3" class="form-control-custom" placeholder="Specify line-item vetting observations, justification for recommended revision, or endorsement remarks...">${escapeHtml(req.notes || '')}</textarea>
              </div>
            </div>

            <!-- Action Buttons Bar -->
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-top:8px; border-top:1px solid #e2e8f0; padding-top:16px;">
              <div>
                <button type="button" class="act-btn act-btn-reject" onclick="handleSSCReject(${req.id}, '${escapeHtml(addslashes(req.title))}')" style="padding:9px 16px;">
                  <i class="fa-solid fa-xmark"></i> Reject Requisition
                </button>
              </div>
              <div style="display:flex; align-items:center; gap:10px;">
                <button type="button" class="act-btn act-btn-view" onclick="closeModal('sscReviewModal')">Cancel</button>
                <button type="button" class="act-btn act-btn-edit" onclick="handleSSCRevise(${req.id})" style="padding:9px 16px;" title="Save revised recommended amount without forwarding yet">
                  <i class="fa-solid fa-floppy-disk"></i> Revise Recommendation
                </button>
                <button type="button" class="act-btn act-btn-approve" onclick="handleSSCEndorse(${req.id})" style="padding:9px 18px;" title="Endorse and forward to Administration for final disbursement">
                  <i class="fa-solid fa-share-from-square"></i> Endorse to Admin
                </button>
              </div>
            </div>
          ` : `
            <!-- Historical Notes for View Mode -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 16px;">
              <div style="font-size:0.72rem; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:4px; letter-spacing:0.04em;">
                Audit &amp; Workflow History
              </div>
              <div style="font-size:0.85rem; color:#475569;">
                ${escapeHtml(req.notes || 'No review notes logged.')}
              </div>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:8px; border-top:1px solid #e2e8f0; padding-top:14px;">
              <button type="button" class="act-btn act-btn-view" onclick="closeModal('sscReviewModal')">Close</button>
            </div>
          `}
        </div>
      `;

      modal.style.display = 'flex';
    }

    // SSC Endorse Action
    async function handleSSCEndorse(id) {
      const amtInput = document.getElementById('modalSSCAmount');
      const notesInput = document.getElementById('modalSSCNotes');
      const recAmt = amtInput ? parseFloat(amtInput.value) : 0;
      if (!recAmt || recAmt <= 0) {
        window.alert('Please enter a valid recommended amount.', 'warning');
        return;
      }
      const confirmed = await window.showConfirmModal(
        'Endorse Budget Requisition?',
        'Do you want to endorse this budget requisition and forward to Administration for final disbursement?',
        { type: 'decision', confirmText: 'Yes, Endorse Requisition' }
      );
      if (!confirmed) return;

      const fd = new FormData();
      fd.append('action', 'approve');
      fd.append('id', id);
      fd.append('recommended_amount', recAmt);
      fd.append('notes', notesInput ? notesInput.value.trim() : '');
      if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

      try {
        const res = await fetch('../shared/budget_actions.php', { method: 'POST', body: fd });
        const data = await res.json();
        closeModal('sscReviewModal');
        if (data.success) {
          await window.showSystemModal({ title: 'Requisition Endorsed', message: data.message, type: 'success' });
          location.reload();
        } else {
          window.alert(data.message, 'error');
        }
      } catch {
        window.alert('Network error.', 'error');
      }
    }

    // SSC Revise Recommendation Action
    async function handleSSCRevise(id) {
      const amtInput = document.getElementById('modalSSCAmount');
      const notesInput = document.getElementById('modalSSCNotes');
      const recAmt = amtInput ? parseFloat(amtInput.value) : 0;
      if (!recAmt || recAmt <= 0) {
        window.alert('Please enter a valid recommended amount.', 'warning');
        return;
      }

      const confirmed = await window.showConfirmModal(
        'Update SSC Recommendation?',
        'Do you want to update the recommended budget allocation and audit notes for this requisition?',
        { type: 'info', confirmText: 'Yes, Save Changes' }
      );
      if (!confirmed) return;

      const fd = new FormData();
      fd.append('action', 'ssc_edit');
      fd.append('id', id);
      fd.append('recommended_amount', recAmt);
      fd.append('notes', notesInput ? notesInput.value.trim() : '');
      if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

      try {
        const res = await fetch('../shared/budget_actions.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
          await window.showSystemModal({ title: 'Recommendation Saved', message: data.message, type: 'success' });
          location.reload();
        } else {
          window.alert(data.message, 'error');
        }
      } catch {
        window.alert('Network error.', 'error');
      }
    }

    // SSC Reject from Review Modal
    function handleSSCReject(id, title) {
      closeModal('sscReviewModal');
      promptReject(id, title);
    }

    // Modal Action Confirmation Dialogs
    let pendingAction = null;

    function promptApprove(id, title, status, amount = 0, recAmount = 0) {
      let label = 'Endorse Requisition';
      let msg = `Are you sure you want to endorse and forward "${title}" to Stage 2 (SSC Review)?`;

      const amtCont = document.getElementById('actionPromptAmountContainer');
      const amtLabel = document.getElementById('actionPromptAmountLabel');
      const amtInput = document.getElementById('actionPromptAmountInput');
      const refCont = document.getElementById('actionPromptRefContainer');
      const refInput = document.getElementById('actionPromptRefInput');

      amtCont.style.display = 'none';
      refCont.style.display = 'none';
      amtInput.value = '';
      refInput.value = '';

      if (status === 'Pending SSC') {
        label = 'Forward to Admin';
        msg = `Audit complete. Forward "${title}" to Stage 3 (Admin Final Approval)?`;
        amtCont.style.display = 'block';
        amtLabel.textContent = 'Recommended Amount for Admin (₱)';
        amtInput.value = (recAmount > 0 ? recAmount : amount).toFixed(2);
      } else if (status === 'Pending Admin') {
        label = 'Disburse & Release Funds';
        msg = `Final authorization: Approve and disburse funding for "${title}"?`;
        amtCont.style.display = 'block';
        amtLabel.textContent = 'Final Disbursed Amount (₱)';
        amtInput.value = (recAmount > 0 ? recAmount : amount).toFixed(2);
        refCont.style.display = 'block';
      }

      document.getElementById('actionPromptTitle').innerHTML = `<i class="fa-solid fa-check-circle" style="color:#22c55e;"></i> ${label}`;
      document.getElementById('actionPromptHeader').style.background = '#1a3a8c';
      document.getElementById('actionPromptMessage').textContent = msg;
      document.getElementById('actionPromptInputLabel').textContent = 'Audit / Approval Notes (Optional)';
      document.getElementById('actionPromptInput').value = '';
      document.getElementById('actionPromptConfirmBtn').className = 'act-btn act-btn-approve';
      document.getElementById('actionPromptConfirmBtn').innerHTML = `<i class="fa-solid fa-check"></i> Confirm ${label}`;

      pendingAction = async () => {
        const confirmed = await window.showConfirmModal(
          label + '?',
          `Do you want to ${label.toLowerCase()} for "${title}"?`,
          { type: 'info', confirmText: `Yes, ${label}` }
        );
        if (!confirmed) return;

        const notes = document.getElementById('actionPromptInput').value.trim();
        const fd = new FormData();
        fd.append('action', 'approve');
        fd.append('id', id);
        fd.append('notes', notes);
        if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

        if (status === 'Pending SSC' && amtInput.value) {
          fd.append('recommended_amount', amtInput.value);
        } else if (status === 'Pending Admin') {
          if (amtInput.value) fd.append('final_approved_amount', amtInput.value);
          if (refInput.value.trim()) fd.append('disbursement_reference', refInput.value.trim());
        }

        const btn = document.getElementById('actionPromptConfirmBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';

        try {
          const res = await fetch('../shared/budget_actions.php', { method: 'POST', body: fd });
          const data = await res.json();
          closeModal('actionPromptModal');
          if (data.success) {
            alert('✓ ' + data.message);
            location.reload();
          } else {
            alert('✗ ' + data.message);
          }
        } catch {
          alert('Network error.');
        }
        btn.disabled = false;
      };

      document.getElementById('actionPromptModal').style.display = 'flex';
    }

    function promptReject(id, title) {
      document.getElementById('actionPromptTitle').innerHTML = `<i class="fa-solid fa-times-circle" style="color:#ef4444;"></i> Reject Requisition`;
      document.getElementById('actionPromptHeader').style.background = '#dc2626';
      document.getElementById('actionPromptMessage').textContent = `Please specify the feedback or reasons for rejecting "${title}":`;
      document.getElementById('actionPromptInputLabel').textContent = 'Reason for Rejection *';
      document.getElementById('actionPromptInput').value = '';
      document.getElementById('actionPromptConfirmBtn').className = 'act-btn act-btn-reject';
      document.getElementById('actionPromptConfirmBtn').innerHTML = `<i class="fa-solid fa-times"></i> Confirm Rejection`;

      pendingAction = async () => {
        const reason = document.getElementById('actionPromptInput').value.trim();
        if (!reason) {
          window.alert('Please enter a reason for rejection.', 'warning');
          return;
        }

        const confirmed = await window.showConfirmModal(
          'Reject Budget Requisition?',
          'Do you want to reject this budget requisition with the stated reason?',
          { type: 'error', danger: true, confirmText: 'Yes, Reject Requisition' }
        );
        if (!confirmed) return;

        const fd = new FormData();
        fd.append('action', 'reject');
        fd.append('id', id);
        fd.append('reason', reason);
        if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

        const btn = document.getElementById('actionPromptConfirmBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';

        try {
          const res = await fetch('../shared/budget_actions.php', { method: 'POST', body: fd });
          const data = await res.json();
          closeModal('actionPromptModal');
          if (data.success) {
            await window.showSystemModal({ title: 'Requisition Rejected', message: data.message, type: 'warning' });
            location.reload();
          } else {
            window.alert(data.message, 'error');
          }
        } catch {
          window.alert('Network error.', 'error');
        }
        btn.disabled = false;
      };

      document.getElementById('actionPromptModal').style.display = 'flex';
    }

    document.getElementById('actionPromptConfirmBtn').addEventListener('click', () => {
      if (typeof pendingAction === 'function') pendingAction();
    });

    // Handle Admin Return Requisition
    function openAdminReturnModal(id, title) {
      document.getElementById('adminReturnId').value = id;
      document.getElementById('adminReturnTitleDisplay').textContent = 'Return for Revision: "' + title + '"';
      document.getElementById('adminReturnReason').value = '';
      document.getElementById('adminReturnModal').style.display = 'flex';
    }

    async function handleAdminReturn(e) {
      e.preventDefault();
      const form = e.target;
      const btn = document.getElementById('adminReturnConfirmBtn');
      const reason = document.getElementById('adminReturnReason').value.trim();

      if (!reason) {
        window.alert('Please enter revision instructions or the reason for returning this requisition.', 'warning');
        return;
      }

      const confirmed = await window.showConfirmModal(
        'Return Requisition for Revision?',
        'Do you want to return this budget requisition for revision with the stated instructions?',
        { type: 'warning', warning: true, confirmText: 'Yes, Return for Revision' }
      );
      if (!confirmed) return;

      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';

      const fd = new FormData(form);
      fd.append('action', 'return');
      if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

      try {
        const res = await fetch('../shared/budget_actions.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
          await window.showSystemModal({ title: 'Requisition Returned', message: data.message, type: 'warning' });
          closeModal('adminReturnModal');
          location.reload();
        } else {
          alert('✗ ' + data.message);
          btn.disabled = false;
          btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Return Requisition';
        }
      } catch (err) {
        alert('An unexpected network error occurred.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Return Requisition';
      }
    }

    // Handle Admin Override Modal
    function openAdminBudgetOverrideModal(req) {
      const refNo = 'REQ-' + new Date(req.created_at).getFullYear() + '-' + String(req.id).padStart(4, '0');
      document.getElementById('overrideReqId').value = req.id;
      document.getElementById('overrideRefCode').textContent = refNo;
      document.getElementById('overrideOrgChip').textContent = req.club_code || 'CLUB';
      document.getElementById('overrideReqTitle').textContent = req.title || '';
      document.getElementById('overrideCurrentStatus').innerHTML = 'Current Status: <strong>' + escapeHtml(req.status) + '</strong>';

      const defaultAmt = req.final_approved_amount || req.recommended_amount || req.amount;
      document.getElementById('overrideAmountInput').value = parseFloat(defaultAmt || 0).toFixed(2);
      document.getElementById('overrideRefInput').value = req.disbursement_reference || ('OVR-DISB-' + new Date().getFullYear() + '-' + String(req.id).padStart(4, '0'));
      document.getElementById('overrideReasonInput').value = '';
      document.getElementById('overrideAdminPassword').value = '';
      document.getElementById('overrideTypeSelect').value = 'disburse';
      toggleOverrideTypeFields();

      document.getElementById('adminBudgetOverrideModal').style.display = 'flex';
    }

    function toggleOverrideTypeFields() {
      const val = document.getElementById('overrideTypeSelect').value;
      const disburseFields = document.getElementById('overrideDisburseFields');
      const amountInput = document.getElementById('overrideAmountInput');
      if (val === 'disburse') {
        disburseFields.style.display = 'block';
        amountInput.required = true;
      } else {
        disburseFields.style.display = 'none';
        amountInput.required = false;
      }
    }

    async function handleAdminBudgetOverride(e) {
      e.preventDefault();
      const form = e.target;
      const btn = document.getElementById('overrideSubmitBtn');
      const reason = document.getElementById('overrideReasonInput').value.trim();
      const pass = document.getElementById('overrideAdminPassword').value;

      if (!reason) {
        alert('Please provide a mandatory justification/reason for this administrative override.');
        return;
      }
      if (!pass) {
        alert('Administrator password is required for security re-authentication.');
        return;
      }

      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Authenticating &amp; Executing...';

      const fd = new FormData(form);
      fd.append('action', 'override');
      if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

      try {
        const res = await fetch('../shared/budget_actions.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
          alert('✓ ' + data.message);
          closeModal('adminBudgetOverrideModal');
          location.reload();
        } else {
          alert('✗ ' + data.message);
          btn.disabled = false;
          btn.innerHTML = '<i class="fa-solid fa-bolt"></i> Authorize &amp; Execute Override';
        }
      } catch (err) {
        alert('An unexpected network error occurred.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-bolt"></i> Authorize &amp; Execute Override';
      }
    }

    // Dynamic Line Items Builder for New Requisition Modal
    function addLineItemRow() {
      const container = document.getElementById('lineItemsContainer');
      if (!container) return;
      const row = document.createElement('div');
      row.className = 'line-item-row';
      row.style.cssText = 'display:grid; grid-template-columns:1.4fr 1.6fr 70px 110px 32px; gap:8px; align-items:center;';
      row.innerHTML = `
        <input type="text" class="form-control-custom li-name" placeholder="Item Name" style="padding:7px 10px; font-size:0.8rem;" oninput="recalcLineItemsTotal()"/>
        <input type="text" class="form-control-custom li-desc" placeholder="Specs / Description" style="padding:7px 10px; font-size:0.8rem;"/>
        <input type="number" min="1" value="1" class="form-control-custom li-qty" placeholder="Qty" style="padding:7px 8px; font-size:0.8rem; text-align:center;" oninput="recalcLineItemsTotal()"/>
        <input type="number" step="0.01" min="0" class="form-control-custom li-cost" placeholder="Unit Cost" style="padding:7px 10px; font-size:0.8rem;" oninput="recalcLineItemsTotal()"/>
        <button type="button" onclick="removeLineItemRow(this)" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca; border-radius:6px; height:32px; cursor:pointer;" title="Remove item"><i class="fa-solid fa-trash-can" style="font-size:0.75rem;"></i></button>
      `;
      container.appendChild(row);
    }

    function removeLineItemRow(btn) {
      const container = document.getElementById('lineItemsContainer');
      const rows = container ? container.querySelectorAll('.line-item-row') : [];
      if (rows.length > 1) {
        btn.closest('.line-item-row').remove();
      } else if (rows.length === 1) {
        rows[0].querySelector('.li-name').value = '';
        rows[0].querySelector('.li-desc').value = '';
        rows[0].querySelector('.li-qty').value = '1';
        rows[0].querySelector('.li-cost').value = '';
      }
      recalcLineItemsTotal();
    }

    function recalcLineItemsTotal() {
      const rows = document.querySelectorAll('#lineItemsContainer .line-item-row');
      let sum = 0;
      const items = [];
      rows.forEach(r => {
        const name = (r.querySelector('.li-name')?.value || '').trim();
        const desc = (r.querySelector('.li-desc')?.value || '').trim();
        const qty  = Math.max(1, parseFloat(r.querySelector('.li-qty')?.value) || 1);
        const cost = parseFloat(r.querySelector('.li-cost')?.value) || 0;
        if (name || cost > 0) {
          const total = qty * cost;
          sum += total;
          items.push({
            item: name || 'Requisition Line Item',
            description: desc || 'Operational supplies & materials',
            qty: qty,
            unit_cost: cost,
            total: total
          });
        }
      });
      const jsonField = document.getElementById('newReqLineItemsJson');
      if (jsonField) {
        jsonField.value = items.length > 0 ? JSON.stringify(items) : '';
      }
      const amtInput = document.getElementById('newReqAmountInput');
      if (amtInput && sum > 0) {
        amtInput.value = sum.toFixed(2);
      }
    }

    // Handle Create Request
    async function handleCreateRequest(e) {
      e.preventDefault();
      recalcLineItemsTotal();
      const btn = document.getElementById('submitReqBtn');
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';

      const fd = new FormData(e.target);
      fd.append('action', 'create');
      if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

      try {
        const res = await fetch('../shared/budget_actions.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
          alert('✓ ' + data.message);
          location.reload();
        } else {
          alert('✗ ' + data.message);
        }
      } catch {
        alert('Network error.');
      }
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Requisition';
    }

    // Handle Edit Request
    async function handleEditRequest(e) {
      e.preventDefault();
      const fd = new FormData(e.target);
      fd.append('action', 'ssc_edit');
      if (CSRF_TOKEN) fd.append('csrf_token', CSRF_TOKEN);

      try {
        const res = await fetch('../shared/budget_actions.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
          alert('✓ ' + data.message);
          location.reload();
        } else {
          alert('✗ ' + data.message);
        }
      } catch {
        alert('Network error.');
      }
    }

    document.addEventListener('DOMContentLoaded', () => {
      const initPagination = () => {
        if (window.initTablePagination) {
          window.initTablePagination('#budgetLedgerTable', {
            pageSize: 10,
            showInfo: true,
            showPageSizeSelector: true
          });
        }
      };
      initPagination();
      window.addEventListener('load', initPagination);
    });
  </script>

  <script src="https://unpkg.com/@zxing/library@0.21.1/umd/index.min.js"></script>
  <script src="../js/dashboard.js?v=<?= filemtime(__DIR__ . '/../js/dashboard.js') ?>"></script>
  <script src="../js/table-pagination.js"></script>
</body>
</html>
