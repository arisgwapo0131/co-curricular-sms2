<?php

namespace Tests\Feature;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_access_dashboard_with_dynamic_metrics(): void
    {
        $user = User::where('username', 'scc.admin')->first();
        if (!$user) {
            $user = User::first();
        }

        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertStatus(200);

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('metrics')
            ->has('recentEvents')
            ->has('recentAchievements')
            ->has('featuredClubs')
            ->has('announcements')
        );
    }

    public function test_authenticated_user_can_view_students_roster(): void
    {
        $user = User::where('username', 'scc.admin')->first() ?: User::first();

        $response = $this->actingAs($user)->get('/students');
        $response->assertStatus(200);

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Students/Index')
            ->has('students.data')
        );
    }

    public function test_authenticated_user_can_view_clubs_directory(): void
    {
        $user = User::where('username', 'scc.admin')->first() ?: User::first();

        $response = $this->actingAs($user)->get('/clubs');
        $response->assertStatus(200);

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Clubs/Index')
            ->has('clubs')
        );
    }

    public function test_authenticated_user_can_view_achievements(): void
    {
        $user = User::where('username', 'scc.admin')->first() ?: User::first();

        $response = $this->actingAs($user)->get('/achievements');
        $response->assertStatus(200);

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Achievements/Index')
            ->has('achievements.data')
        );
    }
}
