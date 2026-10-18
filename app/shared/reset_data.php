<?php
// ============================================================
//  RESET_DATA.PHP — Clear Demo Data & Seed Real Test Accounts
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

// Security Guard: Only allow CLI execution or authenticated System Administrator
$is_cli = (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']));
if (!$is_cli) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (empty($_SESSION['user_id']) || (($_SESSION['real_role'] ?? $_SESSION['role'] ?? '') !== 'admin')) {
        http_response_code(403);
        die("<h3>403 Forbidden</h3><p>Access denied. Database re-seeding is restricted to System Administrators.</p>");
    }
}

// Disable foreign key checks for clean truncation
$conn->query("SET FOREIGN_KEY_CHECKS = 0;");

$tables_to_truncate = [
    'events',
    'event_registrations',
    'budget_requests',
    'achievements',
    'attendance_logs',
    'notifications',
    'audit_logs',
    'elections',
    'election_candidates',
    'election_votes',
    'org_announcements',
    'club_memberships',
    'club_applications',
];

$truncated = [];
foreach ($tables_to_truncate as $table) {
    if ($conn->query("TRUNCATE TABLE $table")) {
        $truncated[] = $table;
    } else {
        $conn->query("DELETE FROM $table");
        $conn->query("ALTER TABLE $table AUTO_INCREMENT = 1");
        $truncated[] = $table;
    }
}

// Delete all existing users and reset auto-increment
$conn->query("DELETE FROM users");
$conn->query("ALTER TABLE users AUTO_INCREMENT = 1");

// Configurable passwords via environment variables with default fallback
$admin_plain = getenv('ADMIN_DEFAULT_PASSWORD') ?: ($_ENV['ADMIN_DEFAULT_PASSWORD'] ?? 'Bcp@Admin2026!');
$ssc_plain   = getenv('SSC_DEFAULT_PASSWORD')   ?: ($_ENV['SSC_DEFAULT_PASSWORD']   ?? 'Bcp@SSC2026!');
$std_plain   = getenv('STUDENT_DEFAULT_PASSWORD')?: ($_ENV['STUDENT_DEFAULT_PASSWORD'] ?? 'Bcp@Test2026!');
$adv_plain   = getenv('ADVISER_DEFAULT_PASSWORD')?: ($_ENV['ADVISER_DEFAULT_PASSWORD'] ?? 'Bcp@Adviser2026!');

$std_hash  = password_hash($std_plain,   PASSWORD_DEFAULT);
$adv_hash  = password_hash($adv_plain,   PASSWORD_DEFAULT);
$ssc_hash  = password_hash($ssc_plain,   PASSWORD_DEFAULT);
$adm_hash  = password_hash($admin_plain, PASSWORD_DEFAULT);

$ins = $conn->prepare("INSERT INTO users (username, email, first_name, last_name, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)");

