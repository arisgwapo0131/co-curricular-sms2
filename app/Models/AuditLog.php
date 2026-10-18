<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'user_display',
        'action',
        'target_table',
        'target_id',
        'detail',
        'ip_address',
        'severity',
        'resolution_status',
        'resolved_by',
        'resolution_notes',
        'resolved_at',
        'created_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
