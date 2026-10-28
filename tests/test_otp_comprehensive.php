<?php
// Comprehensive end-to-end OTP / MFA verification suite
require_once __DIR__ . '/../app/shared/db.php';
require_once __DIR__ . '/../app/shared/mail_helper.php';

echo "============================================================\n";
echo "  BCP CCMS — OTP & MULTI-FACTOR AUTHENTICATION TEST SUITE   \n";
echo "============================================================\n\n";

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] $name\n";
        $passed++;
    } else {
        echo "  [FAIL] $name" . ($detail ? " — $detail" : "") . "\n";
        $failed++;
    }
}

// 1. Check system_settings table has MFA settings
$settings = [];
$res = $conn->query("SELECT setting_key, setting_value FROM system_settings");
while ($r = $res->fetch_assoc()) {
    $settings[$r['setting_key']] = $r['setting_value'];
}
assert_test("1.1 MFA enabled in system settings", ($settings['mfa_enabled'] ?? '') === '1');
assert_test("1.2 MFA expiry set to 5 minutes", ($settings['mfa_expiry_minutes'] ?? '') === '5');
assert_test("1.3 MFA resend cooldown set to 60 seconds", ($settings['mfa_resend_cooldown'] ?? '') === '60');

// 2. Test Student MFA Code Issuance
$std = $conn->query("SELECT id, username, email, first_name, last_name, role FROM users WHERE role = 'student' LIMIT 1")->fetch_assoc();
assert_test("2.1 Student account found ({$std['username']})", !empty($std));
$conn->query("DELETE FROM mfa_codes WHERE user_id = " . (int)$std['id']);
$mfa_std = issue_mfa_code($conn, (int)$std['id'], $std['email'], ($std['first_name'] ?? '') . ' ' . ($std['last_name'] ?? ''), 'login');
assert_test("2.2 Student MFA 6-digit code issued", $mfa_std['success'] && strlen($mfa_std['code']) === 6);

// 3. Test Adviser MFA Code Issuance
$adv = $conn->query("SELECT id, username, email, first_name, last_name, role FROM users WHERE role = 'club_adviser' LIMIT 1")->fetch_assoc();
assert_test("3.1 Adviser account found ({$adv['username']})", !empty($adv));
$conn->query("DELETE FROM mfa_codes WHERE user_id = " . (int)$adv['id']);
$mfa_adv = issue_mfa_code($conn, (int)$adv['id'], $adv['email'], ($adv['first_name'] ?? '') . ' ' . ($adv['last_name'] ?? ''), 'login');
assert_test("3.2 Adviser MFA 6-digit code issued", $mfa_adv['success'] && strlen($mfa_adv['code']) === 6);

// 4. Test SSC Officer MFA Code Issuance
$ssc = $conn->query("SELECT id, username, email, first_name, last_name, role FROM users WHERE role = 'ssc' LIMIT 1")->fetch_assoc();
assert_test("4.1 SSC account found ({$ssc['username']})", !empty($ssc));

// Clear test user mfa_codes to avoid cooldown conflicts between test runs
$conn->query("DELETE FROM mfa_codes WHERE user_id = " . (int)$ssc['id']);

$mfa_ssc = issue_mfa_code($conn, (int)$ssc['id'], $ssc['email'], $ssc['first_name'] . ' ' . $ssc['last_name'], 'login');
assert_test("4.2 SSC MFA 6-digit code issued", $mfa_ssc['success'] && strlen($mfa_ssc['code']) === 6);
assert_test("4.3 SSC Email correctly masked", $mfa_ssc['email_masked'] === mask_email($ssc['email']));
assert_test("4.4 SSC Expiry set to 300s (5m)", $mfa_ssc['expires_in'] === 300);

// 5. Test Admin MFA Code Issuance
$adm = $conn->query("SELECT id, username, email, first_name, last_name, role FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
assert_test("5.1 Admin account found ({$adm['username']})", !empty($adm));

$conn->query("DELETE FROM mfa_codes WHERE user_id = " . (int)$adm['id']);
$mfa_adm = issue_mfa_code($conn, (int)$adm['id'], $adm['email'], $adm['first_name'] . ' ' . $adm['last_name'], 'login');
assert_test("5.2 Admin MFA 6-digit code issued", $mfa_adm['success'] && strlen($mfa_adm['code']) === 6);
assert_test("5.3 Admin Email correctly masked", $mfa_adm['email_masked'] === mask_email($adm['email']));

// 6. Test Resend Cooldown Enforcement
$mfa_resend_blocked = issue_mfa_code($conn, (int)$adm['id'], $adm['email'], 'Admin', 'login');
assert_test("6.1 Resend cooldown enforced when immediate re-request attempted", !$mfa_resend_blocked['success'] && ($mfa_resend_blocked['cooldown'] ?? false) === true);

// 7. Test Verification: Invalid 6-digit Code Attempt
$verify_wrong = verify_mfa_code($conn, (int)$adm['id'], '000000', 'login');
assert_test("7.1 Invalid code rejected with remaining attempt count", !$verify_wrong['success'] && ($verify_wrong['remaining'] ?? 0) === 4);

// 8. Test Verification: Malformed Code Attempt
$verify_malformed = verify_mfa_code($conn, (int)$adm['id'], 'abc12', 'login');
assert_test("8.1 Malformed non-numeric/short code rejected", !$verify_malformed['success']);

// 9. Test Verification: Valid Code Success
$verify_correct = verify_mfa_code($conn, (int)$adm['id'], $mfa_adm['code'], 'login');
assert_test("9.1 Correct 6-digit code verified successfully", $verify_correct['success'] === true);

// 10. Test Used Code Cannot Be Reused (Replay Attack Prevention)
$verify_reused = verify_mfa_code($conn, (int)$adm['id'], $mfa_adm['code'], 'login');
assert_test("10.1 Used code cannot be reused (Replay attack prevention)", !$verify_reused['success']);

// 11. Test Database State for MFA Codes Table
$chk_mfa_row = $conn->query("SELECT is_used, attempts FROM mfa_codes WHERE id = {$mfa_adm['code_id']}")->fetch_assoc();
assert_test("11.1 MFA record in database marked as is_used = 1", (int)$chk_mfa_row['is_used'] === 1);

echo "\n------------------------------------------------------------\n";
echo "SUMMARY: Passed: $passed, Failed: $failed\n";
echo "============================================================\n";

if ($failed > 0) {
    exit(1);
}