// 16 STUDENT ACCOUNTS (one per Bestlink program)
$student_accounts = [
    // [username, email, first, last, hash, role, course, year, section]
    ['bsit.student',    'bsit@student.bcp.edu.ph',     'Juan',      'Santos',     $std_hash, 'student', 'Bachelor of Science in Information Technology',           '2nd Year', 'IT-2A'],
    ['bshm.student',    'bshm@student.bcp.edu.ph',     'Maria',     'Cruz',       $std_hash, 'student', 'Bachelor of Science in Hospitality Management',           '1st Year', 'HM-1B'],
    ['bsais.student',   'bsais@student.bcp.edu.ph',    'Jose',      'Reyes',      $std_hash, 'student', 'Bachelor of Science in Accounting Information System',    '3rd Year', 'AIS-3A'],
    ['bstm.student',    'bstm@student.bcp.edu.ph',     'Ana',       'Dela Cruz',  $std_hash, 'student', 'Bachelor of Science in Tourism Management',               '2nd Year', 'TM-2C'],
    ['bsoa.student',    'bsoa@student.bcp.edu.ph',     'Carlos',    'Garcia',     $std_hash, 'student', 'Bachelor of Science in Office Administration',            '1st Year', 'OA-1A'],
    ['bse.student',     'bse@student.bcp.edu.ph',      'Liza',      'Ramos',      $std_hash, 'student', 'Bachelor of Science in Entrepreneurship',                 '3rd Year', 'ENT-3B'],
    ['bsba.student',    'bsba@student.bcp.edu.ph',     'Ramon',     'Villanueva', $std_hash, 'student', 'Bachelor of Science in Business Administration',          '2nd Year', 'BA-2A'],
    ['bsis.student',    'bsis@student.bcp.edu.ph',     'Patricia',  'Aquino',     $std_hash, 'student', 'Bachelor of Science in Information Science',              '1st Year', 'IS-1A'],
    ['bscpe.student',   'bscpe@student.bcp.edu.ph',    'Mark',      'Bautista',   $std_hash, 'student', 'Bachelor of Science in Computer Engineering',             '3rd Year', 'CPE-3A'],
    ['bspsych.student', 'bspsych@student.bcp.edu.ph',  'Jenny',     'Navarro',    $std_hash, 'student', 'Bachelor of Science in Psychology',                       '2nd Year', 'PSY-2B'],
    ['bscrim.student',  'bscrim@student.bcp.edu.ph',   'Rico',      'Fernandez',  $std_hash, 'student', 'Bachelor of Science in Criminology',                      '4th Year', 'CRIM-4A'],
    ['bspe.student',    'bspe@student.bcp.edu.ph',     'Sheila',    'Santos',     $std_hash, 'student', 'Bachelor of Science in Physical Education',               '2nd Year', 'PE-2A'],
    ['tle.student',     'tle@student.bcp.edu.ph',      'Angelo',    'Torres',     $std_hash, 'student', 'Technological and Livelihood Education',                  '1st Year', 'TLE-1B'],
    ['bseled.student',  'bseled@student.bcp.edu.ph',   'Claire',    'Mendoza',    $std_hash, 'student', 'Bachelor of Science in Elementary Education',             '3rd Year', 'ELED-3A'],
    ['bsseed.student',  'bsseed@student.bcp.edu.ph',   'Danilo',    'Pascual',    $std_hash, 'student', 'Bachelor of Science in Secondary Education',              '2nd Year', 'SEED-2C'],
    ['bslis.student',   'bslis@student.bcp.edu.ph',    'Rowena',    'Espinosa',   $std_hash, 'student', 'Bachelor of Science in Library Information Science',      '3rd Year', 'LIS-3A'],
];

