<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    protected $table = 'events';

    public $timestamps = false;

    protected $fillable = [
        'club_id',
        'event_type',
        'title',
        'description',
        'event_date',
        'venue',
        'expected_attendees',
        'attachment',
        'status',
        'endorsement_notes',
        'rejection_note',
        'created_by',
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
