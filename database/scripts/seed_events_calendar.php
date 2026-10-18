<?php
require_once __DIR__ . '/../../app/shared/db.php';

// Seed authentic events if table is empty or has fewer than 5 events
$chk = $conn->query("SELECT COUNT(*) AS c FROM `events`");
$count = $chk ? (int)$chk->fetch_assoc()['c'] : 0;

echo "Current events count: $count\n";

if ($count < 5) {
    // Check available clubs
    $clubs_res = $conn->query("SELECT id, code FROM clubs ORDER BY id");
    $clubs_map = [];
    while ($r = $clubs_res->fetch_assoc()) {
        $clubs_map[$r['code']] = (int)$r['id'];
    }

    // Default creator users
    $admin_id = 59;
    $ssc_id = 58;
    $adviser_user_id = 17;

    $events_to_seed = [
        [
            'club_id'            => $clubs_map['CSSEC'] ?? 1,
            'event_type'         => 'Club',
            'title'              => 'Annual Tech Symposium & Innovation Expo 2026',
            'description'        => 'Annual technology summit bringing together industry speakers, artificial intelligence showcases, cloud workshops, and collaborative tech exhibits.',
            'event_date'         => '2026-09-18 09:00:00',
            'venue'              => 'Main Auditorium',
            'expected_attendees' => 280,
            'status'             => 'Completed',
            'created_by'         => $adviser_user_id,
            'endorsement_notes'  => 'Endorsed by SSC: Meets institutional academic standards. Clearance issued by Administration.'
        ],
        [
            'club_id'            => $clubs_map['CSSEC'] ?? 1,
            'event_type'         => 'Club',
            'title'              => 'BCP Inter-College Hackathon & Code Fest',
            'description'        => '24-hour collegiate software hackathon focusing on AI solutions for community development, smart campus utilities, and algorithmic problem-solving.',
            'event_date'         => '2026-09-30 08:30:00',
            'venue'              => 'IT Laboratory 3',
            'expected_attendees' => 120,
            'status'             => 'Approved',
            'created_by'         => $adviser_user_id,
            'endorsement_notes'  => 'Endorsed by SSC: Complete security and lab protocols approved.'
        ],
        [
            'club_id'            => NULL,
            'event_type'         => 'Institutional',
            'title'              => 'Campus Leadership Summit & Officer Synergy Forum',
            'description'        => 'Mandatory university-wide leadership development summit for all recognized student organization officers, council delegates, and faculty advisers.',
            'event_date'         => '2026-10-05 09:00:00',
            'venue'              => 'Bulwagang Balagtas',
            'expected_attendees' => 350,
            'status'             => 'Approved',
            'created_by'         => $ssc_id,
            'endorsement_notes'  => 'Institutional Council Event: Fully endorsed by SSC and authorized by Office of Student Affairs.'
        ],
        [
            'club_id'            => $clubs_map['ALL STAR'] ?? 30,
            'event_type'         => 'Club',
            'title'              => 'Philippine Cultural Heritage & Folk Dance Showcase',
            'description'        => 'Live performing arts festival presenting traditional Philippine folk dances, indigenous musical heritage, and theatrical expressions.',
            'event_date'         => '2026-10-12 13:00:00',
            'venue'              => 'Campus Plaza',
            'expected_attendees' => 200,
            'status'             => 'Approved',
            'created_by'         => $adviser_user_id,
            'endorsement_notes'  => 'Endorsed by SSC: Sound permits and outdoor pavilion reservation verified.'
        ],
        [
            'club_id'            => $clubs_map['RCYC-BCP'] ?? 28,
            'event_type'         => 'Club',
            'title'              => 'Red Cross Emergency First Aid & Voluntary Blood Drive',
            'description'        => 'Campus-wide voluntary blood donation drive and certified emergency disaster response workshop conducted in coordination with the Philippine Red Cross.',
            'event_date'         => '2026-10-18 08:00:00',
            'venue'              => 'Health Services Center',
            'expected_attendees' => 160,
            'status'             => 'Approved',
            'created_by'         => $adviser_user_id,
            'endorsement_notes'  => 'Endorsed by SSC: Medical staff and sanitation protocols arranged.'
        ],
        [
            'club_id'            => $clubs_map['CESC'] ?? 26,
            'event_type'         => 'Club',
            'title'              => 'Collegiate Esports Invitational Cup',
            'description'        => 'Inter-department competitive gaming tourney and esports sportsmanship seminar promoting strategic teamwork and digital wellness.',
            'event_date'         => '2026-10-22 10:00:00',
            'venue'              => 'Student Center Gymnasium',
            'expected_attendees' => 220,
            'status'             => 'Pending SSC',
            'created_by'         => $adviser_user_id,
            'endorsement_notes'  => 'Awaiting initial SSC review of network and power requirements.'
        ],
        [
            'club_id'            => $clubs_map['PEER'] ?? 39,
            'event_type'         => 'Club',
            'title'              => 'Youth Mental Health & Peer Counseling Colloquium',
            'description'        => 'Empowering students through psychological first-aid training, emotional resilience seminars, and peer-to-peer counseling networks.',
            'event_date'         => '2026-10-26 13:30:00',
            'venue'              => 'Audio Visual Theater',
            'expected_attendees' => 180,
            'status'             => 'Pending Admin',
            'created_by'         => $adviser_user_id,
            'endorsement_notes'  => 'Endorsed by SSC: Approved for final Administrative calendar posting.'
        ],
        [
            'club_id'            => $clubs_map['TECHs'] ?? 22,
            'event_type'         => 'Club',
            'title'              => 'Hospitality & Culinary Arts Skills Championship',
            'description'        => 'Live culinary cooking challenge, table setup exhibition, and front-of-house hospitality competitive showcase.',
            'event_date'         => '2026-11-06 09:00:00',
            'venue'              => 'Culinary Arts Pavilion',
            'expected_attendees' => 200,
            'status'             => 'Approved',
            'created_by'         => $adviser_user_id,
            'endorsement_notes'  => 'Endorsed by SSC: Kitchen safety and food safety compliance endorsed.'
        ],
        [
            'club_id'            => $clubs_map['EYO'] ?? 8,
            'event_type'         => 'Club',
            'title'              => 'Startup Incubation & Young Entrepreneurs Fair',
            'description'        => 'Student business venture exposition featuring student-led commercial stalls, marketing pitches, and sustainable product showcases.',
            'event_date'         => '2026-11-14 09:00:00',
            'venue'              => 'Central Quadrangle',
            'expected_attendees' => 400,
            'status'             => 'Approved',
            'created_by'         => $adviser_user_id,
            'endorsement_notes'  => 'Endorsed by SSC: Trade permits and quadrangle booth layouts validated.'
        ]
    ];

    $stmt = $conn->prepare("
        INSERT INTO `events` 
        (`club_id`, `event_type`, `title`, `description`, `event_date`, `venue`, `expected_attendees`, `status`, `created_by`, `endorsement_notes`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($events_to_seed as $ev) {
        $stmt->bind_param(
            'isssssisis',
            $ev['club_id'],
            $ev['event_type'],
            $ev['title'],
            $ev['description'],
            $ev['event_date'],
            $ev['venue'],
            $ev['expected_attendees'],
            $ev['status'],
            $ev['created_by'],
            $ev['endorsement_notes']
        );
        $stmt->execute();
        echo "Seeded event: " . $ev['title'] . " (ID: " . $stmt->insert_id . ")\n";
    }
    $stmt->close();

    // Also register student user #1 to some approved events
    $conn->query("
        INSERT IGNORE INTO `event_registrations` (`event_id`, `user_id`, `status`)
        SELECT id, 1, 'Registered' FROM `events` WHERE `status` = 'Approved' LIMIT 3
    ");
    echo "Seeded sample event registrations for student.\n";
}

echo "Events seeding check completed successfully.\n";
