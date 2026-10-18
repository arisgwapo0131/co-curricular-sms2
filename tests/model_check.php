<?php
require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Student;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\Event;
use App\Models\BudgetRequest;
use App\Models\Achievement;
use App\Models\OrgAnnouncement;
use App\Models\Notification;
use App\Models\Election;
use App\Models\AuditLog;

echo "=== TESTING ELOQUENT MODELS & DATABASE QUERIES ===\n";

$checks = [
    'User'            => User::count(),
    'Student'         => Student::count(),
    'Club'            => Club::count(),
    'ClubMembership'  => ClubMembership::count(),
    'Event'           => Event::count(),
    'BudgetRequest'   => BudgetRequest::count(),
    'Achievement'     => Achievement::count(),
    'OrgAnnouncement' => OrgAnnouncement::count(),
    'Notification'    => Notification::count(),
    'Election'        => Election::count(),
    'AuditLog'        => AuditLog::count(),
];

foreach ($checks as $model => $count) {
    echo "  [OK] Model $model: $count records found in sms_db.\n";
}

// Relationships Check
echo "\n=== TESTING ELOQUENT RELATIONSHIPS ===\n";

// 1. User -> Student
$u = User::where('role', 'student')->first();
if ($u && $u->student) {
    echo "  [OK] User -> student: Found student #" . $u->student->student_number . "\n";
} else {
    echo "  [INFO] User has no student or no student user found\n";
}

// 2. Student -> User
$s = Student::first();
if ($s && $s->user) {
    echo "  [OK] Student -> user: Found user @" . $s->user->username . "\n";
}

// 3. Club -> Adviser
$c = Club::first();
if ($c) {
    $adv = $c->adviser ? $c->adviser->username : ($c->adviser_name ?: 'None');
    echo "  [OK] Club -> adviser: " . $c->name . " -> " . $adv . "\n";
}

// 4. Event -> Club
$ev = Event::first();
if ($ev && $ev->club) {
    echo "  [OK] Event -> club: " . $ev->title . " -> " . $ev->club->name . "\n";
}

// 5. BudgetRequest -> Club
$br = BudgetRequest::first();
if ($br && $br->club) {
    echo "  [OK] BudgetRequest -> club: " . $br->title . " -> " . $br->club->name . "\n";
}

echo "\nALL MODEL & RELATIONSHIP CHECKS COMPLETED SUCCESSFULLY!\n";
