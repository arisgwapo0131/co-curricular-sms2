<?php

namespace Database\Seeders;

use App\Models\Achievement;
use App\Models\BudgetRequest;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\Event;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Password hashes with environment variable overrides
        $adminPass = getenv('ADMIN_DEFAULT_PASSWORD') ?: ($_ENV['ADMIN_DEFAULT_PASSWORD'] ?? 'Bcp@Admin2026!');
        $sscPass   = getenv('SSC_DEFAULT_PASSWORD')   ?: ($_ENV['SSC_DEFAULT_PASSWORD']   ?? 'Bcp@SSC2026!');
        $advPass   = getenv('ADVISER_DEFAULT_PASSWORD')?: ($_ENV['ADVISER_DEFAULT_PASSWORD']?? 'Bcp@Adviser2026!');
        $stdPass   = getenv('STUDENT_DEFAULT_PASSWORD')?: ($_ENV['STUDENT_DEFAULT_PASSWORD']?? 'Bcp@Test2026!');

        $pwdAdmin   = password_hash($adminPass, PASSWORD_DEFAULT);
        $pwdSsc     = password_hash($sscPass, PASSWORD_DEFAULT);
        $pwdAdviser = password_hash($advPass, PASSWORD_DEFAULT);
        $pwdStudent = password_hash($stdPass, PASSWORD_DEFAULT);

        // Official system accounts adhering to the 4 canonical roles
        $users = [
            ['username' => 'scc.admin',     'email' => 'pangilinanarisguillersaret@gmail.com', 'first_name' => 'System',     'last_name' => 'Admin',      'role' => 'admin',        'password_hash' => $pwdAdmin],
            ['username' => 'ssc.officer',   'email' => 'pangilinanarisguillersaret@gmail.com', 'first_name' => 'SSC',        'last_name' => 'Officer',    'role' => 'ssc',          'password_hash' => $pwdSsc],
            ['username' => 'cssec.adviser', 'email' => 'cssec@adviser.bcp.edu.ph',   'first_name' => 'Prof. Alex', 'last_name' => 'Reyes',      'role' => 'club_adviser', 'password_hash' => $pwdAdviser],
            ['username' => 'bsit.student',  'email' => 'bsit@student.bcp.edu.ph',    'first_name' => 'Juan',       'last_name' => 'Santos',     'role' => 'student',      'password_hash' => $pwdStudent],
        ];

        foreach ($users as $uData) {
            User::updateOrCreate(['username' => $uData['username']], $uData);
        }

        $adminUser   = User::where('username', 'scc.admin')->first();
        $adviserUser = User::where('username', 'cssec.adviser')->first();
        $studentUser = User::where('username', 'bsit.student')->first();

        // 2. Seed Accredited Clubs
        $clubs = [
            [
                'id'              => 1,
                'code'            => 'CSSEC',
                'name'            => 'Computer Science Student Exec Council',
                'category'        => 'Academic',
                'description'     => 'Official organization for IT students focusing on technical skill-building and innovation.',
                'adviser_user_id' => $adviserUser ? $adviserUser->id : null,
                'adviser_name'    => 'Prof. Alex Reyes',
                'program'         => 'BSCS',
                'status'          => 'Active',
            ],
            [
                'id'              => 2,
                'code'            => 'ACADS',
                'name'            => 'Association of Computer Eng Driven Students',
                'category'        => 'Academic',
                'description'     => 'Dedicated to academic excellence among Computer Engineering students.',
                'adviser_name'    => 'Prof. Mark Velo',
                'program'         => 'BSCpE',
                'status'          => 'Active',
            ],
        ];

        foreach ($clubs as $cData) {
            Club::updateOrCreate(['id' => $cData['id']], $cData);
        }

        // 3. Seed Students (all required fields provided)
        if ($studentUser) {
            Student::updateOrCreate(
                ['student_number' => '2024-10001'],
                [
                    'user_id'        => $studentUser->id,
                    'student_number' => '2024-10001',
                    'first_name'     => 'Juan',
                    'last_name'      => 'Santos',
                    'birthday'       => '2004-06-20',
                    'course'         => 'Bachelor of Science in Information Technology',
                    'year_level'     => '2nd Year',
                    'section'        => 'IT-2A',
                    'phone'          => '09123456789',
                    'status'         => 'Active',
                ]
            );
        }

        // 4. Seed Club Memberships
        if ($studentUser) {
            ClubMembership::updateOrCreate(
                ['club_id' => 1, 'user_id' => $studentUser->id],
                [
                    'role'           => 'Member',
                    'status'         => 'Active',
                    'adviser_review' => 'Endorsed',
                    'ssc_review'     => 'Approved',
                ]
            );
        }

        if ($adviserUser) {
            ClubMembership::updateOrCreate(
                ['club_id' => 1, 'user_id' => $adviserUser->id],
                [
                    'role'           => 'Club Adviser',
                    'status'         => 'Active',
                    'adviser_review' => 'Endorsed',
                    'ssc_review'     => 'Approved',
                ]
            );
        }

        // 5. Seed Campus Events
        Event::updateOrCreate(
            ['id' => 1],
            [
                'club_id'            => 1,
                'event_type'         => 'Club',
                'title'              => 'Tech Summit 2026: AI Innovations',
                'description'        => 'Annual campus technology summit featuring keynotes, workshops, and student tech showcases.',
                'event_date'         => date('Y-m-d H:i:s', strtotime('+14 days 09:00:00')),
                'venue'              => 'Campus Auditorium & Gymnasium',
                'expected_attendees' => 250,
                'status'             => 'Approved',
                'created_by'         => $adviserUser ? $adviserUser->id : 1,
            ]
        );

        // 6. Seed Budget Requests
        BudgetRequest::updateOrCreate(
            ['id' => 1],
            [
                'club_id'                => 1,
                'title'                  => 'Tech Summit 2026 Production & Materials',
                'description'            => 'Logistics, certificates, guest speaker honorarium, and event merchandise.',
                'amount'                 => 15000.00,
                'recommended_amount'     => 15000.00,
                'status'                 => 'Approved',
                'requested_by'           => $adviserUser ? $adviserUser->id : 1,
                'disbursement_reference' => 'DISB-2026-0001',
            ]
        );
    }
}
