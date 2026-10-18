<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Achievement extends Model
{
    use HasFactory;

    protected $table = 'achievements';

    public $timestamps = false;

    protected $fillable = [
        'club_id',
        'submitted_by',
        'title',
        'competition',
        'award_date',
        'proof_file',
        'status',
        'verified_by',
        'notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function club()
    {
        return $this->belongsTo(Club::class, 'club_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
