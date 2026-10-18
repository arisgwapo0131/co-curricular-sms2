<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Election extends Model
{
    use HasFactory;

    protected $table = 'elections';

    public $timestamps = false;

    protected $fillable = [
        'election_code',
        'club_id',
        'scope',
        'title',
        'description',
        'election_type',
        'starts_at',
        'closes_at',
        'status',
        'eligible_voters',
        'positions',
        'created_by',
        'verified_at',
        'verified_by',
        'audit_notes',
        'created_at',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class, 'club_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
