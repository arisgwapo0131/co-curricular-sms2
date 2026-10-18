<?php
// ============================================================
//  REGISTER.PHP  (auth/)
//  Self-registration is disabled; all accounts are pre-provisioned.
//  Direct all users to official authentication sign-in.
// ============================================================
session_start();
header('Location: signin.php');
exit;
