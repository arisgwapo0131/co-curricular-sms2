<?php

namespace App\Http\Controllers;

use App\Models\Achievement;
use App\Models\BudgetRequest;
use App\Models\Club;
use App\Models\Event;
use App\Models\OrgAnnouncement;
use App\Models\Student;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // 100% Dynamic Database Queries (Zero Hardcoded Data)
        $metrics = [
            'total_students'        => Student::count(),
            'active_clubs'          => Club::where('status', 'Active')->count(),
            'verified_achievements' => Achievement::where('status', 'Verified')->count(),
            'upcoming_events'       => Event::where('status', 'Approved')->where('event_date', '>=', now()->toDateString())->count(),
            'pending_budgets'       => BudgetRequest::whereIn('status', ['Pending SSC', 'Pending Admin', 'Pending Adviser'])->count(),
        ];

        $recent_events = Event::with('club')
            ->orderBy('event_date', 'desc')
            ->take(5)
            ->get();

        $recent_achievements = Achievement::orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        $featured_clubs = Club::where('status', 'Active')
            ->take(6)
            ->get();

        $announcements = OrgAnnouncement::orderBy('created_at', 'desc')
            ->take(4)
            ->get();

        return Inertia::render('Dashboard', [
            'metrics'             => $metrics,
            'recentEvents'        => $recent_events,
            'recentAchievements'  => $recent_achievements,
            'featuredClubs'       => $featured_clubs,
            'announcements'       => $announcements,
        ]);
    }

    public function students(Request $request)
    {
        $query = Student::with('user');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('student_number', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('course', 'like', "%{$search}%");
            });
        }

        if ($request->filled('course')) {
            $query->where('course', $request->input('course'));
        }

        $students = $query->orderBy('last_name', 'asc')->paginate(15)->withQueryString();

        return Inertia::render('Students/Index', [
            'students' => $students,
            'filters'  => $request->only(['search', 'course']),
        ]);
    }

    public function clubs(Request $request)
    {
        $clubs = Club::with('adviser')
            ->orderBy('name', 'asc')
            ->get();

        return Inertia::render('Clubs/Index', [
            'clubs' => $clubs,
        ]);
    }

    public function achievements(Request $request)
    {
        $achievements = Achievement::orderBy('created_at', 'desc')
            ->paginate(15);

        return Inertia::render('Achievements/Index', [
            'achievements' => $achievements,
        ]);
    }
}
