<?php

namespace Tests\Feature;

use App\Models\Club;
use Tests\TestCase;

class AchievementApiTest extends TestCase
{
    private function getBearerToken(string $username, string $password): string
    {
        $loginRes = $this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);
        return $loginRes->json('token') ?? '';
    }

    public function test_achievements_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/achievements');
        $response->assertStatus(401);
    }

    public function test_authenticated_admin_can_list_achievements(): void
    {
        $token = $this->getBearerToken('scc.admin', 'Bcp@Admin2026!');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->getJson('/api/achievements');

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_adviser_cannot_submit_achievement_for_unassigned_club(): void
    {
        $token = $this->getBearerToken('cssec.adviser', 'Bcp@Adviser2026!');

        // Club 2 (ACADS) is advised by Mark Velo, not Alex Reyes (CSSEC)
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/achievements', [
                'club_id'     => 2,
                'title'       => 'Unauthorized Hackathon 1st Place',
                'competition' => 'Regional IT Competition',
                'award_date'  => '2026-03-15',
            ]);

        $response->assertStatus(403);
    }

    public function test_adviser_can_submit_achievement_for_assigned_club(): void
    {
        $token = $this->getBearerToken('cssec.adviser', 'Bcp@Adviser2026!');

        // Club 1 (CSSEC) is advised by cssec.adviser
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/achievements', [
                'club_id'     => 1,
                'title'       => 'Authorized Tech Symposium Champion',
                'competition' => 'National Inter-Collegiate Coding Olympiad',
                'award_date'  => '2026-03-20',
            ]);

        $response->assertStatus(201);
    }

    public function test_student_cannot_submit_achievement_for_non_member_club(): void
    {
        $token = $this->getBearerToken('bsit.student', 'Bcp@Test2026!');

        // bsit.student is member of CSSEC (club 1), not ACADS (club 2)
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/achievements', [
                'club_id'     => 2,
                'title'       => 'Unauthorized Non-Member Achievement',
                'competition' => 'Engineering Robotics Expo',
                'award_date'  => '2026-03-25',
            ]);

        $response->assertStatus(403);
    }
}
