<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// 新增leave資料表
class Leave extends Model
{
    use HasFactory;

    protected $table = 'leaves';

    protected $fillable = [
        'user_id',
        'leave_type_id',
        'start_time',
        'end_time',
        'leave_hours',
        'reason',
        'reject_reason',
        'status',
        'attachment',
    ];

    public const STATUSES = [
        0,
        1,
        2,
        3,
        4
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function file()
    {
        return $this->hasOne(File::class, 'id', 'attachment');
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function employee()
    {
        return $this->hasOneThrough(Employee::class, User::class, 'id', 'user_id', 'user_id', 'id');
    }

    public function scopeOverlapping($query, $start, $end)
    {
        return $query->where(function ($q) use ($start, $end) {
            $q->where('start_time', '<', $end)
                ->where('end_time', '>', $start);
        });
    }
}
