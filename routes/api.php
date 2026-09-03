<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\DashboardController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    // =========================================================
    // UMUM - SEMUA USER YANG SUDAH LOGIN
    // =========================================================

    Route::post('/logout', [
        AuthController::class,
        'logout'
    ]);

    Route::get('/me', [
        AuthController::class,
        'me'
    ]);

    Route::get('/dashboard', [
        DashboardController::class,
        'index'
    ]);


    // =========================================================
    // ATTENDANCE - USER SENDIRI
    // =========================================================

    Route::get('/attendance/today', [
        AttendanceController::class,
        'today'
    ]);

    Route::post('/attendance/clock-in', [
        AttendanceController::class,
        'clockIn'
    ]);

    Route::post('/attendance/clock-out', [
        AttendanceController::class,
        'clockOut'
    ]);


    // =========================================================
    // ADMIN ONLY
    // =========================================================

    Route::middleware('admin')->group(function () {

        // CRUD USER
        Route::apiResource('users', UserController::class)
            ->except([
                'create',
                'edit'
            ]);


        // Semua attendance
        Route::get('/attendances', [
            AttendanceController::class,
            'index'
        ]);

        // Detail attendance
        Route::get('/attendances/{attendance}', [
            AttendanceController::class,
            'show'
        ]);

        // Update attendance
        Route::put('/attendances/{attendance}', [
            AttendanceController::class,
            'update'
        ]);

        // Hapus attendance
        Route::delete('/attendances/{attendance}', [
            AttendanceController::class,
            'destroy'
        ]);
    });
});
