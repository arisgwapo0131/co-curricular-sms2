<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use HasFactory;

    protected $table = 'students';

    protected $fillable = [
        'user_id',
        'student_number',
        'first_name',
        'last_name',
        'birthday',
        'course',
        'year_level',
        'section',
        'phone',
        'status',
    ];

    /**
     * Alias student_id to student_number for API backward compatibility
     */
    public function getStudentIdAttribute()
    {
        return $this->student_number;
    }

    public function setStudentIdAttribute($value)
    {
        $this->attributes['student_number'] = $value;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
