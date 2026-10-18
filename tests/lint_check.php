<?php
$files = [
    'index.php',
    'routes/web.php',
    'routes/api.php',
    'bootstrap/app.php',
    'app/Models/User.php',
    'app/Models/Student.php',
    'app/Models/Club.php',
    'app/Models/ClubMembership.php',
    'app/Models/Event.php',
    'app/Models/BudgetRequest.php',
    'app/Models/Achievement.php',
    'app/Models/OrgAnnouncement.php',
    'app/Models/Notification.php',
    'app/Models/Election.php',
    'app/Models/AuditLog.php',
    'app/Http/Controllers/WebAuthController.php',
    'app/Http/Controllers/DashboardController.php',
    'app/Http/Controllers/Api/AuthController.php',
    'app/Http/Controllers/Api/StudentController.php',
    'app/Http/Controllers/Api/AchievementController.php',
    'app/Http/Middleware/HandleInertiaRequests.php',
    'app/Http/Middleware/SecurityHeadersMiddleware.php',
    'app/shared/budget_actions.php',
    'app/shared/notification_actions.php',
    'app/shared/init_elections.php',
    'app/shared/setup.php',
    'app/shared/reset_data.php',
    'database/seeders/DatabaseSeeder.php',
    'tests/Feature/WebAuthTest.php',
    'tests/Feature/DashboardTest.php',
    'tests/Feature/AuthApiTest.php',
    'tests/Feature/StudentApiTest.php',
    'tests/Feature/AchievementApiTest.php',
    'tests/Feature/ExampleTest.php'
];

$errors = 0;
foreach ($files as $f) {
    if (!file_exists($f)) {
        echo "MISSING: $f\n";
        $errors++;
        continue;
    }
    $output = [];
    $returnVar = 0;
    exec('"C:\\xamppp\\php\\php.exe" -l ' . escapeshellarg($f), $output, $returnVar);
    if ($returnVar !== 0) {
        echo "SYNTAX ERROR in $f:\n" . implode("\n", $output) . "\n";
        $errors++;
    }
}

if ($errors === 0) {
    echo "SUCCESS: All " . count($files) . " PHP files passed syntax checks!\n";
} else {
    echo "FAIL: $errors files had issues.\n";
}
