<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'status',

        'clock_in',
        'latitude_in',
        'longitude_in',
        'photo_in',
        'address_in',

        'clock_out',
        'latitude_out',
        'longitude_out',
        'photo_out',
        'address_out',

        'is_late',
        'late_duration',
        'work_duration',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_late' => 'boolean',
            'late_duration' => 'integer',
            'work_duration' => 'integer',
        ];
    }

    /**
     * Relasi ke User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
