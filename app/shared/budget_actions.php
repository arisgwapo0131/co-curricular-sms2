<?php
// ============================================================
//  BUDGET_ACTIONS.PHP — Budget & Finance AJAX Handler
//  Workflow: Pending Adviser → Pending SSC → Pending Admin → Disbursed
//  Roles: club_adviser endorses, ssc reviews/edits, admin approves & disburses
// ============================================================
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification_actions.php';
require_once __DIR__ . '/security.php';

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';

// Strictly deny student role
if ($user_role === 'student' || !in_array($user_role, ['club_adviser', 'ssc', 'staff', 'admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Access restricted. Student access to financial requisitions has been terminated.']);
    exit;
}

// CSRF validation on mutating POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function bdRespond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

switch ($action) {

    // ── 1. LIST Budget Requests ──────────────────────────────
    case 'list': {
        $where  = 'WHERE br.deleted_at IS NULL';
        $params = [];
        $types  = '';

        if ($user_role === 'club_adviser') {
            $cm = $conn->prepare("SELECT club_id FROM club_memberships WHERE user_id=? AND status='Active' LIMIT 1");
            $cm->bind_param('i', $user_id);
            $cm->execute();
            $cm->bind_result($my_club_id);
            $cm->fetch();
            $cm->close();
            if (!empty($my_club_id)) {
                $where  .= " AND br.club_id = ?";
                $params = [(int)$my_club_id];
                $types  = 'i';
            } else {
                $where .= " AND 1=0";
            }
        } elseif ($user_role === 'ssc') {
            $where .= " AND br.status IN ('Pending SSC','Pending Admin','Approved','Disbursed','Rejected','Returned')";
        }
        // admin, super_admin, staff see all active (non-deleted) requests

        $sql = "SELECT br.id, br.club_id, br.title, br.description, br.line_items, br.amount, br.recommended_amount, br.final_approved_amount, br.status, br.notes, br.created_at, br.updated_at,
                       c.name AS club_name, c.code AS club_code,
                       u.first_name, u.last_name, u.email
                FROM budget_requests br
                JOIN clubs c ON c.id = br.club_id
                JOIN users u ON u.id = br.requested_by
                $where
                ORDER BY br.created_at DESC";

        $stmt = $conn->prepare($sql);
        if ($types && $params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        bdRespond(true, 'Budget requests loaded.', ['requests' => $requests]);
    }

    // ── 2. CREATE Budget Request ─────────────────────────────
    case 'create': {
        if (!in_array($user_role, ['club_adviser', 'staff', 'admin', 'super_admin'])) {
            bdRespond(false, 'Only Faculty Club Advisers and Administrators can submit budget requests.');
        }

        $club_id     = (int)($_POST['club_id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $amount      = (float)($_POST['amount'] ?? 0);
        $line_items  = trim($_POST['line_items'] ?? '');

        if (!$club_id || empty($title) || $amount <= 0) {
            bdRespond(false, 'Please provide a valid club, title, and positive amount.');
        }

        if ($user_role === 'club_adviser') {
            if (!verify_club_adviser_scope($conn, $club_id, $user_id)) {
                bdRespond(false, 'Unauthorized: You can only submit budget requisitions for your assigned organization.');
            }
        }

        if (empty($line_items)) {
            $line_items = json_encode([
                [
                    'item'        => $title,
                    'description' => $description ?: 'Program materials & operational supplies',
                    'qty'         => 1,
                    'unit_cost'   => $amount,
                    'total'       => $amount
                ]
            ]);
        }

        $initial_status = 'Pending SSC';
        $initial_notes  = 'Adviser proposed — submitted to SSC for review & vetting.';

        $stmt = $conn->prepare(
            "INSERT INTO budget_requests (club_id, title, description, line_items, amount, status, requested_by, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isssdsis', $club_id, $title, $description, $line_items, $amount, $initial_status, $user_id, $initial_notes);

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $stmt->close();

            log_workflow_history($conn, 'budget_requests', $new_id, 'DRAFT', $initial_status, 'budget_submit', $user_id, "Budget requisition created: ₱" . number_format($amount, 2));

            // Notify SSC officers for review & vetting
            $ssc_users = $conn->query("SELECT id FROM users WHERE role='ssc' AND status='active'");
            if ($ssc_users) {
                while ($u = $ssc_users->fetch_assoc()) {
                    push_notification($conn, (int)$u['id'], 'New Budget Requisition',
                        "Club requisition '$title' (₱" . number_format($amount, 2) . ") submitted by Adviser and pending SSC review.", 'info', 'budget_request', $new_id, '../dashboard/budget.php');
                }
            }
            bdRespond(true, 'Budget request submitted successfully!', ['id' => $new_id]);
        } else {
            bdRespond(false, 'Failed to submit budget request: ' . $conn->error);
        }
    }

    // ── 3. APPROVE/FORWARD Budget Request ───────────────────
    // Stage 1: club_adviser  → Pending Adviser → Pending SSC
    // Stage 2: ssc           → Pending SSC     → Pending Admin
    // Stage 3: admin         → Pending Admin   → Disbursed
    case 'approve': {
        $id    = (int)($_POST['id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if (!$id) bdRespond(false, 'Invalid budget request ID.');

        $stmt = $conn->prepare("SELECT br.*, c.name AS club_name FROM budget_requests br JOIN clubs c ON c.id=br.club_id WHERE br.id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$req) bdRespond(false, 'Budget request not found.');

        $current_status = $req['status'];
        $next_status    = '';
        $note_append    = '';

        $conn->begin_transaction();
        try {
            if ($current_status === 'Pending Adviser' || $current_status === 'Returned') {
                if (!in_array($user_role, ['club_adviser', 'admin', 'super_admin'])) {
                    throw new Exception('Only Club Advisers can endorse budget requests at this stage.');
                }
                if ($user_role === 'club_adviser') {
                    if (!verify_club_adviser_scope($conn, (int)$req['club_id'], $user_id)) {
                        throw new Exception('Unauthorized: You can only endorse requests for your assigned organization.');
                    }
                }
                $next_status = 'Pending SSC';
                $note_append = "Endorsed by Club Adviser" . ($notes ? ": $notes" : ".");

                $upd = $conn->prepare("UPDATE budget_requests SET status=?, notes=? WHERE id=?");
                $upd->bind_param('ssi', $next_status, $note_append, $id);
                if (!$upd->execute()) throw new Exception('Database update failed: ' . $conn->error);
                $upd->close();

                log_workflow_history($conn, 'budget_requests', $id, $current_status, $next_status, 'adviser_endorse', $user_id, $note_append);

                // Notify SSC officers
                $sscs = $conn->query("SELECT id FROM users WHERE role='ssc'");
                while ($s = $sscs->fetch_assoc()) {
                    push_notification($conn, (int)$s['id'], 'Budget Endorsed',
                        "Budget request '{$req['title']}' has been endorsed by adviser and needs SSC review.", 'info', 'budget_request', $id, '../dashboard/budget.php');
                }

                $conn->commit();
                push_notification($conn, (int)$req['requested_by'], 'Budget Request Endorsed',
                    "Your request '{$req['title']}' was endorsed by Adviser and forwarded to SSC.", 'info', 'budget_request', $id, '../dashboard/budget.php');
                log_audit($conn, $user_id, "budget_endorsed", 'budget_requests', $id, "Budget #$id endorsed by adviser");
                bdRespond(true, "Budget request endorsed and forwarded to SSC.", ['new_status' => $next_status]);

            } elseif ($current_status === 'Pending SSC') {
                if (!in_array($user_role, ['ssc', 'staff', 'admin', 'super_admin'])) {
                    throw new Exception('Only SSC Officers can forward budget requests at this stage.');
                }
                $next_status = 'Pending Admin';
                $note_append = "Reviewed & forwarded by SSC" . ($notes ? ": $notes" : ".");
                $rec_amt = isset($_POST['recommended_amount']) ? (float)$_POST['recommended_amount'] : (float)$req['amount'];
                if ($rec_amt <= 0) $rec_amt = (float)$req['amount'];

                $upd = $conn->prepare("UPDATE budget_requests SET status=?, notes=?, recommended_amount=? WHERE id=?");
                $upd->bind_param('ssdi', $next_status, $note_append, $rec_amt, $id);
                if (!$upd->execute()) throw new Exception('Database update failed: ' . $conn->error);
                $upd->close();

                log_workflow_history($conn, 'budget_requests', $id, $current_status, $next_status, 'ssc_review', $user_id, $note_append);

                // Notify Admins
                $admins = $conn->query("SELECT id FROM users WHERE role IN ('admin', 'super_admin')");
                while ($a = $admins->fetch_assoc()) {
                    push_notification($conn, (int)$a['id'], 'Budget Needs Disbursement',
                        "Budget request '{$req['title']}' reviewed by SSC (Recommended: ₱" . number_format($rec_amt, 2) . ") awaiting Admin disbursement.", 'info', 'budget_request', $id, '../dashboard/budget.php');
                }

                $conn->commit();
                push_notification($conn, (int)$req['requested_by'], 'Budget Request Reviewed by SSC',
                    "Your request '{$req['title']}' was endorsed by SSC (Recommended: ₱" . number_format($rec_amt, 2) . ") and submitted for final disbursement.", 'success', 'budget_request', $id, '../dashboard/budget.php');
                log_audit($conn, $user_id, "budget_forward_admin", 'budget_requests', $id,
                    "Budget #$id forwarded to Admin by SSC with recommended amount ₱$rec_amt");
                bdRespond(true, "Budget request forwarded to '$next_status'.", ['new_status' => $next_status]);

            } elseif (in_array($current_status, ['Pending Admin', 'Approved'])) {
                if (!in_array($user_role, ['admin', 'super_admin'])) {
                    throw new Exception('Only System Admins can approve and disburse budget requests at this stage.');
                }
                $next_status = 'Disbursed';
                $note_append = "Approved & Disbursed by System Admin" . ($notes ? ": $notes" : ".");
                $final_amt = isset($_POST['final_approved_amount']) ? (float)$_POST['final_approved_amount'] : ($req['recommended_amount'] ?? $req['amount']);
                if ($final_amt <= 0) throw new Exception('Disbursement amount must be greater than zero.');
                $disb_ref  = trim($_POST['disbursement_reference'] ?? ('DISB-' . strtoupper(substr(md5(uniqid()), 0, 8))));

                $upd = $conn->prepare("UPDATE budget_requests SET status=?, notes=?, final_approved_amount=?, disbursement_reference=?, disbursed_by=?, disbursed_at=NOW() WHERE id=?");
                $upd->bind_param('ssdsii', $next_status, $note_append, $final_amt, $disb_ref, $user_id, $id);
                if (!$upd->execute()) throw new Exception('Database update failed: ' . $conn->error);
                $upd->close();

                log_workflow_history($conn, 'budget_requests', $id, $current_status, $next_status, 'admin_disburse', $user_id, "Disbursed ₱" . number_format($final_amt, 2) . " (Ref: $disb_ref)");

                $conn->commit();
                push_notification($conn, (int)$req['requested_by'], 'Budget Disbursed',
                    "Your request '{$req['title']}' of ₱" . number_format($final_amt, 2) . " has been disbursed. Reference: $disb_ref.", 'success', 'budget_request', $id, '../dashboard/budget.php');
                log_audit($conn, $user_id, "budget_disbursed", 'budget_requests', $id,
                    "Budget #$id disbursed by Admin ($disb_ref, ₱$final_amt)");
                bdRespond(true, "Budget request released and disbursed successfully.", ['new_status' => $next_status, 'reference' => $disb_ref]);

            } else {
                throw new Exception("Budget request is in '$current_status' state and cannot be advanced further.");
            }
        } catch (Throwable $e) {
            $conn->rollback();
            bdRespond(false, $e->getMessage());
        }
    }

    // ── 4. SSC EDIT budget request (notes/description/recommended_amount) ──────
    case 'ssc_edit': {
        if (!in_array($user_role, ['ssc', 'staff', 'admin', 'super_admin'])) {
            bdRespond(false, 'Not authorized.');
        }
        $id          = (int)($_POST['id'] ?? 0);
        $notes       = trim($_POST['notes'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $rec_amt     = isset($_POST['recommended_amount']) && is_numeric($_POST['recommended_amount']) ? (float)$_POST['recommended_amount'] : null;
        if (!$id) bdRespond(false, 'Invalid request ID.');

        if ($rec_amt !== null && $rec_amt > 0) {
            if ($description !== '') {
                $upd = $conn->prepare("UPDATE budget_requests SET notes=?, description=?, recommended_amount=? WHERE id=? AND status='Pending SSC'");
                $upd->bind_param('ssdi', $notes, $description, $rec_amt, $id);
            } else {
                $upd = $conn->prepare("UPDATE budget_requests SET notes=?, recommended_amount=? WHERE id=? AND status='Pending SSC'");
                $upd->bind_param('sdi', $notes, $rec_amt, $id);
            }
        } else {
            if ($description !== '') {
                $upd = $conn->prepare("UPDATE budget_requests SET notes=?, description=? WHERE id=? AND status='Pending SSC'");
                $upd->bind_param('ssi', $notes, $description, $id);
            } else {
                $upd = $conn->prepare("UPDATE budget_requests SET notes=? WHERE id=? AND status='Pending SSC'");
                $upd->bind_param('si', $notes, $id);
            }
        }
        if ($upd->execute()) {
            $upd->close();
            bdRespond(true, 'Budget recommendation and notes updated by SSC.');
        } else {
            bdRespond(false, 'Failed to update: ' . $conn->error);
        }
    }

    // ── 5. REJECT Budget Request ─────────────────────────────
    case 'reject': {
        if (!in_array($user_role, ['club_adviser', 'ssc', 'staff', 'admin', 'super_admin'])) {
            bdRespond(false, 'Not authorized to reject budget requests.');
        }

        $id     = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? $_POST['notes'] ?? 'No specific reason provided.');

        if (!$id) bdRespond(false, 'Invalid budget request ID.');

        $req = $conn->query("SELECT requested_by, title, status FROM budget_requests WHERE id=$id")->fetch_assoc();
        if (!$req) bdRespond(false, 'Budget request not found.');

        $upd_note = "Rejected by " . ucwords(str_replace('_', ' ', $user_role)) . ": " . $reason;
        $upd = $conn->prepare("UPDATE budget_requests SET status='Rejected', notes=? WHERE id=?");
        $upd->bind_param('si', $upd_note, $id);

        if ($upd->execute()) {
            $upd->close();
            log_workflow_history($conn, 'budget_requests', $id, $req['status'], 'Rejected', 'reject', $user_id, $reason);
            push_notification($conn, (int)$req['requested_by'], 'Budget Request Rejected',
                "Your request '{$req['title']}' was rejected. Reason: $reason", 'danger', 'budget_request', $id, '../dashboard/budget.php');
            log_audit($conn, $user_id, 'budget_rejected', 'budget_requests', $id, "Rejected by $user_role: $reason");
            bdRespond(true, 'Budget request rejected.');
        } else {
            bdRespond(false, 'Database update failed: ' . $conn->error);
        }
    }

    // ── 6. RETURN Budget Request (SSC / Admin) ────────────────
    case 'return': {
        if (!in_array($user_role, ['ssc', 'staff', 'admin', 'super_admin'])) {
            bdRespond(false, 'Only SSC Officers and Administrators can return budget requests for revision.');
        }

        $id     = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? $_POST['notes'] ?? 'Budget request returned for revision.');

        if (!$id) bdRespond(false, 'Invalid budget request ID.');

        $req = $conn->query("SELECT requested_by, title, status FROM budget_requests WHERE id=$id")->fetch_assoc();
        if (!$req) bdRespond(false, 'Budget request not found.');

        $upd_note = "Returned by " . ucwords(str_replace('_', ' ', $user_role)) . ": " . $reason;
        $upd = $conn->prepare("UPDATE budget_requests SET status='Returned', notes=CONCAT(COALESCE(notes, ''), '\n', ?) WHERE id=?");
        $upd->bind_param('si', $upd_note, $id);

        if ($upd->execute()) {
            $upd->close();
            log_workflow_history($conn, 'budget_requests', $id, $req['status'], 'Returned', 'return_for_revision', $user_id, $reason);
            push_notification($conn, (int)$req['requested_by'], 'Budget Request Returned for Revision',
                "Your request '{$req['title']}' was returned for revision. Reason: $reason", 'warning', 'budget_request', $id, '../dashboard/budget.php');
            log_audit($conn, $user_id, 'budget_return', 'budget_requests', $id, "Returned by $user_role: $reason");
            bdRespond(true, 'Budget request returned for revision.');
        } else {
            bdRespond(false, 'Database update failed: ' . $conn->error);
        }
    }

    // ── 7. ADMIN OVERRIDE (Reason + Re-Authentication + Audit) ─
    case 'override':
    case 'admin_override': {
        if (!in_array($user_role, ['admin', 'super_admin'])) {
            bdRespond(false, 'Unauthorized: Only System Administrators possess override clearance authorization.');
        }

        $id             = (int)($_POST['id'] ?? 0);
        $override_type  = trim($_POST['override_type'] ?? 'disburse');
        $override_amt   = isset($_POST['override_amount']) && is_numeric($_POST['override_amount']) ? (float)$_POST['override_amount'] : null;
        $reason         = trim($_POST['reason'] ?? $_POST['override_reason'] ?? '');
        $admin_password = $_POST['admin_password'] ?? '';

        if ($id <= 0) {
            bdRespond(false, 'Invalid budget requisition ID.');
        }
        if (empty($reason)) {
            bdRespond(false, 'Administrative override reason / justification is mandatory.');
        }
        if (empty($admin_password)) {
            bdRespond(false, 'Re-authentication required. Please enter your administrator password.');
        }

        // Verify Administrator Password Re-Authentication
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $user_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user_row || !password_verify($admin_password, $user_row['password_hash'])) {
            bdRespond(false, 'Re-authentication failed: Incorrect administrator password.');
        }

        // Fetch current requisition record
        $stmt = $conn->prepare("SELECT br.*, c.name AS club_name, c.code AS club_code FROM budget_requests br JOIN clubs c ON c.id=br.club_id WHERE br.id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$req) {
            bdRespond(false, 'Budget requisition not found.');
        }

        $new_status = 'Disbursed';
        $final_amt  = ($override_amt !== null && $override_amt > 0) ? $override_amt : (!empty($req['recommended_amount']) ? (float)$req['recommended_amount'] : (float)$req['amount']);
        $disb_ref   = !empty($req['disbursement_reference']) ? $req['disbursement_reference'] : ('OVR-DISB-' . date('Ymd') . '-' . str_pad($id, 4, '0', STR_PAD_LEFT));

        if ($override_type === 'return') {
            $new_status = 'Returned';
            $note = "Admin Override (Returned): " . $reason;
            $upd = $conn->prepare("UPDATE budget_requests SET status=?, notes=CONCAT(COALESCE(notes, ''), '\n[', ?, ']') WHERE id=?");
            $upd->bind_param('ssi', $new_status, $note, $id);
        } elseif ($override_type === 'reject') {
            $new_status = 'Rejected';
            $note = "Admin Override (Rejected): " . $reason;
            $upd = $conn->prepare("UPDATE budget_requests SET status=?, notes=CONCAT(COALESCE(notes, ''), '\n[', ?, ']') WHERE id=?");
            $upd->bind_param('ssi', $new_status, $note, $id);
        } else {
            // Disburse / Force Release
            $new_status = 'Disbursed';
            $note = "Admin Override Clearance & Release: " . $reason;
            $upd = $conn->prepare("UPDATE budget_requests SET status=?, final_approved_amount=?, disbursement_reference=?, disbursed_by=?, disbursed_at=NOW(), notes=CONCAT(COALESCE(notes, ''), '\n[', ?, ']') WHERE id=?");
            $upd->bind_param('sdsisi', $new_status, $final_amt, $disb_ref, $user_id, $note, $id);
        }

        if (!$upd->execute()) {
            bdRespond(false, 'Database override execution failed: ' . $upd->error);
        }
        $upd->close();

        // 1. Audit Event Logging (Required)
        log_audit($conn, $user_id, 'budget_admin_override', 'budget_requests', $id,
            "Admin override ($override_type) on budget #$id ({$req['club_code']}). Status: $new_status. Amount: ₱$final_amt. Reason: $reason");

        // 2. Notifications
        push_notification($conn, (int)$req['requested_by'], 'Budget Administrative Override Executed',
            "Your requisition '{$req['title']}' was processed via Administrator Override to: $new_status ($reason).",
            ($new_status === 'Disbursed' ? 'success' : 'info'));

        // Notify SSC
        $sscs = $conn->query("SELECT id FROM users WHERE role='ssc'");
        while ($s = $sscs->fetch_assoc()) {
            push_notification($conn, (int)$s['id'], 'Budget Override Notification',
                "Requisition #$id ({$req['club_code']}) received Administrator Override ($override_type).", 'info');
        }

        bdRespond(true, "Administrative override processed successfully! Requisition moved to '$new_status'.", [
            'new_status'   => $new_status,
            'reference'    => $disb_ref,
            'final_amount' => $final_amt
        ]);
    }

    default:
        bdRespond(false, 'Invalid action specified.');
}
