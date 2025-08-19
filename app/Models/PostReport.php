<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'reported_by',
        'reason',
        'description',
        'status',
        'reviewed_by',
        'admin_action'
    ];

    protected $casts = [
        //
    ];

    /**
     * Get the post that was reported
     */
    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Get the user who reported the post
     */
    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * Get the admin who reviewed the report
     */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Scope for pending reports
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for reviewed reports
     */
    public function scopeReviewed($query)
    {
        return $query->where('status', 'reviewed');
    }

    /**
     * Get report reasons
     */
    public static function getReasons()
    {
        return [
            'spam' => 'Spam',
            'inappropriate_content' => 'Inappropriate Content',
            'false_information' => 'False Information',
            'other' => 'Other'
        ];
    }

    /**
     * Get report statuses
     */
    public static function getStatuses()
    {
        return [
            'pending' => 'Pending',
            'reviewed' => 'Under Review',
            'resolved' => 'Resolved',
            'dismissed' => 'Dismissed'
        ];
    }

    /**
     * Get admin actions
     */
    public static function getAdminActions()
    {
        return [
            'remove_post' => 'Remove Post',
            'make_private' => 'Make Private',
            'no_action' => 'No Action'
        ];
    }

    /**
     * Get severity based on reason
     */
    public function getSeverityAttribute()
    {
        $severityMap = [
            'inappropriate_content' => 'high',
            'false_information' => 'medium',
            'spam' => 'medium',
            'other' => 'low'
        ];

        return $severityMap[$this->reason] ?? 'low';
    }
}
