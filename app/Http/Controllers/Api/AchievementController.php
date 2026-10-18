<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\Club;
use App\Models\ClubMembership;
use Illuminate\Http\Request;

class AchievementController extends Controller
{
    /**
     * Display achievements list with role-aware scoping.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Student role: can only view verified achievements or their own submissions
        if ($user->role === 'student') {
            $achievements = Achievement::where('status', 'Verified')
                ->orWhere('submitted_by', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();
            return response()->json($achievements);
        }

        // Adviser role: can view all achievements for their advised club
        if ($user->role === 'club_adviser') {
            $advisedClubs = Club::where('adviser_user_id', $user->id)->pluck('id');
            $achievements = Achievement::whereIn('club_id', $advisedClubs)
                ->orWhere('status', 'Verified')
                ->orderBy('created_at', 'desc')
                ->get();
            return response()->json($achievements);
        }

        // Admin & SSC: Institutional oversight
        return response()->json(Achievement::orderBy('created_at', 'desc')->get());
    }

    /**
     * Store an achievement with organization-scope & membership authorization.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'club_id'     => 'required|integer|exists:clubs,id',
            'title'       => 'required|string|max:250',
            'competition' => 'nullable|string|max:250',
            'award_date'  => 'required|date',
            'notes'       => 'nullable|string',
        ]);

        $club = Club::find($validated['club_id']);

        // Organization-scope authorization check for Club Adviser
        if ($user->role === 'club_adviser') {
            if ($club->adviser_user_id !== $user->id) {
                return response()->json([
                    'message' => 'Forbidden: You are only authorized to submit achievements for your assigned organization.'
                ], 403);
            }
        }

        // Membership authorization check for Student
        if ($user->role === 'student') {
            $isMember = ClubMembership::where('club_id', $club->id)
                ->where('user_id', $user->id)
                ->where('status', 'Active')
                ->exists();

            if (!$isMember) {
                return response()->json([
                    'message' => 'Forbidden: You must be an active registered member of this organization to submit an achievement.'
                ], 403);
            }
        }

        $validated['submitted_by'] = $user->id;

        // Default initial workflow status
        $validated['status'] = in_array($user->role, ['admin', 'ssc']) ? 'Verified' : 'Pending';

        $achievement = Achievement::create($validated);
        return response()->json($achievement, 201);
    }
}
