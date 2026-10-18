<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Tests\TestCase;

class StudentApiTest extends TestCase
{
    private function getBearerToken(string $username, string $password): string
    {
        $loginRes = $this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);
        return $loginRes->json('token') ?? '';
    }

    public function test_students_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/students');
        $response->assertStatus(401);
    }

    public function test_authenticated_admin_can_list_students(): void
    {
        $token = $this->getBearerToken('scc.admin', 'Bcp@Admin2026!');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->getJson('/api/students');

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_student_cannot_create_arbitrary_student(): void
    {
        $token = $this->getBearerToken('bsit.student', 'Bcp@Test2026!');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/students', [
                'student_number' => '2026-99999',
                'first_name'     => 'Fake',
                'last_name'      => 'Student',
                'course'         => 'BSIT',
                'year_level'     => '1st Year',
            ]);

        $response->assertStatus(403);
    }

    public function test_student_cannot_delete_student(): void
    {
        $student = Student::first();
        $token = $this->getBearerToken('bsit.student', 'Bcp@Test2026!');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/students/' . $student->id);

        $response->assertStatus(403);
    }

    public function test_adviser_cannot_delete_student(): void
    {
        $student = Student::first();
        $token = $this->getBearerToken('cssec.adviser', 'Bcp@Adviser2026!');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/students/' . $student->id);

        $response->assertStatus(403);
    }

    public function test_ssc_cannot_delete_student(): void
    {
        $student = Student::first();
        $token = $this->getBearerToken('ssc.officer', 'Bcp@SSC2026!');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/students/' . $student->id);

        $response->assertStatus(403);
    }

    public function test_student_cannot_view_another_student_profile(): void
    {
        $otherStudent = Student::where('student_number', '!=', '2024-10001')->first();
        if ($otherStudent) {
            $token = $this->getBearerToken('bsit.student', 'Bcp@Test2026!');

            $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                ->getJson('/api/students/' . $otherStudent->id);

            $response->assertStatus(403);
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_admin_can_create_and_delete_student(): void
    {
        $token = $this->getBearerToken('scc.admin', 'Bcp@Admin2026!');

        // 1. Admin creates student
        $createRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/students', [
                'student_number' => '2026-TEST-RBAC',
                'first_name'     => 'Test',
                'last_name'      => 'RBAC',
                'birthday'       => '2005-01-15',
                'course'         => 'Bachelor of Science in Information Technology',
                'year_level'     => '1st Year',
                'status'         => 'Active',
            ]);

        $createRes->assertStatus(201);
        $newId = $createRes->json('id');

        // 2. Admin deletes student
        $delRes = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/students/' . $newId);

        $delRes->assertStatus(204);
    }
}
