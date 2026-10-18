<?php
// ==============================================================================
//  PH_HOLIDAYS.PHP — Official Philippine Holidays & Academic Calendar Engine
//  Includes Regular Holidays, Special Non-Working Days & Conflict Detection
//  Compliant with Proclamation No. 727 / 368 / 90 & Republic Act No. 9492 & 9849
// ==============================================================================

if (!function_exists('get_ph_holidays')) {

    /**
     * Compute Easter Sunday for a given year using the Anonymous Gregorian algorithm.
     */
    function compute_easter_date(int $year): string {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Return all official Philippine Regular Holidays, Special Non-Working Days,
     * and Academic Blackout Windows for a given year.
     */
    function get_ph_holidays(int $year = 2026): array {
        $holidays = [];

        // ----------------------------------------------------------------------
        // 1. FIXED REGULAR HOLIDAYS (Nationwide Non-Working)
        // ----------------------------------------------------------------------
        $fixed_regular = [
            '01-01' => [
                'name' => "New Year's Day",
                'filipino_name' => "Araw ng Bagong Taon",
                'type' => 'regular',
                'category' => 'Regular Holiday',
                'description' => 'Official nationwide regular holiday celebrating the first day of the year.'
            ],
            '04-09' => [
                'name' => 'Araw ng Kagitingan',
                'filipino_name' => 'Day of Valor',
                'type' => 'regular',
                'category' => 'Regular Holiday',
                'description' => 'Commemorates the heroism of Filipino and American soldiers in the Fall of Bataan (1942).'
            ],
            '05-01' => [
                'name' => 'Labor Day',
                'filipino_name' => 'Araw ng Paggawa',
                'type' => 'regular',
                'category' => 'Regular Holiday',
                'description' => 'National holiday honoring the contributions of the Filipino labor force and workers.'
            ],
            '06-12' => [
                'name' => 'Independence Day',
                'filipino_name' => 'Araw ng Kasarinlan',
                'type' => 'regular',
                'category' => 'Regular Holiday',
                'description' => 'National holiday celebrating Philippine Independence proclaimed in Kawit, Cavite (1898).'
            ],
            '11-30' => [
                'name' => 'Bonifacio Day',
                'filipino_name' => 'Araw ni Bonifacio',
                'type' => 'regular',
                'category' => 'Regular Holiday',
                'description' => 'Commemorates the birth anniversary of Supremo Andres Bonifacio, Father of the Philippine Revolution.'
            ],
            '12-25' => [
                'name' => 'Christmas Day',
                'filipino_name' => 'Araw ng Pasko',
                'type' => 'regular',
                'category' => 'Regular Holiday',
                'description' => 'Major nationwide Christian holiday celebrating the birth of Jesus Christ.'
            ],
            '12-30' => [
                'name' => 'Rizal Day',
                'filipino_name' => 'Araw ng Kabayanihan ni Dr. Jose Rizal',
                'type' => 'regular',
                'category' => 'Regular Holiday',
                'description' => 'National holiday commemorating the martyrdom of Philippine National Hero Dr. Jose Rizal (1896).'
            ],
        ];

        foreach ($fixed_regular as $md => $data) {
            $date = sprintf('%04d-%s', $year, $md);
            $holidays[$date] = array_merge($data, [
                'date' => $date,
                'badge_color' => '#dc2626',
                'badge_bg' => '#fee2e2',
                'is_non_working' => true
            ]);
        }

        // ----------------------------------------------------------------------
        // 2. MOVABLE REGULAR HOLIDAYS (Holy Week & Islamic Holidays)
        // ----------------------------------------------------------------------
        $easter_str = compute_easter_date($year);
        $easter_time = strtotime($easter_str);

        // Maundy Thursday (Holy Thursday: 3 days before Easter)
        $maundy = date('Y-m-d', strtotime('-3 days', $easter_time));
        $holidays[$maundy] = [
            'date' => $maundy,
            'name' => 'Maundy Thursday',
            'filipino_name' => 'Huwebes Santo',
            'type' => 'regular',
            'category' => 'Regular Holiday',
            'description' => 'Holy Week regular holiday observing the Last Supper and Washing of the Feet.',
            'badge_color' => '#dc2626',
            'badge_bg' => '#fee2e2',
            'is_non_working' => true
        ];

        // Good Friday (2 days before Easter)
        $good_friday = date('Y-m-d', strtotime('-2 days', $easter_time));
        $holidays[$good_friday] = [
            'date' => $good_friday,
            'name' => 'Good Friday',
            'filipino_name' => 'Biyernes Santo',
            'type' => 'regular',
            'category' => 'Regular Holiday',
            'description' => 'Holy Week regular holiday observing the Crucifixion and death of Jesus Christ.',
            'badge_color' => '#dc2626',
            'badge_bg' => '#fee2e2',
            'is_non_working' => true
        ];

        // National Heroes Day: Last Monday of August
        $last_monday_aug = date('Y-m-d', strtotime("last monday of august $year"));
        $holidays[$last_monday_aug] = [
            'date' => $last_monday_aug,
            'name' => 'National Heroes Day',
            'filipino_name' => 'Araw ng mga Bayani',
            'type' => 'regular',
            'category' => 'Regular Holiday',
            'description' => 'Regular national holiday commemorating all known and unknown heroes of the Philippines.',
            'badge_color' => '#dc2626',
            'badge_bg' => '#fee2e2',
            'is_non_working' => true
        ];

        // Islamic Movable Holidays (Estimated per Hijri Lunar Calendar & Presidential Proclamations)
        $islamic_movable = [
            2025 => [
                'eidl_fitr' => '2025-03-31',
                'eidl_adha' => '2025-06-06'
            ],
            2026 => [
                'eidl_fitr' => '2026-03-20',
                'eidl_adha' => '2026-05-27'
            ],
            2027 => [
                'eidl_fitr' => '2027-03-10',
                'eidl_adha' => '2027-05-16'
            ]
        ];

        if (isset($islamic_movable[$year])) {
            $ef = $islamic_movable[$year]['eidl_fitr'];
            $holidays[$ef] = [
                'date' => $ef,
                'name' => "Eid'l Fitr",
                'filipino_name' => "Pistang Pagtatapos ng Ramadan",
                'type' => 'regular',
                'category' => 'Regular Holiday',
                'description' => "Official national regular holiday celebrating the conclusion of the holy month of Ramadan (Republic Act No. 9177).",
                'badge_color' => '#dc2626',
                'badge_bg' => '#fee2e2',
                'is_non_working' => true
            ];

            $ea = $islamic_movable[$year]['eidl_adha'];
            $holidays[$ea] = [
                'date' => $ea,
                'name' => "Eid'l Adha",
                'filipino_name' => "Pista ng Sakripisyo",
                'type' => 'regular',
                'category' => 'Regular Holiday',
                'description' => "Official national regular holiday observing the Feast of Sacrifice (Republic Act No. 9849).",
                'badge_color' => '#dc2626',
                'badge_bg' => '#fee2e2',
                'is_non_working' => true
            ];
        }

        // ----------------------------------------------------------------------
        // 3. FIXED SPECIAL (NON-WORKING) DAYS
        // ----------------------------------------------------------------------
        $fixed_special = [
            '02-25' => [
                'name' => 'EDSA People Power Revolution Anniversary',
                'filipino_name' => 'Anibersaryo ng EDSA People Power',
                'type' => 'special_non_working',
                'category' => 'Special Non-Working Day',
                'description' => 'Special holiday marking the 1986 peaceful revolution restoring Philippine democracy.'
            ],
            '08-21' => [
                'name' => 'Ninoy Aquino Day',
                'filipino_name' => 'Araw ng Kabayanihan ni Ninoy Aquino',
                'type' => 'special_non_working',
                'category' => 'Special Non-Working Day',
                'description' => 'Commemorates the assassination anniversary of Senator Benigno "Ninoy" Aquino Jr. (Republic Act No. 9256).'
            ],
            '11-01' => [
                'name' => "All Saints' Day",
                'filipino_name' => 'Undas (Araw ng mga Banal)',
                'type' => 'special_non_working',
                'category' => 'Special Non-Working Day',
                'description' => 'Traditional nationwide observance commemorating all Christian saints.'
            ],
            '11-02' => [
                'name' => "All Souls' Day",
                'filipino_name' => 'Araw ng mga Patay',
                'type' => 'special_non_working',
                'category' => 'Special Non-Working Day',
                'description' => 'Additional special non-working day for family remembrance of departed loved ones.'
            ],
            '12-08' => [
                'name' => 'Feast of the Immaculate Conception of Mary',
                'filipino_name' => 'Pista ng Kalinis-linisang Paglilihi kay Maria',
                'type' => 'special_non_working',
                'category' => 'Special Non-Working Day',
                'description' => 'Special national non-working holiday honoring the patroness of the Philippines (Republic Act No. 10966).'
            ],
            '12-24' => [
                'name' => 'Christmas Eve',
                'filipino_name' => 'Bisperas ng Pasko',
                'type' => 'special_non_working',
                'category' => 'Special Non-Working Day',
                'description' => 'Special non-working day ahead of Christmas celebrations.'
            ],
            '12-31' => [
                'name' => 'Last Day of the Year',
                'filipino_name' => 'Bisperas ng Bagong Taon (New Year\'s Eve)',
                'type' => 'special_non_working',
                'category' => 'Special Non-Working Day',
                'description' => 'Special non-working day culminating the annual calendar year.'
            ],
        ];

        foreach ($fixed_special as $md => $data) {
            $date = sprintf('%04d-%s', $year, $md);
            $holidays[$date] = array_merge($data, [
                'date' => $date,
                'badge_color' => '#7c3aed',
                'badge_bg' => '#ede9fe',
                'is_non_working' => true
            ]);
        }

        // ----------------------------------------------------------------------
        // 4. MOVABLE SPECIAL (NON-WORKING) DAYS (Black Saturday & Chinese New Year)
        // ----------------------------------------------------------------------
        // Black Saturday (1 day before Easter)
        $black_sat = date('Y-m-d', strtotime('-1 day', $easter_time));
        $holidays[$black_sat] = [
            'date' => $black_sat,
            'name' => 'Black Saturday',
            'filipino_name' => 'Sabado de Gloria',
            'type' => 'special_non_working',
            'category' => 'Special Non-Working Day',
            'description' => 'Holy Week special non-working day observing Holy Saturday.',
            'badge_color' => '#7c3aed',
            'badge_bg' => '#ede9fe',
            'is_non_working' => true
        ];

        // Chinese Lunar New Year (Spring Festival)
        $cny_map = [
            2025 => '2025-01-29',
            2026 => '2026-02-17',
            2027 => '2027-02-06'
        ];
        if (isset($cny_map[$year])) {
            $cny_date = $cny_map[$year];
            $holidays[$cny_date] = [
                'date' => $cny_date,
                'name' => 'Chinese New Year',
                'filipino_name' => 'Bagong Taon ng mga Tsino',
                'type' => 'special_non_working',
                'category' => 'Special Non-Working Day',
                'description' => 'Special non-working day celebrating the Lunar New Year Festival.',
                'badge_color' => '#7c3aed',
                'badge_bg' => '#ede9fe',
                'is_non_working' => true
            ];
        }

        // ----------------------------------------------------------------------
        // 5. ACADEMIC CALENDAR BLACKOUT WINDOWS (Institutional Policy)
        // ----------------------------------------------------------------------
        $academic_blackouts = [
            // 2025-2026 1st Semester Examinations
            '2025-10-20' => ['name' => 'Midterm Examination Week (Day 1)', 'type' => 'exam_blackout'],
            '2025-10-21' => ['name' => 'Midterm Examination Week (Day 2)', 'type' => 'exam_blackout'],
            '2025-10-22' => ['name' => 'Midterm Examination Week (Day 3)', 'type' => 'exam_blackout'],
            '2025-10-23' => ['name' => 'Midterm Examination Week (Day 4)', 'type' => 'exam_blackout'],
            '2025-10-24' => ['name' => 'Midterm Examination Week (Day 5)', 'type' => 'exam_blackout'],
            '2025-12-15' => ['name' => 'Final Examination Week (Day 1)', 'type' => 'exam_blackout'],
            '2025-12-16' => ['name' => 'Final Examination Week (Day 2)', 'type' => 'exam_blackout'],
            '2025-12-17' => ['name' => 'Final Examination Week (Day 3)', 'type' => 'exam_blackout'],
            '2025-12-18' => ['name' => 'Final Examination Week (Day 4)', 'type' => 'exam_blackout'],
            '2025-12-19' => ['name' => 'Final Examination Week (Day 5)', 'type' => 'exam_blackout'],

            // 2025-2026 2nd Semester Midterms & Finals
            '2026-03-09' => ['name' => 'Midterm Examination Week (Day 1)', 'type' => 'exam_blackout'],
            '2026-03-10' => ['name' => 'Midterm Examination Week (Day 2)', 'type' => 'exam_blackout'],
            '2026-03-11' => ['name' => 'Midterm Examination Week (Day 3)', 'type' => 'exam_blackout'],
            '2026-03-12' => ['name' => 'Midterm Examination Week (Day 4)', 'type' => 'exam_blackout'],
            '2026-03-13' => ['name' => 'Midterm Examination Week (Day 5)', 'type' => 'exam_blackout'],
            '2026-05-18' => ['name' => 'Final Examination Week (Day 1)', 'type' => 'exam_blackout'],
            '2026-05-19' => ['name' => 'Final Examination Week (Day 2)', 'type' => 'exam_blackout'],
            '2026-05-20' => ['name' => 'Final Examination Week (Day 3)', 'type' => 'exam_blackout'],
            '2026-05-21' => ['name' => 'Final Examination Week (Day 4)', 'type' => 'exam_blackout'],
            '2026-05-22' => ['name' => 'Final Examination Week (Day 5)', 'type' => 'exam_blackout'],

            // 2026-2027 1st Semester Midterms & Finals
            '2026-10-19' => ['name' => 'Midterm Examination Week (Day 1)', 'type' => 'exam_blackout'],
            '2026-10-20' => ['name' => 'Midterm Examination Week (Day 2)', 'type' => 'exam_blackout'],
            '2026-10-21' => ['name' => 'Midterm Examination Week (Day 3)', 'type' => 'exam_blackout'],
            '2026-10-22' => ['name' => 'Midterm Examination Week (Day 4)', 'type' => 'exam_blackout'],
            '2026-10-23' => ['name' => 'Midterm Examination Week (Day 5)', 'type' => 'exam_blackout'],
            '2026-12-14' => ['name' => 'Final Examination Week (Day 1)', 'type' => 'exam_blackout'],
            '2026-12-15' => ['name' => 'Final Examination Week (Day 2)', 'type' => 'exam_blackout'],
            '2026-12-16' => ['name' => 'Final Examination Week (Day 3)', 'type' => 'exam_blackout'],
            '2026-12-17' => ['name' => 'Final Examination Week (Day 4)', 'type' => 'exam_blackout'],
            '2026-12-18' => ['name' => 'Final Examination Week (Day 5)', 'type' => 'exam_blackout'],

            // 2026-2027 2nd Semester Midterms & Finals
            '2027-03-08' => ['name' => 'Midterm Examination Week (Day 1)', 'type' => 'exam_blackout'],
            '2027-03-09' => ['name' => 'Midterm Examination Week (Day 2)', 'type' => 'exam_blackout'],
            '2027-03-10' => ['name' => 'Midterm Examination Week (Day 3)', 'type' => 'exam_blackout'],
            '2027-03-11' => ['name' => 'Midterm Examination Week (Day 4)', 'type' => 'exam_blackout'],
            '2027-03-12' => ['name' => 'Midterm Examination Week (Day 5)', 'type' => 'exam_blackout'],
            '2027-05-17' => ['name' => 'Final Examination Week (Day 1)', 'type' => 'exam_blackout'],
            '2027-05-18' => ['name' => 'Final Examination Week (Day 2)', 'type' => 'exam_blackout'],
            '2027-05-19' => ['name' => 'Final Examination Week (Day 3)', 'type' => 'exam_blackout'],
            '2027-05-20' => ['name' => 'Final Examination Week (Day 4)', 'type' => 'exam_blackout'],
            '2027-05-21' => ['name' => 'Final Examination Week (Day 5)', 'type' => 'exam_blackout'],
        ];

        foreach ($academic_blackouts as $bdate => $binfo) {
            if (substr($bdate, 0, 4) == (string)$year && !isset($holidays[$bdate])) {
                $holidays[$bdate] = [
                    'date' => $bdate,
                    'name' => $binfo['name'],
                    'filipino_name' => 'Panahon ng Pagsusulit',
                    'type' => 'exam_blackout',
                    'category' => 'Academic Exam Blackout',
                    'description' => 'Official campus examination window. Student co-curricular activities are suspended to protect academic focus.',
                    'badge_color' => '#d97706',
                    'badge_bg' => '#fef3c7',
                    'is_non_working' => false
                ];
            }
        }

        // Sort chronologically by date
        ksort($holidays);
        return $holidays;
    }

    /**
     * Look up holiday details for a specific YYYY-MM-DD date.
     */
    function get_ph_holiday_on_date(string $dateStr): ?array {
        $d = trim(substr($dateStr, 0, 10));
        if (!$d || strlen($d) < 10) return null;
        $year = (int)substr($d, 0, 4);
        $catalog = get_ph_holidays($year);
        return $catalog[$d] ?? null;
    }

    /**
     * Complete Event Schedule & Venue Collision Detection Algorithm.
     * Evaluates:
     *  1. Philippine Regular Holidays
     *  2. Philippine Special Non-Working Days
     *  3. Academic Exam Blackout Windows
     *  4. Venue Double-Bookings (Existing approved/pending events at the same venue)
     *  5. Host Club Scheduling Collisions
     */
    function detect_event_schedule_conflict($conn, string $dateStr, string $venue = '', int $excludeEventId = 0, int $clubId = 0, string $startTime = '', string $endTime = ''): array {
        $rawDate = trim(substr($dateStr, 0, 10));
        $dateTimeStr = trim($dateStr);
        $formattedDate = date('F d, Y', strtotime($rawDate));

        // Normalize Start and End Times
        $parsedStart = '';
        if (!empty($startTime)) {
            $parsedStart = date('H:i:s', strtotime($startTime));
        } elseif (strpos($dateStr, ' ') !== false || strpos($dateStr, 'T') !== false) {
            $parsedStart = date('H:i:s', strtotime($dateStr));
        }

        $parsedEnd = '';
        if (!empty($endTime)) {
            $parsedEnd = date('H:i:s', strtotime($endTime));
        } elseif (!empty($parsedStart)) {
            $parsedEnd = date('H:i:s', strtotime($parsedStart . ' + 3 hours'));
        }

        $conflicts = [];
        $level = 'safe'; // 'safe', 'warning', 'danger'
        $holidayInfo = get_ph_holiday_on_date($rawDate);

        // 1. Check Philippine Holidays & Special Non-Working Days
        if ($holidayInfo) {
            if ($holidayInfo['type'] === 'regular') {
                $level = 'danger';
                $conflicts[] = [
                    'category' => 'ph_regular_holiday',
                    'severity' => 'danger',
                    'title' => "Philippine Regular Holiday: {$holidayInfo['name']}",
                    'message' => "{$formattedDate} is an official nationwide Regular Holiday ({$holidayInfo['name']} / {$holidayInfo['filipino_name']}). Campus facilities are closed. Events on regular holidays require special Vice President for Academic Affairs & OSA exemption clearance."
                ];
            } elseif ($holidayInfo['type'] === 'special_non_working') {
                if ($level !== 'danger') $level = 'warning';
                $conflicts[] = [
                    'category' => 'ph_special_holiday',
                    'severity' => 'warning',
                    'title' => "Special Non-Working Day: {$holidayInfo['name']}",
                    'message' => "{$formattedDate} is a Special Non-Working Day ({$holidayInfo['name']}). Scheduled attendance may be restricted. Please ensure special campus administrative permit is secured."
                ];
            } elseif ($holidayInfo['type'] === 'exam_blackout') {
                $level = 'danger';
                $conflicts[] = [
                    'category' => 'academic_blackout',
                    'severity' => 'danger',
                    'title' => "Academic Blackout: {$holidayInfo['name']}",
                    'message' => "{$formattedDate} falls within the {$holidayInfo['name']}. Co-curricular events are prohibited during major exam periods to safeguard student academic preparation."
                ];
            }
        }

        // 2. Check Weekend Scheduling Advisory
        $dayOfWeek = (int)date('w', strtotime($rawDate)); // 0 = Sun
        if ($dayOfWeek === 0) {
            if ($level === 'safe') $level = 'warning';
            $conflicts[] = [
                'category' => 'weekend_sunday',
                'severity' => 'warning',
                'title' => 'Sunday Schedule Advisory',
                'message' => "{$formattedDate} is a Sunday. Off-campus or special facility access clearance from the Office of Student Affairs (OSA) is required."
            ];
        }

        // 3. Check Venue & Time Double-Booking Collisions in Database
        // Rule: Multiple events are ALLOWED on the same date, but CANNOT have overlapping times at the same place/venue.
        if (!empty($venue) && $conn instanceof mysqli) {
            $vQuery = "SELECT e.id, e.title, e.event_date, e.start_time, e.end_time, e.venue, e.status, 
                              COALESCE(c.name, 'BCP Institutional') AS club_name, 
                              COALESCE(c.code, 'INSTITUTIONAL') AS club_code 
                       FROM events e 
                       LEFT JOIN clubs c ON c.id = e.club_id 
                       WHERE LOWER(TRIM(e.venue)) = LOWER(TRIM(?)) 
                         AND DATE(e.event_date) = ? 
                         AND e.status IN ('Approved', 'Upcoming', 'Pending SSC', 'Pending Admin')
                         AND e.id != ?
                         AND e.deleted_at IS NULL";
            $vStmt = $conn->prepare($vQuery);
            if ($vStmt) {
                $vStmt->bind_param('ssi', $venue, $rawDate, $excludeEventId);
                $vStmt->execute();
                $vRes = $vStmt->get_result();
                while ($vRow = $vRes->fetch_assoc()) {
                    $evStart = !empty($vRow['start_time']) ? $vRow['start_time'] : date('H:i:s', strtotime($vRow['event_date']));
                    $evEnd   = !empty($vRow['end_time'])   ? $vRow['end_time']   : date('H:i:s', strtotime($evStart . ' + 3 hours'));
                    $fmtEvStart = date('g:i A', strtotime($evStart));
                    $fmtEvEnd   = date('g:i A', strtotime($evEnd));

                    if (!empty($parsedStart) && !empty($parsedEnd)) {
                        $fmtReqStart = date('g:i A', strtotime($parsedStart));
                        $fmtReqEnd   = date('g:i A', strtotime($parsedEnd));

                        // Check time interval overlap: max(parsedStart, evStart) < min(parsedEnd, evEnd)
                        if ($parsedStart < $evEnd && $parsedEnd > $evStart) {
                            $level = 'danger';
                            $conflicts[] = [
                                'category' => 'venue_time_collision',
                                'severity' => 'danger',
                                'title' => "Venue & Time Conflict: '{$venue}' is Not Available",
                                'message' => "The venue '{$venue}' is already reserved on {$formattedDate} from {$fmtEvStart} to {$fmtEvEnd} for event \"{$vRow['title']}\" [{$vRow['club_code']} - {$vRow['status']}]. Your requested time slot ({$fmtReqStart} &ndash; {$fmtReqEnd}) overlaps with this booking. Events can share the same date, but cannot occupy the same venue at the same time. Please choose another time or venue."
                            ];
                        } else {
                            // Non-overlapping times at the same venue on the same date are ALLOWED!
                            $conflicts[] = [
                                'category' => 'venue_same_date_clear',
                                'severity' => 'info',
                                'title' => "Venue Slot Available",
                                'message' => "Venue '{$venue}' also hosts \"{$vRow['title']}\" on this date ({$fmtEvStart} &ndash; {$fmtEvEnd}), but your requested hours ({$fmtReqStart} &ndash; {$fmtReqEnd}) do not conflict."
                            ];
                        }
                    } else {
                        // Times not specified yet - issue warning advisory of existing venue booking
                        if ($level === 'safe') $level = 'warning';
                        $conflicts[] = [
                            'category' => 'venue_time_advisory',
                            'severity' => 'warning',
                            'title' => "Venue Scheduled on {$formattedDate}",
                            'message' => "The venue '{$venue}' is reserved on {$formattedDate} from {$fmtEvStart} to {$fmtEvEnd} for \"{$vRow['title']}\". Please ensure your event start and end times do not overlap with this schedule."
                        ];
                    }
                }
                $vStmt->close();
            }
        }

        // 4. Check Same Club Simultaneous Event Collision
        if ($clubId > 0 && $conn instanceof mysqli) {
            $cQuery = "SELECT id, title, event_date, start_time, end_time, status FROM events 
                       WHERE club_id = ? 
                         AND DATE(event_date) = ? 
                         AND status IN ('Approved', 'Upcoming', 'Pending SSC', 'Pending Admin')
                         AND id != ?
                         AND deleted_at IS NULL";
            $cStmt = $conn->prepare($cQuery);
            if ($cStmt) {
                $cStmt->bind_param('isi', $clubId, $rawDate, $excludeEventId);
                $cStmt->execute();
                $cRes = $cStmt->get_result();
                while ($cRow = $cRes->fetch_assoc()) {
                    $evStart = !empty($cRow['start_time']) ? $cRow['start_time'] : date('H:i:s', strtotime($cRow['event_date']));
                    $evEnd   = !empty($cRow['end_time'])   ? $cRow['end_time']   : date('H:i:s', strtotime($evStart . ' + 3 hours'));
                    $fmtEvStart = date('g:i A', strtotime($evStart));
                    $fmtEvEnd   = date('g:i A', strtotime($evEnd));

                    if (!empty($parsedStart) && !empty($parsedEnd) && ($parsedStart < $evEnd && $parsedEnd > $evStart)) {
                        if ($level !== 'danger') $level = 'warning';
                        $conflicts[] = [
                            'category' => 'club_time_collision',
                            'severity' => 'warning',
                            'title' => "Simultaneous Organization Event",
                            'message' => "Your organization already has another event \"{$cRow['title']}\" filed on {$formattedDate} during overlapping hours ({$fmtEvStart} &ndash; {$fmtEvEnd})."
                        ];
                    }
                }
                $cStmt->close();
            }
        }

        $hasDangerOrWarning = false;
        foreach ($conflicts as $c) {
            if ($c['severity'] === 'danger' || $c['severity'] === 'warning') {
                $hasDangerOrWarning = true;
                break;
            }
        }

        $summary = $hasDangerOrWarning 
            ? count(array_filter($conflicts, fn($c) => $c['severity'] !== 'info')) . ' scheduling advisory/conflict(s) detected.' 
            : 'No conflicts detected. Date and venue are clear for scheduling.';

        return [
            'date' => $rawDate,
            'formatted_date' => $formattedDate,
            'venue' => $venue,
            'start_time' => $parsedStart,
            'end_time' => $parsedEnd,
            'level' => $level, // 'safe', 'warning', 'danger'
            'has_conflict' => $hasDangerOrWarning,
            'holiday_info' => $holidayInfo,
            'conflicts' => $conflicts,
            'summary' => $summary
        ];
    }
}
