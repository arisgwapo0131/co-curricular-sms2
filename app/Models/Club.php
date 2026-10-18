<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Club extends Model
{
    use HasFactory;

    protected $table = 'clubs';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'code',
        'name',
        'category',
        'sub_category',
        'description',
        'adviser_user_id',
        'adviser_name',
        'program',
        'status',
    ];

    public function adviser()
    {
        return $this->belongsTo(User::class, 'adviser_user_id');
    }

    public function memberships()
    {
        return $this->hasMany(ClubMembership::class, 'club_id');
    }

    public function events()
    {
        return $this->hasMany(Event::class, 'club_id');
    }
}
