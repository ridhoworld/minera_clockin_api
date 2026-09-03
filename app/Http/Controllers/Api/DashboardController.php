<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Dashboard sesuai role user.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            return $this->adminDashboard();
        }

        return $this->bargeCrewDashboard($user);
    }

    /**
     * Dashboard Admin.
     */
    private function adminDashboard()
    {
        $today = today();

        $totalBargeCrew = User::where('role', 'barge_crew')->count();

        $attendanceToday = Attendance::whereDate('date', $today)
            ->where('status', 'present')
            ->count();

        $lateToday = Attendance::whereDate('date', $today)
            ->where('is_late', true)
            ->count();

        $clockedOutToday = Attendance::whereDate('date', $today)
            ->whereNotNull('clock_out')
            ->count();

        $notClockedInToday = max(
            $totalBargeCrew - $attendanceToday,
            0
        );

        // Statistik 7 hari terakhir
        $weekly = collect();

        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);

            $weekly->push([
                'date' => $date->format('Y-m-d'),
                'label' => $date->format('d/m'),
                'present' => Attendance::whereDate(
                    'date',
                    $date
                )
                    ->where('status', 'present')
                    ->count(),

                'late' => Attendance::whereDate(
                    'date',
                    $date
                )
                    ->where('is_late', true)
                    ->count(),
            ]);
        }

        // Attendance terbaru
        $recentAttendances = Attendance::with('user')
            ->latest('date')
            ->latest('clock_in')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'role' => 'admin',

                'user' => [
                    'name' => auth()->user()->name,
                ],

                'statistics' => [
                    'total_barge_crew' => $totalBargeCrew,
                    'present_today' => $attendanceToday,
                    'not_clocked_in_today' => $notClockedInToday,
                    'late_today' => $lateToday,
                    'clocked_out_today' => $clockedOutToday,
                ],

                'weekly' => $weekly,

                'recent_attendances' => $recentAttendances,
            ],
        ]);
    }

    /**
     * Dashboard Barge Crew.
     */
    private function bargeCrewDashboard(User $user)
    {
        $today = today();

        $todayAttendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->first();

        // Riwayat 7 hari terakhir
        $history = Attendance::where('user_id', $user->id)
            ->latest('date')
            ->limit(7)
            ->get();

        // Total hadir bulan ini
        $presentThisMonth = Attendance::where(
            'user_id',
            $user->id
        )
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->where('status', 'present')
            ->count();

        // Total terlambat bulan ini
        $lateThisMonth = Attendance::where(
            'user_id',
            $user->id
        )
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->where('is_late', true)
            ->count();

        return response()->json([
            'success' => true,

            'data' => [
                'role' => 'barge_crew',

                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $user->role,
                ],

                'today' => $todayAttendance,

                'statistics' => [
                    'present_this_month' => $presentThisMonth,
                    'late_this_month' => $lateThisMonth,
                    'total_work_minutes_this_month' => Attendance::where(
                        'user_id',
                        $user->id
                    )
                        ->whereMonth('date', now()->month)
                        ->whereYear('date', now()->year)
                        ->sum('work_duration'),
                ],

                'history' => $history,
            ],
        ]);
    }
}