// 40 ADVISER ACCOUNTS (one per registered organization)
$adviser_accounts = [
    // [username, email, first, last, hash, role, club_id]
    ['cssec.adviser',    'cssec@adviser.bcp.edu.ph',    'Alex',    'Reyes',    $adv_hash, 'club_adviser', 1],
    ['acads.adviser',    'acads@adviser.bcp.edu.ph',    'Mark',    'Velo',     $adv_hash, 'club_adviser', 2],
    ['aces.adviser',     'aces@adviser.bcp.edu.ph',     'Elena',   'Ramos',    $adv_hash, 'club_adviser', 3],
    ['aiss.adviser',     'aiss@adviser.bcp.edu.ph',     'Clara',   'Tan',      $adv_hash, 'club_adviser', 4],
    ['bliss.adviser',    'bliss@adviser.bcp.edu.ph',    'Robert',  'Cruz',     $adv_hash, 'club_adviser', 5],
    ['brave.adviser',    'brave@adviser.bcp.edu.ph',    'Diana',   'Gomez',    $adv_hash, 'club_adviser', 6],
    ['cjsu.adviser',     'cjsu@adviser.bcp.edu.ph',     'Jose',    'Mercado',  $adv_hash, 'club_adviser', 7],
    ['eyo.adviser',      'eyo@adviser.bcp.edu.ph',      'Lisa',    'Santos',   $adv_hash, 'club_adviser', 8],
    ['galaw.adviser',    'galaw@adviser.bcp.edu.ph',    'Manuel',  'Cruz',     $adv_hash, 'club_adviser', 9],
    ['gems.adviser',     'gems@adviser.bcp.edu.ph',     'Anna',    'Reyes',    $adv_hash, 'club_adviser', 10],
    ['gold.adviser',     'gold@adviser.bcp.edu.ph',     'Karen',   'Lim',      $adv_hash, 'club_adviser', 11],
    ['jfinex.adviser',   'jfinex@adviser.bcp.edu.ph',   'Manuel',  'Cruz',     $adv_hash, 'club_adviser', 12],
    ['hrs.adviser',      'hrs@adviser.bcp.edu.ph',      'Alex',    'Reyes',    $adv_hash, 'club_adviser', 13],
    ['jma.adviser',      'jma@adviser.bcp.edu.ph',      'Sarah',   'Mercado',  $adv_hash, 'club_adviser', 14],
    ['lakas.adviser',    'lakas@adviser.bcp.edu.ph',    'Clara',   'Tan',      $adv_hash, 'club_adviser', 15],
    ['lapis.adviser',    'lapis@adviser.bcp.edu.ph',    'Diana',   'Gomez',    $adv_hash, 'club_adviser', 16],
    ['libro.adviser',    'libro@adviser.bcp.edu.ph',    'Robert',  'Cruz',     $adv_hash, 'club_adviser', 17],
    ['omega.adviser',    'omega@adviser.bcp.edu.ph',    'Jose',    'Mercado',  $adv_hash, 'club_adviser', 18],
    ['psychsoc.adviser', 'psychsoc@adviser.bcp.edu.ph', 'Lisa',    'Santos',   $adv_hash, 'club_adviser', 19],
    ['rsd.adviser',      'rsd@adviser.bcp.edu.ph',      'Anna',    'Reyes',    $adv_hash, 'club_adviser', 20],
    ['sigma.adviser',    'sigma@adviser.bcp.edu.ph',    'Karen',   'Lim',      $adv_hash, 'club_adviser', 21],
    ['techs.adviser',    'techs@adviser.bcp.edu.ph',    'Alex',    'Reyes',    $adv_hash, 'club_adviser', 22],
    ['tts.adviser',      'tts@adviser.bcp.edu.ph',      'Sarah',   'Mercado',  $adv_hash, 'club_adviser', 23],
    ['wika.adviser',     'wika@adviser.bcp.edu.ph',     'Clara',   'Tan',      $adv_hash, 'club_adviser', 24],
    ['acac.adviser',     'acac@adviser.bcp.edu.ph',     'Sarah',   'Mercado',  $adv_hash, 'club_adviser', 25],
    ['cesc.adviser',     'cesc@adviser.bcp.edu.ph',     'Mark',    'Velo',     $adv_hash, 'club_adviser', 26],
    ['ebcpct.adviser',   'ebcpct@adviser.bcp.edu.ph',   'Mark',    'Velo',     $adv_hash, 'club_adviser', 27],
    ['rcyc.adviser',     'rcyc@adviser.bcp.edu.ph',     'Elena',   'Cruz',     $adv_hash, 'club_adviser', 28],
    ['smc.adviser',      'smc@adviser.bcp.edu.ph',      'Mark',    'Velo',     $adv_hash, 'club_adviser', 29],
    ['allstar.adviser',  'allstar@adviser.bcp.edu.ph',  'Sarah',   'Mercado',  $adv_hash, 'club_adviser', 30],
    ['bforce.adviser',   'bforce@adviser.bcp.edu.ph',   'Sarah',   'Mercado',  $adv_hash, 'club_adviser', 31],
    ['creative.adviser', 'creative@adviser.bcp.edu.ph', 'Sarah',   'Mercado',  $adv_hash, 'club_adviser', 32],
    ['cdc.adviser',      'cdc@adviser.bcp.edu.ph',      'Sarah',   'Mercado',  $adv_hash, 'club_adviser', 33],
    ['dlc.adviser',      'dlc@adviser.bcp.edu.ph',      'Sarah',   'Mercado',  $adv_hash, 'club_adviser', 34],
    ['ikatlong.adviser', 'ikatlong@adviser.bcp.edu.ph', 'Sarah',   'Mercado',  $adv_hash, 'club_adviser', 35],
    ['image.adviser',    'image@adviser.bcp.edu.ph',    'Sarah',   'Mercado',  $adv_hash, 'club_adviser', 36],
    ['sikat.adviser',    'sikat@adviser.bcp.edu.ph',    'Sarah',   'Mercado',  $adv_hash, 'club_adviser', 37],
    ['uv.adviser',       'uv@adviser.bcp.edu.ph',       'Sarah',   'Mercado',  $adv_hash, 'club_adviser', 38],
    ['peer.adviser',     'peer@adviser.bcp.edu.ph',     'Elena',   'Cruz',     $adv_hash, 'club_adviser', 39],
    ['newslink.adviser', 'newslink@adviser.bcp.edu.ph', 'Elena',   'Cruz',     $adv_hash, 'club_adviser', 40],
    ['gadcg.adviser',    'gadcg@adviser.bcp.edu.ph',    'Elena',   'Cruz',     $adv_hash, 'club_adviser', 41],
];

