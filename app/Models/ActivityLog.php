<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class ActivityLog extends Model
{
    protected $fillable = [
        'admin_id',
        'action',
        'target',
        'details',
        'category',
        'severity',
        'ip_address'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the admin who performed the action
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Scope for filtering by date range
     */
    public function scopeDateRange($query, $filter)
    {
        switch ($filter) {
            case 'Today':
                return $query->whereDate('created_at', Carbon::today());
            case 'Yesterday':
                return $query->whereDate('created_at', Carbon::yesterday());
            case 'Last 7 days':
                return $query->where('created_at', '>=', Carbon::now()->subDays(7));
            case 'Last 30 days':
                return $query->where('created_at', '>=', Carbon::now()->subDays(30));
            default:
                return $query;
        }
    }

    /**
     * Scope for filtering by category
     */
    public function scopeCategory($query, $category)
    {
        if ($category && $category !== 'All') {
            return $query->where('category', $category);
        }
        return $query;
    }

    /**
     * Scope for filtering by severity
     */
    public function scopeSeverity($query, $severity)
    {
        if ($severity && $severity !== 'All') {
            return $query->where('severity', $severity);
        }
        return $query;
    }

    /**
     * Scope for searching
     */
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where(function ($q) use ($search) {
                $q->where('action', 'LIKE', "%{$search}%")
                  ->orWhere('target', 'LIKE', "%{$search}%")
                  ->orWhere('details', 'LIKE', "%{$search}%")
                  ->orWhereHas('admin', function ($adminQuery) use ($search) {
                      $adminQuery->where('name', 'LIKE', "%{$search}%")
                                 ->orWhere('email', 'LIKE', "%{$search}%");
                  });
            });
        }
        return $query;
    }

    /**
     * Static method to log admin activity
     */
    public static function logActivity($adminId, $action, $target, $details, $category, $severity, $ipAddress)
    {
        return self::create([
            'admin_id' => $adminId,
            'action' => $action,
            'target' => $target,
            'details' => $details,
            'category' => $category,
            'severity' => $severity,
            'ip_address' => $ipAddress
        ]);
    }
} 