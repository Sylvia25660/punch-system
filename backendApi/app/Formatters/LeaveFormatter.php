<?php

namespace App\Formatters;

use App\Models\Leave;

class LeaveFormatter
{
    public static function format(Leave $leave): array
    {
        return [
            'leave_id' => $leave->id,
            'user_id' => $leave->user_id,
            'user_name' => $leave->user->name,
            'leave_type_id' => $leave->leave_type_id,
            'leave_type' => optional($leave->leaveType)->name,
            'leave_type_name' => optional($leave->leaveType)->description,
            'start_time' => $leave->start_time,
            'end_time' => $leave->end_time,
            'reason' => $leave->reason,
            'leave_hours' => $leave->leave_hours,
            'status' => $leave->status,
            'reject_reason' => $leave->reject_reason,
            'attachment' => $leave->file ? asset("storage/" . $leave->file->leave_attachment) : null,
            'created_at' => $leave->created_at,
        ];
    }
}
