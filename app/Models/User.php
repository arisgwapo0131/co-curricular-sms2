<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'username',
        'first_name',
        'last_name',
        'email',
        'role',
        'status',
        'password_hash',
        'profile_pic',
        'last_login',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    /**
     * Get the password for authentication.
     */
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    /**
     * Alias password attribute to password_hash
     */
    public function getPasswordAttribute()
    {
        return $this->password_hash;
    }

    public function setPasswordAttribute($value)
    {
        $this->attributes['password_hash'] = $value;
    }

    /**
     * Relationship to Student profile
     */
    public function student()
    {
        return $this->hasOne(Student::class, 'user_id');
    }
}
