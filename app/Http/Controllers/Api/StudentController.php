<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    /**
     * Display a listing of students with role-aware scoping.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Student role: can only list or see own record
        if ($user->role === 'student') {
            $student = Student::with('user')->where('user_id', $user->id)->first();
            return response()->json($student ? [$student] : []);
        }

        // Admin, SSC, Club Adviser can view student directory
        return response()->json(Student::with('user')->get());
    }

    /**
     * Store a newly created student (Admin & SSC only).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, ['admin', 'ssc'])) {
            return response()->json([
                'message' => 'Forbidden: Only administrators and SSC officers can create student records.'
            ], 403);
        }

        $validated = $request->validate([
            'user_id'        => 'nullable|integer|exists:users,id',
            'student_number' => 'required|string|max:50|unique:students,student_number',
            'first_name'     => 'required|string|max:100',
            'last_name'      => 'required|string|max:100',
            'birthday'       => 'nullable|date',
            'course'         => 'required|string|max:150',
            'year_level'     => 'required|string|max:50',
            'section'        => 'nullable|string|max:50',
            'phone'          => 'nullable|string|max:20',
            'status'         => 'nullable|string|in:Active,Inactive',
        ]);

        if (empty($validated['birthday'])) {
            $validated['birthday'] = '2004-01-01';
        }
        $validated['section'] = $validated['section'] ?? '';
        $validated['phone']   = $validated['phone'] ?? '';
        $validated['status']  = $validated['status'] ?? 'Active';

        if (empty($validated['user_id'])) {
            $matchedUser = User::where('username', $validated['student_number'])->first();
            if ($matchedUser) {
                $validated['user_id'] = $matchedUser->id;
            }
        }

        $student = Student::create($validated);
        return response()->json($student->load('user'), 201);
    }

    /**
     * Display the specified student (Role-scoped).
     */
    public function show(Request $request, Student $student)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Student can only access their own profile
        if ($user->role === 'student' && $student->user_id !== $user->id) {
            return response()->json([
                'message' => 'Forbidden: You are not authorized to view another student profile.'
            ], 403);
        }

        return response()->json($student->load('user'));
    }

    /**
     * Update the specified student (Admin & SSC, or student self-phone update).
     */
    public function update(Request $request, Student $student)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Club advisers cannot edit student profiles
        if ($user->role === 'club_adviser') {
            return response()->json([
                'message' => 'Forbidden: Club advisers cannot modify student records.'
            ], 403);
        }

        // Student role: can only update their own phone contact, cannot alter academic or status fields
        if ($user->role === 'student') {
            if ($student->user_id !== $user->id) {
                return response()->json([
                    'message' => 'Forbidden: You cannot modify another student profile.'
                ], 403);
            }

            $validated = $request->validate([
                'phone' => 'required|string|max:20',
            ]);

            $student->update(['phone' => $validated['phone']]);
            return response()->json($student->load('user'));
        }

        // Admin and SSC: Full management authorization
        if (in_array($user->role, ['admin', 'ssc'])) {
            $validated = $request->validate([
                'student_number' => 'sometimes|required|string|max:50|unique:students,student_number,' . $student->id,
                'first_name'     => 'sometimes|required|string|max:100',
                'last_name'      => 'sometimes|required|string|max:100',
                'birthday'       => 'nullable|date',
                'course'         => 'sometimes|required|string|max:150',
                'year_level'     => 'sometimes|required|string|max:50',
                'section'        => 'nullable|string|max:50',
                'phone'          => 'nullable|string|max:20',
                'status'         => 'sometimes|required|string|in:Active,Inactive',
            ]);

            $student->update($validated);
            return response()->json($student->load('user'));
        }

        return response()->json(['message' => 'Forbidden.'], 403);
    }

    /**
     * Remove the specified student (Admin ONLY).
     */
    public function destroy(Request $request, Student $student)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'message' => 'Forbidden: Only system administrators are authorized to delete student records.'
            ], 403);
        }

        $student->delete();
        return response()->json(null, 204);
    }
}
