<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();

            // Relasi ke user
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            // Tanggal absensi
            $table->date('date')->index();

            // Status absensi
            $table->enum('status', [
                'present',
                'absent',
                'sick',
                'leave'
            ])->default('present');

            // =========================
            // CLOCK IN
            // =========================

            $table->time('clock_in')->nullable();

            $table->decimal('latitude_in', 10, 7)->nullable();
            $table->decimal('longitude_in', 10, 7)->nullable();

            $table->string('photo_in')->nullable();

            // =========================
            // CLOCK OUT
            // =========================

            $table->time('clock_out')->nullable();

            $table->decimal('latitude_out', 10, 7)->nullable();
            $table->decimal('longitude_out', 10, 7)->nullable();

            $table->string('photo_out')->nullable();

            // =========================
            // ANALISIS
            // =========================

            // Apakah terlambat
            $table->boolean('is_late')->default(false);

            // Durasi keterlambatan dalam menit
            $table->integer('late_duration')->default(0);

            // Durasi kerja dalam menit
            $table->integer('work_duration')->default(0);

            // Catatan
            $table->text('notes')->nullable();

            $table->timestamps();

            // Satu user hanya boleh memiliki
            // satu data absensi dalam satu hari
            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
