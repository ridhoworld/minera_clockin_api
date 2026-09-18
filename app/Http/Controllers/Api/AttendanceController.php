<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AttendanceController extends Controller
{
    /**
     * Menentukan tanggal bisnis absensi.
     *
     * Aturan:
     * - 06:00 - 23:59 = tanggal hari ini
     * - 00:00 - 05:59 = masih dianggap tanggal absensi kemarin
     *
     * Contoh:
     * 03 September 2026 05:30 -> tanggal absensi 02 September 2026
     * 03 September 2026 06:00 -> tanggal absensi 03 September 2026
     */
    private function getAttendanceDate(): Carbon
    {
        $now = now();

        if ($now->hour < 6) {
            return $now->copy()->subDay()->startOfDay();
        }

        return $now->copy()->startOfDay();
    }

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
     * Menampilkan status attendance
     * berdasarkan siklus absensi saat ini.
     *
     * Aturan jam 06:00:
     * - Jam 00:00 - 05:59 -> mengambil absensi kemarin
     * - Jam 06:00+ -> mengambil absensi hari ini
     */
    public function today(Request $request)
    {
        $attendanceDate = $this->getAttendanceDate();

        $attendance = Attendance::where('user_id', $request->user()->id)
            ->whereDate('date', $attendanceDate)
            ->first();

        return response()->json([
            'success' => true,
            'data' => $attendance,
        ]);
    }

    /**
     * Clock In (Absen Masuk)
     *
     * Bebas untuk SIAPA SAJA (Barge Crew & Admin).
     *
     * Setiap jam 06:00 dimulai siklus absensi baru.
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

        // =========================================================
        // TENTUKAN TANGGAL ABSENSI BERDASARKAN ATURAN JAM 06:00
        // =========================================================

        $attendanceDate = $this->getAttendanceDate();

        // =========================================================
        // CEK APAKAH USER SUDAH CLOCK IN
        // =========================================================

        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', $attendanceDate)
            ->first();

        if ($attendance) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah melakukan clock in pada siklus absensi ini.',
                'data' => $attendance,
            ], 400);
        }

        // =========================================================
        // WAKTU SEKARANG
        // =========================================================

        $now = now();

        // =========================================================
        // JAM MASUK KERJA: 08:00
        // =========================================================

        $workStart = Carbon::create(
            $now->year,
            $now->month,
            $now->day,
            8,
            0,
            0,
            $now->timezone
        );

        // =========================================================
        // BATAS TOLERANSI: 08:15
        // =========================================================

        $toleranceTime = $workStart->copy()->addMinutes(15);

        // =========================================================
        // STATUS TERLAMBAT
        // =========================================================

        $isLate = $now->greaterThan($toleranceTime);

        // =========================================================
        // DURASI TERLAMBAT
        // Dihitung dari 08:15
        // =========================================================

        $lateDuration = $isLate
            ? $toleranceTime->diffInMinutes($now)
            : 0;

        // =========================================================
        // SIMPAN FOTO CLOCK IN
        // =========================================================

        $photoPath = $request->file('photo')
            ->store('attendances', 'public');

        // =========================================================
        // REVERSE GEOCODE
        // latitude + longitude
        // menjadi address_in
        // =========================================================

        $addressIn = null;

        try {

            $latitude = $request->latitude;
            $longitude = $request->longitude;

            $response = Http::timeout(5)
                ->withHeaders([
                    'User-Agent' => 'MineraClockIn/1.0',
                ])
                ->get(
                    'https://nominatim.openstreetmap.org/reverse',
                    [
                        'format' => 'jsonv2',
                        'lat' => $latitude,
                        'lon' => $longitude,
                        'addressdetails' => 1,
                        'zoom' => 18,
                        'accept-language' => 'id',
                    ]
                );

            if ($response->successful()) {

                $addressIn =
                    $response->json('display_name');
            }
        } catch (\Throwable $e) {

            // Jangan menggagalkan clock in
            // hanya karena reverse geocode gagal.

            Log::warning(
                'Reverse geocode clock in gagal',
                [
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'error' => $e->getMessage(),
                ]
            );

            $addressIn = null;
        }

        // =========================================================
        // JIKA REVERSE GEOCODE GAGAL
        // SIMPAN KOORDINAT SEBAGAI FALLBACK
        // =========================================================

        if (!$addressIn) {

            $addressIn =
                'Lat: ' .
                $request->latitude .
                ', Long: ' .
                $request->longitude;
        }

        // =========================================================
        // BUAT ATTENDANCE BARU
        // =========================================================

        $attendance = Attendance::create([

            'user_id' => $user->id,

            'date' =>
            $attendanceDate->toDateString(),

            'status' => 'present',

            // CLOCK IN
            'clock_in' =>
            $now->format('H:i:s'),

            'latitude_in' =>
            $request->latitude,

            'longitude_in' =>
            $request->longitude,

            'photo_in' =>
            $photoPath,

            // ALAMAT HASIL REVERSE GEOCODE
            'address_in' =>
            $addressIn,

            // ANALISIS
            'is_late' =>
            $isLate,

            'late_duration' =>
            $lateDuration,

            'notes' =>
            $request->notes,
        ]);

        // =========================================================
        // RESPONSE
        // =========================================================

        return response()->json([
            'success' => true,

            'message' =>
            'Clock in berhasil.',

            'data' =>
            $attendance,
        ], 201);
    }

    /**
     * Clock Out (Absen Pulang)
     *
     * Bebas untuk SIAPA SAJA (Barge Crew & Admin).
     *
     * Clock out antara 00:00 - 05:59 masih akan
     * menggunakan attendance tanggal sebelumnya.
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

        /**
         * =========================================================
         * AMBIL TANGGAL ABSENSI BERDASARKAN SIKLUS 06:00
         * =========================================================
         */
        $attendanceDate = $this->getAttendanceDate();

        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', $attendanceDate)
            ->first();

        if (!$attendance) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum melakukan clock in pada siklus absensi ini.',
            ], 400);
        }

        /**
         * =========================================================
         * CEK SUDAH CLOCK OUT
         * =========================================================
         */
        if ($attendance->clock_out) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah menyelesaikan absensi pada siklus ini (Clock In & Clock Out).',
                'data' => $attendance,
            ], 400);
        }

        $now = now();

        /**
         * =========================================================
         * REVERSE GEOCODE CLOCK OUT
         * =========================================================
         *
         * Latitude + longitude Clock Out
         * diubah menjadi alamat.
         */
        $addressOut = $this->reverseGeocode(
            $request->latitude,
            $request->longitude
        );

        /**
         * Jika reverse geocode gagal,
         * tetap simpan koordinat sebagai fallback.
         */
        if (!$addressOut) {
            $addressOut =
                'Lat: ' . $request->latitude .
                ', Long: ' . $request->longitude;
        }

        /**
         * =========================================================
         * SIMPAN FOTO CLOCK OUT
         * =========================================================
         */
        $photoPath = $request->file('photo')
            ->store('attendances', 'public');

        /**
         * =========================================================
         * BUAT WAKTU CLOCK IN LENGKAP
         * =========================================================
         */
        $clockIn = Carbon::parse(
            Carbon::parse($attendance->date)->format('Y-m-d')
                . ' '
                . $attendance->clock_in
        );

        /**
         * =========================================================
         * WAKTU CLOCK OUT
         * =========================================================
         */
        $clockOut = $now->copy();

        /**
         * Jika clock out setelah tengah malam,
         * tambahkan 1 hari.
         */
        if ($clockOut->lessThan($clockIn)) {
            $clockOut->addDay();
        }

        /**
         * =========================================================
         * HITUNG DURASI KERJA
         * =========================================================
         */
        $workDuration = $clockIn->diffInMinutes($clockOut);

        /**
         * =========================================================
         * UPDATE ATTENDANCE
         * =========================================================
         */
        $attendance->update([
            'clock_out' => $now->format('H:i:s'),

            'latitude_out' => $request->latitude,

            'longitude_out' => $request->longitude,

            'address_out' => $addressOut,

            'photo_out' => $photoPath,

            'work_duration' => $workDuration,

            'notes' => $request->notes ?? $attendance->notes,
        ]);

        /**
         * Ambil ulang data terbaru dari database.
         */
        $attendance->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Clock out berhasil. Absensi pada siklus ini telah lengkap.',
            'data' => $attendance,
        ]);
    }

    private function reverseGeocode($latitude, $longitude)
    {
        try {

            $response = Http::withHeaders([
                'User-Agent' => 'MineraClockIn/1.0',
                'Accept' => 'application/json',
            ])
                ->timeout(10)
                ->get(
                    'https://nominatim.openstreetmap.org/reverse',
                    [
                        'format' => 'jsonv2',
                        'lat' => $latitude,
                        'lon' => $longitude,
                        'addressdetails' => 1,
                        'zoom' => 18,
                        'accept-language' => 'id',
                    ]
                );

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            return $data['display_name'] ?? null;
        } catch (\Throwable $e) {

            Log::error('Reverse geocode clock out gagal', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }




    /**
     * Detail Absensi
     * Khusus Admin.
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
     * Update/Koreksi Absensi
     * Khusus Admin.
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
            'notes',
        ]);

        /**
         * Ganti foto clock in jika ada foto baru.
         */
        if ($request->hasFile('photo_in')) {
            if ($attendance->photo_in) {
                Storage::disk('public')->delete($attendance->photo_in);
            }

            $data['photo_in'] = $request->file('photo_in')
                ->store('attendances', 'public');
        }

        /**
         * Ganti foto clock out jika ada foto baru.
         */
        if ($request->hasFile('photo_out')) {
            if ($attendance->photo_out) {
                Storage::disk('public')->delete($attendance->photo_out);
            }

            $data['photo_out'] = $request->file('photo_out')
                ->store('attendances', 'public');
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
     * Hapus Absensi
     * Khusus Admin.
     */
    public function destroy(Request $request, Attendance $attendance)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Hanya Admin yang dapat menghapus absensi.',
            ], 403);
        }

        /**
         * Hapus foto clock in.
         */
        if ($attendance->photo_in) {
            Storage::disk('public')->delete($attendance->photo_in);
        }

        /**
         * Hapus foto clock out.
         */
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
