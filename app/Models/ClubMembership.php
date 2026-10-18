<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClubMembership extends Model
{
    use HasFactory;

    protected $table = 'club_memberships';

    public $timestamps = false;

    protected $fillable = [
        'club_id',
        'user_id',
        'role',
        'status',
        'joined_at',
        'approved_by',
        'letter_intent',
        'letter_endorsement',
        'adviser_review',
        'ssc_review',
        'review_notes',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class, 'club_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