// Insert all student accounts
foreach ($student_accounts as $s) {
    [$un, $em, $fn, $ln, $pw, $rl] = $s;
    $ins->bind_param('ssssss', $un, $em, $fn, $ln, $pw, $rl);
    $ins->execute();
}
// Insert all adviser accounts
foreach ($adviser_accounts as $a) {
    [$un, $em, $fn, $ln, $pw, $rl] = array_slice($a, 0, 6);
    $ins->bind_param('ssssss', $un, $em, $fn, $ln, $pw, $rl);
    $ins->execute();
}
// Insert SSC officer and Admin
$ssc_un = 'ssc.officer'; $ssc_em = 'pangilinanarisguillersaret@gmail.com'; $ssc_fn = 'SSC';    $ssc_ln = 'Officer'; $ssc_rl = 'ssc';
$adm_un = 'scc.admin';   $adm_em = 'pangilinanarisguillersaret@gmail.com'; $adm_fn = 'System'; $adm_ln = 'Admin';   $adm_rl = 'admin';
$ins->bind_param('ssssss', $ssc_un, $ssc_em, $ssc_fn, $ssc_ln, $ssc_hash, $ssc_rl);
$ins->execute();
$ins->bind_param('ssssss', $adm_un, $adm_em, $adm_fn, $adm_ln, $adm_hash, $adm_rl);
$ins->execute();
$ins->close();

// Ensure core clubs exist in `clubs` table
$conn->query("TRUNCATE TABLE clubs");

