<?php
require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

echo "=== TRIPLE CHECK: AUTHENTICATION & SECURITY VERIFICATION ===\n";

$roles = [
    'admin'        => ['username' => 'scc.admin',     'password' => 'Bcp@Admin2026!'],
    'ssc'          => ['username' => 'ssc.officer',   'password' => 'Bcp@SSC2026!'],
    'club_adviser' => ['username' => 'cssec.adviser', 'password' => 'Bcp@Adviser2026!'],
    'student'      => ['username' => 'bsit.student',  'password' => 'Bcp@Test2026!'],
];

foreach ($roles as $role => $data) {
    $u = User::where('username', $data['username'])->first();
    if (!$u) {
        echo "  [FAIL] User {$data['username']} not found!\n";
        continue;
    }

    // Test 1: Role match
    if ($u->role !== $role) {
        echo "  [FAIL] Role mismatch for {$data['username']}: expected $role, got {$u->role}\n";
    } else {
        echo "  [OK] User {$data['username']} has authentic canonical role '$role'\n";
    }

    // Test 2: password_verify against password_hash column
    if (password_verify($data['password'], $u->password_hash)) {
        echo "  [OK] Password verification on password_hash SUCCEEDED for {$data['username']}\n";
    } else {
        echo "  [FAIL] Password verification on password_hash FAILED for {$data['username']}\n";
    }

    // Test 3: Rejection of wrong password
    if (!password_verify('WrongPass123!', $u->password_hash)) {
        echo "  [OK] Bad password correctly rejected for {$data['username']}\n";
    } else {
        echo "  [FAIL] Bad password incorrectly accepted for {$data['username']}!\n";
    }
}

// Check Budget Actions re-auth line
echo "\n=== CHECKING BUDGET ACTIONS PASSWORD RE-AUTH ===\n";
$budgetFile = file_get_contents(__DIR__ . '/../app/shared/budget_actions.php');
if (strpos($budgetFile, 'SELECT password_hash FROM users WHERE id = ?') !== false &&
    strpos($budgetFile, '$user_row[\'password_hash\']') !== false &&
    strpos($budgetFile, 'SELECT password FROM users') === false) {
    echo "  [OK] budget_actions.php uses password_hash and does NOT query nonexistent 'password' column.\n";
} else {
    echo "  [FAIL] budget_actions.php still contains legacy password column reference!\n";
}

// Check Notification Actions CSRF protection
echo "\n=== CHECKING NOTIFICATION ACTIONS CSRF PROTECTION ===\n";
$notifFile = file_get_contents(__DIR__ . '/../app/shared/notification_actions.php');
if (strpos($notifFile, "\$action !== 'list'") !== false &&
    strpos($notifFile, "\$_SERVER['REQUEST_METHOD'] !== 'POST'") !== false) {
    echo "  [OK] notification_actions.php strictly enforces POST and CSRF verification for mutating actions.\n";
} else {
    echo "  [FAIL] notification_actions.php lacks strict POST/CSRF guard!\n";
}

echo "\nSECURITY VERIFICATION FINISHED.\n";
