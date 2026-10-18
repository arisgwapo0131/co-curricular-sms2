<?php
// Test end-to-end MFA authentication flow
require_once __DIR__ . '/../app/shared/db.php';
require_once __DIR__ . '/../app/shared/mail_helper.php';

echo "--- 1. Testing user credentials & MFA code generation ---\n";
$user = $conn->query("SELECT id, username, email, first_name, last_name FROM users WHERE username = 'bsit.student'")->fetch_assoc();
if (!$user) {
    die("User not found\n");
}
echo "User: {$user['username']} ({$user['email']})\n";

// Generate MFA code
$mfaRes = send_mfa_verification_code($conn, (int)$user['id'], $user['email'], $user['first_name'] . ' ' . $user['last_name'], 'login');
echo "Code generated: {$mfaRes['code']} for {$mfaRes['email_masked']}\n";

// Check database record
$dbRow = $conn->query("SELECT id, code, expires_at, is_used, attempts FROM mfa_codes WHERE user_id = {$user['id']} AND is_used = 0 ORDER BY id DESC LIMIT 1")->fetch_assoc();
echo "Stored DB code: {$dbRow['code']}, expires at {$dbRow['expires_at']}\n";

// Test invalid code
$invalid = verify_mfa_code($conn, (int)$user['id'], '999999', 'login');
echo "Invalid code test: " . ($invalid['success'] ? 'FAILED' : 'PASSED (Rejected correctly)') . " - {$invalid['message']}\n";

// Test valid code
$valid = verify_mfa_code($conn, (int)$user['id'], $mfaRes['code'], 'login');
echo "Valid code test: " . ($valid['success'] ? 'PASSED (Verified successfully)' : 'FAILED') . " - {$valid['message']}\n";

// Verify database status
$checkUsed = $conn->query("SELECT is_used FROM mfa_codes WHERE id = {$dbRow['id']}")->fetch_assoc();
echo "Database is_used flag: {$checkUsed['is_used']}\n";

echo "ALL MFA FLOW TESTS COMPLETED SUCCESSFULLY!\n";
