<?php
// ============================================================
//  STUDENT_ACTIONS.PHP  (shared/)
//  Handles all student CRUD operations from dashboard.js.
// ============================================================
header('Content-Type: application/json');
session_start();
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}
require_once __DIR__ . '/security.php';
require_any_permission(['users.manage.all', 'users.view.directory']);
require_once __DIR__ . '/db.php';

$user_role = $_SESSION['role'] ?? 'student';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Mutating actions require admin role and CSRF verification
if (in_array($action, ['add', 'edit', 'delete', 'bulk_delete', 'bulk_status'])) {
    if ($user_role !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Only System Administrators can modify student records.']);
        exit;
    }
    verify_csrf();
}

switch ($action) {

    case 'add':
        $student_number = trim($conn->real_escape_string($_POST['student_number'] ?? ''));
        $first_name     = trim($conn->real_escape_string($_POST['first_name'] ?? ''));
        $last_name      = trim($conn->real_escape_string($_POST['last_name']  ?? ''));
        $birthday       = trim($conn->real_escape_string($_POST['birthday']   ?? ''));
        $course         = trim($conn->real_escape_string($_POST['course']     ?? ''));
        $year_level     = trim($conn->real_escape_string($_POST['year_level'] ?? ''));
        $section        = trim($conn->real_escape_string($_POST['section']    ?? ''));
        $phone          = trim($conn->real_escape_string($_POST['phone']      ?? ''));
        $status         = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if (!$first_name || !$last_name || !$birthday || !$course || !$year_level || !$section || !$phone) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']);
            break;
        }
        if (!$student_number) {
            $student_number = '2024-' . rand(10000, 99999);
        }
        $sql = "INSERT INTO students (student_number,first_name,last_name,birthday,course,year_level,section,phone,status)
                VALUES ('$student_number','$first_name','$last_name','$birthday','$course','$year_level','$section','$phone','$status')";
        if ($conn->query($sql)) {
            echo json_encode(['success' => true, 'message' => 'Student added.', 'id' => $conn->insert_id]);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        break;

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); break; }
        $result  = $conn->query("SELECT * FROM students WHERE id = $id LIMIT 1");
        $student = $result->fetch_assoc();
        echo $student
            ? json_encode(['success' => true, 'student' => $student])
            : json_encode(['success' => false, 'message' => 'Student not found.']);
        break;

    case 'edit':
        $id             = (int)($_POST['id'] ?? 0);
        $student_number = trim($conn->real_escape_string($_POST['student_number'] ?? ''));
        $first_name     = trim($conn->real_escape_string($_POST['first_name'] ?? ''));
        $last_name      = trim($conn->real_escape_string($_POST['last_name']  ?? ''));
        $birthday       = trim($conn->real_escape_string($_POST['birthday']   ?? ''));
        $course         = trim($conn->real_escape_string($_POST['course']     ?? ''));
        $year_level     = trim($conn->real_escape_string($_POST['year_level'] ?? ''));
        $section        = trim($conn->real_escape_string($_POST['section']    ?? ''));
        $phone          = trim($conn->real_escape_string($_POST['phone']      ?? ''));
        $status         = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($id <= 0 || !$first_name || !$last_name || !$birthday || !$course || !$year_level || !$section || !$phone) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']); break;
        }
        $snum_sql = $student_number ? "student_number='$student_number'," : "";
        $sql = "UPDATE students SET {$snum_sql} first_name='$first_name',last_name='$last_name',birthday='$birthday',
                course='$course',year_level='$year_level',section='$section',phone='$phone',status='$status'
                WHERE id=$id";
        echo $conn->query($sql)
            ? json_encode(['success' => true, 'message' => 'Student updated.'])
            : json_encode(['success' => false, 'message' => $conn->error]);
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); break; }
        echo $conn->query("DELETE FROM students WHERE id = $id")
            ? json_encode(['success' => true, 'message' => 'Student deleted.'])
            : json_encode(['success' => false, 'message' => $conn->error]);
        break;

    case 'bulk_delete':
        $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
        if (empty($ids)) { echo json_encode(['success' => false, 'message' => 'No IDs provided.']); break; }
        $list = implode(',', $ids);
        echo $conn->query("DELETE FROM students WHERE id IN ($list)")
            ? json_encode(['success' => true, 'message' => count($ids) . ' student(s) deleted.'])
            : json_encode(['success' => false, 'message' => $conn->error]);
        break;

    case 'bulk_status':
        $ids    = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
        $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        if (empty($ids)) { echo json_encode(['success' => false, 'message' => 'No IDs provided.']); break; }
        $list = implode(',', $ids);
        echo $conn->query("UPDATE students SET status='$status' WHERE id IN ($list)")
            ? json_encode(['success' => true, 'message' => count($ids) . " student(s) set to $status."])
            : json_encode(['success' => false, 'message' => $conn->error]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
}

$conn->close();
?>
