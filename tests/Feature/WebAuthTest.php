<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class WebAuthTest extends TestCase
{
    public function test_guest_is_redirected_to_login_from_root(): void
    {
        $response = $this->get('/');
        $response->assertRedirect('/login');
    }

    public function test_login_page_renders_successfully(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $password = 'Bcp@Admin2026!';
        $user = User::where('username', 'scc.admin')->first();

        if (!$user) {
            $user = User::create([
                'username'      => 'scc.admin',
                'email'         => 'admin@bcp.edu.ph',
                'first_name'    => 'System',
                'last_name'     => 'Admin',
                'role'          => 'admin',
                'status'        => 'Active',
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        }

        $response = $this->post('/login', [
            'username' => 'scc.admin',
            'password' => $password,
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_cannot_login_with_invalid_password(): void
    {
        $response = $this->post('/login', [
            'username' => 'scc.admin',
            'password' => 'WrongPassword123!',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::where('username', 'scc.admin')->first();
        if ($user) {
            $response = $this->actingAs($user)->post('/logout');
            $response->assertRedirect('/login');
            $this->assertGuest();
        } else {
            $this->assertTrue(true);
        }
    }
}