$seed_clubs = [
    [1, 'CSSEC', 'Computer Science Student Executive Council', 'Academic', 'Official organization for IT students focusing on technical skill-building and innovation.', 'Prof. Alex Reyes'],
    [2, 'ACADS', 'Association of Computer Engineering Academic Driven Students', 'Academic', 'ACADS is dedicated to academic excellence among Computer Engineering students through seminars, competitions, and peer mentoring programs.', 'Prof. Mark Velo'],
    [3, 'ACES', 'Association of Computer Engineering Students', 'Academic', 'ACES unites Computer Engineering students and promotes technical growth through workshops, laboratory enhancement campaigns, and industry visits.', 'Prof. Elena Ramos'],
    [4, 'AISS', 'Accounting Information System Society', 'Academic', 'AISS advances accounting information literacy and bridges academic knowledge with industry technology practices in financial systems.', 'Prof. Clara Tan'],
    [5, 'BLISS', 'Bestlink Library and Information Science Society', 'Academic', 'BLISS promotes library science excellence and information literacy across all BCP academic departments through advocacy and community service.', 'Prof. Robert Cruz'],
    [6, 'BRAVE', 'Building Responsibility and Accountability Through Values Education', 'Academic', 'BRAVE fosters values education and responsible leadership among BCP students through community outreach and character formation programs.', 'Prof. Diana Gomez'],
    [7, 'CJSU', 'Criminal Justice Student Unit', 'Academic', 'CJSU strengthens criminal justice students through moot courts, criminology seminars, and law enforcement immersion programs.', 'Prof. Jose Mercado'],
    [8, 'EYO', 'Entrepreyouth Organization', 'Academic', 'EYO cultivates entrepreneurial mindsets among BCP youth through business incubation workshops, trade fairs, and start-up mentorship.', 'Prof. Lisa Santos'],
    [9, 'G.A.L.A.W', 'Group of Athletes and Leaders Association for Wellness', 'Academic', 'Group of Athletes and Leaders Association for Wellness.', 'Prof. Manuel Cruz'],
    [10, 'GEMs', 'Guild of English Majors', 'Academic', 'English language literacy and debate.', 'Prof. Anna Reyes'],
    [11, 'GOLD', 'Guild of Officers to Lead Development', 'Academic', 'Leadership and management seminars.', 'Prof. Karen Lim'],
    [12, 'JFINEX', 'Junior Financial Executives', 'Academic', 'Financial literacy and investment seminars.', 'Prof. Manuel Cruz'],
    [13, 'HRS', 'Human Resources Society', 'Academic', 'Human resources development.', 'Prof. Alex Reyes'],
    [14, 'J.M.A', 'Junior Marketing Association', 'Academic', 'Marketing strategy and branding.', 'Prof. Sarah Mercado'],
    [15, 'LAKAS', 'Liga ng mga Aktibong Kabataan sa Araling Panlipunan', 'Academic', 'Social studies and leadership development.', 'Prof. Clara Tan'],
    [16, 'L.A.P.I.S', 'Leadership Association Program Including Services', 'Academic', 'Leadership and community service.', 'Prof. Diana Gomez'],
    [17, 'LIBRO', 'Lucid of Bright and Righteous Officers', 'Academic', 'Library science development.', 'Prof. Robert Cruz'],
    [18, 'OMEGA', 'Organization for Mathematics in Engineering for Global Application', 'Academic', 'Engineering mathematics application.', 'Prof. Jose Mercado'],
    [19, 'PsychSoc', 'Psychology Society', 'Academic', 'Psychology student growth and services.', 'Prof. Lisa Santos'],
    [20, 'RSD', 'Regnum Scientiae Discipulus', 'Academic', 'Science major student development.', 'Prof. Anna Reyes'],
    [21, 'SIGMA', 'Students Interactive Guild for Mathematics Major', 'Academic', 'Mathematics exploration and peer tutoring.', 'Prof. Karen Lim'],
    [22, 'TECHs', 'Technology, Exploratory, Creativity and Hospitality Skills', 'Academic', 'Technology and hospitality skills development.', 'Prof. Alex Reyes'],
    [23, 'TTS', 'Tourism Student Society', 'Academic', 'Tourism industry training.', 'Prof. Sarah Mercado'],
    [24, 'WIKA', 'Wikang Filipino Instrumento sa Kaunlarang Akademya', 'Academic', 'Filipino language promotion.', 'Prof. Clara Tan'],
    
    // Talent / Cultural
    [25, 'ACAC', 'Association of Cultural Art Club', 'Cultural', 'Cultural arts representation.', 'Prof. Sarah Mercado'],
    [26, 'CESC', 'Computer Engineering Sports Club', 'Sports', 'Computer Engineering sports events.', 'Prof. Mark Velo'],
    [27, 'EBCPCT', 'Elite BCP Chess Team', 'Sports', 'Elite chess training.', 'Prof. Mark Velo'],
    [28, 'RCYC-BCP', 'Red Cross Youth Council – BCP Chapter', 'Sports', 'Red cross youth operations.', 'Dr. Elena Cruz'],
    [29, 'SMC', 'Shuttle Master Club', 'Sports', 'Badminton training.', 'Prof. Mark Velo'],
    [30, 'ALL STAR', 'All Star', 'Cultural', 'Talent group.', 'Prof. Sarah Mercado'],
    [31, 'B-FORCE', 'B-Force', 'Cultural', 'Hip-hop and dance crew.', 'Prof. Sarah Mercado'],
    [32, 'CREATIVE', 'Creative Arts', 'Cultural', 'Creative design and arts group.', 'Prof. Sarah Mercado'],
    [33, 'CDC', 'Criminology Dance Company', 'Cultural', 'Criminology dance group.', 'Prof. Sarah Mercado'],
    [34, 'DLC', 'Drum and Lyre Corporation', 'Cultural', 'Drum and lyre marching group.', 'Prof. Sarah Mercado'],
    [35, 'IKATLONG', 'Ikatlong Lahi Royalties', 'Cultural', 'Cultural representation.', 'Prof. Sarah Mercado'],
    [36, 'IMAGE', 'Image Alchemy', 'Cultural', 'Photography and media crew.', 'Prof. Sarah Mercado'],
    [37, 'S.I.K.A.T', 'Sining Interpretasyon ng Kabataang Aktor sa Teatro', 'Cultural', 'Theater and acting group.', 'Prof. Sarah Mercado'],
    [38, 'UV', 'Unlimited Voice', 'Cultural', 'Choir and vocal group.', 'Prof. Sarah Mercado'],
    
    // Independent
    [39, 'PEER', 'Peer Counselor', 'Advocacy', 'Student counseling support.', 'Dr. Elena Cruz'],
    [40, 'NEWSLINK', 'Newslink: The School Publications', 'Advocacy', 'Student journalism and school publications.', 'Dr. Elena Cruz'],
    [41, 'GAD-CG', 'Gender and Development – Core Group', 'Advocacy', 'Gender awareness and advocacy.', 'Dr. Elena Cruz']
];

