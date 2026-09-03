<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttendanceController extends Controller
{
    /**
     * Menampilkan semua data attendance.
     * Khusus ADMIN untuk mengelola absensi.
     */
    public function index(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Fitur kelola absensi khusus untuk Admin.',
            ], 403);
        }

        $attendances = Attendance::with('user')
            ->latest('date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $attendances,
        ]);
    }

    /**
     * Menampilkan status attendance hari ini untuk user yang login.
     * Bisa diakses oleh SIAPA SAJA (Admin / Barge Crew).
     */
    public function today(Request $request)
    {
        $attendance = Attendance::where('user_id', $request->user()->id)
            ->whereDate('date', today())
            ->first();

        return response()->json([
            'success' => true,
            'data' => $attendance,
        ]);
    }

    /**
     * Clock In (Absen Masuk)
     * Bebas untuk SIAPA SAJA (Barge Crew & Admin).
     */
    public function clockIn(Request $request)
    {
        $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'notes' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $today = today();

        // Cek apakah sudah clock in hari ini
        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->first();

        if ($attendance) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah melakukan clock in hari ini.',
                'data' => $attendance,
            ], 400);
        }

        // KODE BARU (Pendekatan A)
        $now = now();

        // 1. Tentukan Jam Masuk Kerja (08:00)
        $workStart = Carbon::createFromTime(8, 0, 0);

        // 2. Tentukan Batas Toleransi (08:00 + 15 Menit = 08:15)
        $toleranceTime = $workStart->copy()->addMinutes(15);

        // 3. Status Terlambat: Hanya bernilai true JIKA waktu absen melewati jam 08:15
        $isLate = $now->greaterThan($toleranceTime);

        // 4. Durasi Terlambat: Dihitung selisih menitnya dari jam 08:15 (bukan dari 08:00)
        $lateDuration = $isLate ? $toleranceTime->diffInMinutes($now) : 0;

        $photoPath = $request->file('photo')->store('attendances', 'public');

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'date' => $today,
            'status' => 'present',
            'clock_in' => $now->format('H:i:s'),
            'latitude_in' => $request->latitude,
            'longitude_in' => $request->longitude,
            'photo_in' => $photoPath,
            'is_late' => $isLate,
            'late_duration' => $lateDuration,
            'notes' => $request->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Clock in berhasil.',
            'data' => $attendance,
        ], 201);
    }

    /**
     * Clock Out (Absen Pulang)
     * Bebas untuk SIAPA SAJA (Barge Crew & Admin).
     */
    public function clockOut(Request $request)
    {
        $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'notes' => ['nullable', 'string'],
        ]);

        $user = $request->user();

        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', today())
            ->first();

        if (!$attendance) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum melakukan clock in hari ini.',
            ], 400);
        }

        // Cek jika sudah clock out (2x absen selesai)
        if ($attendance->clock_out) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah menyelesaikan absensi hari ini (Clock In & Clock Out).',
                'data' => $attendance,
            ], 400);
        }

        $now = now();
        $photoPath = $request->file('photo')->store('attendances', 'public');

        $clockIn = Carbon::parse($attendance->date->format('Y-m-d') . ' ' . $attendance->clock_in);
        $workDuration = $clockIn->diffInMinutes($now);

        $attendance->update([
            'clock_out' => $now->format('H:i:s'),
            'latitude_out' => $request->latitude,
            'longitude_out' => $request->longitude,
            'photo_out' => $photoPath,
            'work_duration' => $workDuration,
            'notes' => $request->notes ?? $attendance->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Clock out berhasil. Absensi hari ini telah lengkap.',
            'data' => $attendance,
        ]);
    }

    /**
     * Detail Absensi (Khusus Admin)
     */
    public function show(Request $request, Attendance $attendance)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Fitur ini khusus untuk Admin.',
            ], 403);
        }

        $attendance->load('user');

        return response()->json([
            'success' => true,
            'data' => $attendance,
        ]);
    }

    /**
     * Update/Koreksi Absensi (Khusus Admin)
     */
    public function update(Request $request, Attendance $attendance)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Hanya Admin yang dapat mengedit absensi.',
            ], 403);
        }

        $request->validate([
            'date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'in:present,absent,sick,leave'],
            'clock_in' => ['nullable', 'date_format:H:i:s'],
            'clock_out' => ['nullable', 'date_format:H:i:s'],
            'latitude_in' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude_in' => ['nullable', 'numeric', 'between:-180,180'],
            'latitude_out' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude_out' => ['nullable', 'numeric', 'between:-180,180'],
            'photo_in' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'photo_out' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'is_late' => ['sometimes', 'boolean'],
            'late_duration' => ['sometimes', 'integer', 'min:0'],
            'work_duration' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $data = $request->only([
            'date',
            'status',
            'clock_in',
            'clock_out',
            'latitude_in',
            'longitude_in',
            'latitude_out',
            'longitude_out',
            'is_late',
            'late_duration',
            'work_duration',
            'notes'
        ]);

        if ($request->hasFile('photo_in')) {
            if ($attendance->photo_in) {
                Storage::disk('public')->delete($attendance->photo_in);
            }
            $data['photo_in'] = $request->file('photo_in')->store('attendances', 'public');
        }

        if ($request->hasFile('photo_out')) {
            if ($attendance->photo_out) {
                Storage::disk('public')->delete($attendance->photo_out);
            }
            $data['photo_out'] = $request->file('photo_out')->store('attendances', 'public');
        }

        $attendance->update($data);
        $attendance->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Data attendance berhasil diperbarui oleh Admin.',
            'data' => $attendance,
        ]);
    }

    /**
     * Hapus Absensi (Khusus Admin)
     */
    public function destroy(Request $request, Attendance $attendance)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Hanya Admin yang dapat menghapus absensi.',
            ], 403);
        }

        if ($attendance->photo_in) {
            Storage::disk('public')->delete($attendance->photo_in);
        }

        if ($attendance->photo_out) {
            Storage::disk('public')->delete($attendance->photo_out);
        }

        $attendance->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data attendance berhasil dihapus.',
        ]);
    }
}
