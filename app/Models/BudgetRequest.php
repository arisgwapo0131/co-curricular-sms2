<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BudgetRequest extends Model
{
    use HasFactory;

    protected $table = 'budget_requests';

    protected $fillable = [
        'club_id',
        'title',
        'description',
        'amount',
        'recommended_amount',
        'status',
        'requested_by',
        'disbursement_reference',
        'rejection_note',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class, 'club_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
