<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrgAnnouncement extends Model
{
    use HasFactory;

    protected $table = 'org_announcements';

    protected $fillable = [
        'club_id',
        'scope',
        'author_id',
        'title',
        'category',
        'priority',
        'status',
        'is_pinned',
        'expires_at',
        'content',
        'target_group',
        'channels',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function club()
    {
        return $this->belongsTo(Club::class, 'club_id');
    }
}
