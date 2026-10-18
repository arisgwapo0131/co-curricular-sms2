<?php
require_once __DIR__ . '/../shared/security.php';
require_auth();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Loading – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/loading.css" />
</head>
<body>
  <img class="bcp-logo" src="../images/BCP_LOGO.png" alt="Bestlink College of the Philippines Logo" />
  <p class="welcome">Magandang Buhay BCPian!</p>
  <p class="wait">
    Please wait
    <span class="dots"><span></span><span></span><span></span></span>
  </p>
  <script>
    try {
      localStorage.removeItem('bcp_sms_session_expired');
      localStorage.setItem('bcp_sms_session_active', Date.now().toString());
    } catch (e) {}
    setTimeout(function () {
      window.location.href = 'dashboard.php';
    }, 1200);
  </script>
</body>
</html>
