<?php
// ============================================================
//  AI_ACTIONS.PHP — AJAX Endpoint for AI Features (Adviser & SSC)
//  Actions: plan_events, check_schedule_conflict, generate_report, save_api_key, get_api_key_status
// ============================================================
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ai_config.php';
require_once __DIR__ . '/ai_engine.php';

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$action    = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF check on mutating requests (e.g. saving credentials)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['save_api_key'])) {
    verify_csrf();
}

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'student';

// Restrict AI tools to Adviser, SSC, and Admin
if (!in_array($user_role, ['club_adviser', 'ssc', 'admin'])) {
    echo json_encode([
        'success' => false, 
        'message' => 'Access Restricted: AI event planning and analytics are available exclusively to Faculty Advisers and SSC Officers.'
    ]);
    exit;
}

switch ($action) {

    // ── Save Google Gemini API Key (Admin Only) ────────────────
    case 'save_api_key': {
        if ($user_role !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Permission denied: Only System Administrators can configure system AI credentials.']);
            break;
        }

        $api_key = trim($_POST['api_key'] ?? '');
        if (empty($api_key)) {
            echo json_encode(['success' => false, 'message' => 'API Key cannot be empty.']);
            break;
        }

        $ok = save_gemini_api_key($api_key, $conn);
        if ($ok) {
            log_audit($conn, $user_id, 'ai_api_key_update', 'system_settings', 0, 'Updated Google Gemini API key');
            echo json_encode(['success' => true, 'message' => 'Google Gemini API key saved successfully! Live AI generation is active.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save API key to database.']);
        }
        break;
    }

    // ── Check API Key Status ───────────────────────────────────
    case 'get_api_key_status': {
        $key = get_gemini_api_key($conn);
        $hasKey = !empty($key);
        $masked = $hasKey ? substr($key, 0, 6) . '...' . substr($key, -4) : '';
        echo json_encode(['success' => true, 'has_key' => $hasKey, 'masked_key' => $masked]);
        break;
    }

    // ── Live AI Event Planner & Schedule Conflict Optimizer ────
    case 'plan_events': {
        if (!in_array($user_role, ['club_adviser', 'ssc', 'admin'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Permission denied: AI Event Planner is available to Club Advisers, SSC Officers, and Administrators.'
            ]);
            break;
        }

        $club_id = (int)($_POST['club_id'] ?? $_GET['club_id'] ?? 0);
        $theme   = trim($_POST['theme'] ?? $_GET['theme'] ?? '');

        $result = ai_plan_events_and_schedule($conn, $user_id, [
            'club_id' => $club_id,
            'theme'   => $theme
        ]);
        echo json_encode($result);
        break;
    }

    // ── Live Date Accessibility & Conflict Checker ─────────────
    case 'check_schedule_conflict': {
        $event_date = trim($_POST['event_date'] ?? $_GET['event_date'] ?? '');
        $venue      = trim($_POST['venue'] ?? $_GET['venue'] ?? '');
        $exclude_id = (int)($_POST['exclude_id'] ?? 0);

        if (empty($event_date)) {
            echo json_encode(['success' => false, 'message' => 'Please select an event date & time.']);
            break;
        }

        $analysis = ai_analyze_schedule_conflict($conn, $event_date, $venue, $exclude_id);
        echo json_encode(['success' => true, 'analysis' => $analysis]);
        break;
    }

    // ── AI Intelligent Report (Adviser / SSC / Admin) ────────────
    case 'generate_report': {
        $report_type = trim($_POST['report_type'] ?? '');
        $valid_types = ['activity_events', 'membership_engagement', 'attendance_analytics', 'budget_financial', 'comprehensive'];

        if (!in_array($report_type, $valid_types)) {
            echo json_encode(['success' => false, 'message' => 'Invalid report type.']);
            break;
        }

        $result = ai_generate_report($conn, $report_type, $user_id);
        echo json_encode($result);
        break;
    }

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown AI action.']);
}

$conn->close();