$c_stmt = $conn->prepare("INSERT INTO clubs (id, code, name, category, description, adviser_name, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
foreach ($seed_clubs as [$cid, $ccode, $cname, $ccat, $cdesc, $cadv]) {
    $c_stmt->bind_param('isssss', $cid, $ccode, $cname, $ccat, $cdesc, $cadv);
    $c_stmt->execute();
}
$c_stmt->close();

// Link Adviser accounts to their respective clubs via club_memberships
$mem_stmt = $conn->prepare(
    "INSERT INTO club_memberships (club_id, user_id, role, status) VALUES (?, ?, 'Adviser', 'Active')"
);
foreach ($adviser_accounts as $a) {
    $club_id = (int)$a[6];
    $escaped = $conn->real_escape_string($a[0]);
    $res = $conn->query("SELECT id FROM users WHERE username = '$escaped' LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        $adv_uid = (int)$row['id'];
        $mem_stmt->bind_param('ii', $club_id, $adv_uid);
        $mem_stmt->execute();
    }
}
$mem_stmt->close();

// Seed students table profile data (for dashboard display)
$check_tbl = $conn->query("SHOW TABLES LIKE 'students'");
if ($check_tbl && $check_tbl->num_rows > 0) {
    $conn->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS student_number VARCHAR(50) DEFAULT NULL AFTER id");
    $conn->query("DELETE FROM students");
    $s_ins = $conn->prepare(
        "INSERT INTO students (student_number, first_name, last_name, course, year_level, section, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')"
    );
    $student_profiles = [
        ['2024-10001', 'Juan',     'Santos',     'Bachelor of Science in Information Technology',           '2nd Year', 'IT-2A'],
        ['2024-10002', 'Maria',    'Cruz',       'Bachelor of Science in Hospitality Management',           '1st Year', 'HM-1B'],
        ['2024-10003', 'Jose',     'Reyes',      'Bachelor of Science in Accounting Information System',    '3rd Year', 'AIS-3A'],
        ['2024-10004', 'Ana',      'Dela Cruz',  'Bachelor of Science in Tourism Management',               '2nd Year', 'TM-2C'],
        ['2024-10005', 'Carlos',   'Garcia',     'Bachelor of Science in Office Administration',            '1st Year', 'OA-1A'],
        ['2024-10006', 'Liza',     'Ramos',      'Bachelor of Science in Entrepreneurship',                 '3rd Year', 'ENT-3B'],
        ['2024-10007', 'Ramon',    'Villanueva', 'Bachelor of Science in Business Administration',          '2nd Year', 'BA-2A'],
        ['2024-10008', 'Patricia', 'Aquino',     'Bachelor of Science in Information Science',              '1st Year', 'IS-1A'],
        ['2024-10009', 'Mark',     'Bautista',   'Bachelor of Science in Computer Engineering',             '3rd Year', 'CPE-3A'],
        ['2024-10010', 'Jenny',    'Navarro',    'Bachelor of Science in Psychology',                       '2nd Year', 'PSY-2B'],
        ['2024-10011', 'Rico',     'Fernandez',  'Bachelor of Science in Criminology',                      '4th Year', 'CRIM-4A'],
        ['2024-10012', 'Sheila',   'Santos',     'Bachelor of Science in Physical Education',               '2nd Year', 'PE-2A'],
        ['2024-10013', 'Angelo',   'Torres',     'Technological and Livelihood Education',                  '1st Year', 'TLE-1B'],
        ['2024-10014', 'Claire',   'Mendoza',    'Bachelor of Science in Elementary Education',             '3rd Year', 'ELED-3A'],
        ['2024-10015', 'Danilo',   'Pascual',    'Bachelor of Science in Secondary Education',              '2nd Year', 'SEED-2C'],
        ['2024-10016', 'Rowena',   'Espinosa',   'Bachelor of Science in Library Information Science',      '3rd Year', 'LIS-3A'],
    ];
    foreach ($student_profiles as [$psnum, $pfn, $pln, $pcourse, $pyr, $psec]) {
        $s_ins->bind_param('ssssss', $psnum, $pfn, $pln, $pcourse, $pyr, $psec);
        $s_ins->execute();
    }
    $s_ins->close();
}

// Enable foreign key checks
$conn->query("SET FOREIGN_KEY_CHECKS = 1;");

// Provision & clean sync all 42 organization databases
require_once __DIR__ . '/org_db_manager.php';
provision_all_org_databases($conn);


if (php_sapi_name() === 'cli') {
    echo "SUCCESS: System reset complete.\n";
    echo "  - 16 student accounts (one per Bestlink program)\n";
    echo "    Password: {$std_plain}\n";
    echo "  - 40 adviser accounts (one per registered org)\n";
    echo "    Password: {$adv_plain}\n";
    echo "  - SSC Officer: ssc.officer / {$ssc_plain}\n";
    echo "  - Admin: scc.admin / {$admin_plain}\n";
    echo "  - All transactional tables cleared.\n";
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'success'  => true,
        'message'  => 'System reset complete. Real program-based accounts seeded.',
        'students' => "16 accounts (one per Bestlink program) — Password: {$std_plain}",
        'advisers' => "40 accounts (one per registered org) — Password: {$adv_plain}",
        'ssc'      => "ssc.officer — Password: {$ssc_plain}",
        'admin'    => "scc.admin — Password: {$admin_plain}",
        'cleared_tables' => $truncated,
    ]);
}
$conn->close();
