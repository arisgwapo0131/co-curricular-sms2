<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    public function test_login_with_valid_admin_credentials(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'username' => 'scc.admin',
            'password' => 'Bcp@Admin2026!',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'message',
                     'token',
                     'user' => [
                         'id',
                         'username',
                         'email',
                         'role',
                     ],
                 ]);
    }

    public function test_login_with_invalid_credentials_is_rejected(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'username' => 'scc.admin',
            'password' => 'WrongPassword123!',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['username']);
    }

    public function test_authenticated_profile_with_bearer_token(): void
    {
        $loginRes = $this->postJson('/api/auth/login', [
            'username' => 'scc.admin',
            'password' => 'Bcp@Admin2026!',
        ]);

        $token = $loginRes->json('token');
        $this->assertNotEmpty($token);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->getJson('/api/auth/profile');

        $response->assertStatus(200)
                 ->assertJsonPath('user.username', 'scc.admin');
    }

    public function test_public_self_registration_is_prohibited(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'username'   => 'new.student',
            'email'      => 'new.student@bcp.edu.ph',
            'first_name' => 'New',
            'last_name'  => 'Student',
            'password'   => 'Password123!',
        ]);

        $response->assertStatus(403);
    }

    public function test_logout_revokes_token(): void
    {
        $loginRes = $this->postJson('/api/auth/login', [
            'username' => 'scc.admin',
            'password' => 'Bcp@Admin2026!',
        ]);

        $token = $loginRes->json('token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->postJson('/api/auth/logout');

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Successfully logged out']);
    }
}
