<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\EmployeeProfile;
use App\Services\FileService;

class LeaveService
{
    const WORK_HOURS_PER_DAY = 8;

    protected FileService $fileService;
    public function __construct(FileService $fileService)
    {
        $this->fileService = $fileService;
    }

    //  1. 申請請假
    public function applyLeave(array $data): Leave
    {
        $userId = $data['user_id'];
        $leaveTypeId = $data['leave_type_id'];
        $start = $data['start_time'];
        $end = $data['end_time'];

        if ($start >= $end) {
            throw new \Exception('結束時間必須大於開始時間', 422);
        }

        // check time range
        $isOverlap = Leave::where('user_id', $userId)
            ->overlapping($start, $end)
            ->whereIn('status', [0, 1])
            ->exists();

        if ($isOverlap) {
            throw new \Exception('此筆申請與已有的紀錄重疊，請重新調整時間範圍', 422);
        }

        // count Leave time
        $hours = $this->calculateHours($start, $end);
        if ($hours <= 0) {
            throw new \Exception('請假區間無效，請重新選擇', 422);
        }

        // count remain Leave time
        $remainingHours = $this->getRemainingLeaveHours($leaveTypeId, $userId, $start);
        if (!is_null($remainingHours) && $remainingHours < $hours) {
            throw new \Exception('剩餘時數不足，請重新修改請假區間', 422);
        }

        // creat request
        $leave = Leave::create([
            'user_id' => $userId,
            'leave_type_id' => $leaveTypeId,
            'start_time' => $start,
            'end_time' => $end,
            'leave_hours' => $hours,
            'reason' => $data['reason'] ?? '',
            'status' => $data['status'] ?? 0,
            'attachment' => isset($data['attachment']) ? $data['attachment'] : null,
        ]);

        return $leave;
    }

    // 2. 查詢個人全部請假紀錄
    public function getLeaveList($user, array $filters)
    {
        $query = Leave::with(['user', 'file'])->where('user_id', $user->id);
        $this->applyFilters($query, $filters);

        return $query->select('leaves.*')
            ->orderByRaw('FIELD(status, 0, 1, 3, 2, 4)')
            ->orderBy('created_at', 'asc')
            ->paginate(10);
    }

    // 3. 查詢「部門」請假紀錄（主管 & HR）
    public function getDepartmentLeaveList($user, array $filters)
    {
        $departmentId = $user->employee->department_id;
        $query = Leave::with(['user', 'file', 'employee'])
            ->whereHas('user.employee', fn($q) => $q->where('department_id', $departmentId));

        $this->applyFilters($query, $filters);

        return $query->select('leaves.*')
            ->orderByRaw('FIELD(status, 0, 1, 3, 2, 4)')
            ->orderBy('created_at', 'asc')
            ->paginate(10);
    }

    // 4. 查詢「全公司」請假紀錄（HR）
    public function getCompanyLeaveList(array $filters)
    {
        $query = Leave::with(['user', 'file']);

        $this->applyFilters($query, $filters);

        $leaves = $query->select('*')
            ->orderByRaw('FIELD(status, 1, 0, 3, 2, 4)')
            ->orderBy('created_at', 'asc')
            ->paginate(10);

        Log::info('Query Result:', ['leaves' => $leaves->items()]);

        return $leaves;
    }

    // 5. 更新單筆紀錄
    public function updateLeaveRequest(Leave $leave, array $data): Leave
    {
        $isUpdatingHours = isset($data['start_time'], $data['end_time']);
        $startTime = $leave->start_time;
        $endTime = $leave->end_time;
        $hours = $leave->leave_hours;
        $leaveTypeId = $data['leave_type_id'] ?? $leave->leave_type_id;

        if ($isUpdatingHours) {
            $startTime = Carbon::parse($data['start_time']);
            $endTime = Carbon::parse($data['end_time']);

            if ($startTime->greaterThanOrEqualTo($endTime)) {
                throw new \Exception("結束時間必須大於開始時間", 422);
            }

            $isOverlap = Leave::where('user_id', $leave->user_id)
                ->where('id', '!=', $leave->id)
                ->overlapping($startTime, $endTime)
                ->whereIn('status', [0, 1])
                ->exists();

            if ($isOverlap) {
                throw new \Exception("您的請假時間與已有的請假紀錄重疊，請重新調整時間範圍", 422);
            }

            $hours = $this->calculateHours($startTime, $endTime);
            if ($hours <= 0) {
                throw new \Exception("請假時間區間無效，請重新選擇有效的請假時段", 422);
            }

            $remainingHours = $this->getRemainingLeaveHours(
                $leaveTypeId,
                $leave->user_id,
                $startTime,
                $leave->id
            );
            if (!is_null($remainingHours) && $remainingHours < $hours) {
                throw new \Exception("剩餘時數不足，請重新修改請假區間", 422);
            }
        }

        // update leaverequest
        $leave->update([
            'leave_type_id' => $leaveTypeId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'leave_hours' => $hours,
            'reason' => $data['reason'] ?? $leave->reason,
            'status' => $data['status'] ?? $leave->status,
            'attachment' => $data['attachment'] ?? $leave->attachment,
        ]);

        return $leave->fresh();
    }

    // 6. 刪除請假記錄
    public function deleteLeaveRequest(int $leaveId, User $user): void
    {
        $leave = Leave::find($leaveId);

        if (!$leave || $leave->user_id !== $user->id) {
            throw new \Exception('查無此假單', 403);
        }

        if ($leave->status !== 0) {
            throw new \Exception('僅能刪除尚未審核的假單', 403);
        }

        if ($leave->attachment) {
            $this->fileService->deleteAttachment($leave->attachment);
        }

        $leave->delete();
    }

    // 7. 計算特殊假別剩餘小時數
    public function getRemainingLeaveHours($leaveTypeId, $userId, $leaveStartTime = null, $excludeLeaveId = null)
    {
        $leaveType = LeaveType::find($leaveTypeId);

        if (!$leaveType) {
            return null;
        }

        if ($leaveType->name === 'Annual Leave') {
            return $this->getRemainingAnnualLeaveHours($userId, $leaveStartTime, $excludeLeaveId);
        } elseif ($leaveType->name === 'Menstrual Leave') {
            return $this->getRemainingMenstrualLeaveHours($userId, $leaveStartTime, $excludeLeaveId);
        }

        return $this->getGenericLeaveHours($leaveTypeId, $userId, $excludeLeaveId);
    }

    // 8. 計算單天請假時數 (考慮上下班時間)
    private function calculateOneDayHours(string $start, string $end): float
    {
        $startTime = strtotime($start);
        $endTime = strtotime($end);

        if ($startTime >= $endTime) {
            return 0;
        }

        // work time
        $workStart = strtotime(date('Y-m-d', $startTime) . ' 09:00:00');
        $workEnd = strtotime(date('Y-m-d', $startTime) . ' 18:00:00');

        // Business Working Hours
        $startTime = max($startTime, $workStart);
        $endTime = min($endTime, $workEnd);

        if ($startTime >= $endTime) {
            return 0;
        }

        // count hours
        $hours = ($endTime - $startTime) / 3600;

        // Lunch Break Deduction
        $lunchStart = strtotime(date('Y-m-d', $startTime) . ' 12:00:00');
        $lunchEnd = strtotime(date('Y-m-d', $startTime) . ' 13:00:00');

        if ($startTime < $lunchEnd && $endTime > $lunchStart) {
            $hours -= 1;
        }

        return ceil($hours);
    }

    // 9. 計算跨天請假時數 (支援單日、跨日)
    private function calculateHours(string $startTime, string $endTime): float
    {
        $startDate = date('Y-m-d', strtotime($startTime));
        $endDate = date('Y-m-d', strtotime($endTime));

        if ($startDate === $endDate) {
            $hours = $this->calculateOneDayHours($startTime, $endTime);
            if ($hours < 1) {
                throw new \Exception("請假時間不在上班時間內，請重新選擇", 400);
            }
            return ceil($hours);
        }

        $totalHours = 0;

        // first
        $firstDayEnd = $startDate . ' 18:00:00';
        $totalHours += $this->calculateOneDayHours($startTime, $firstDayEnd);

        // mid 
        $current = date('Y-m-d', strtotime($startDate . ' +1 day'));
        while ($current < $endDate) {
            $dayStart = $current . ' 09:00:00';
            $dayEnd = $current . ' 18:00:00';
            $totalHours += $this->calculateOneDayHours($dayStart, $dayEnd);
            $current = date('Y-m-d', strtotime($current . ' +1 day'));
        }

        // last
        $lastDayStart = $endDate . ' 09:00:00';
        $totalHours += $this->calculateOneDayHours($lastDayStart, $endTime);

        if ($totalHours < 1) {
            throw new \Exception("請假時間不在上班時間內，請重新選擇", 400);
        }

        return ceil($totalHours);
    }

    // 8. 統一查詢結果及修改格式
    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereBetween('start_time', [$filters['start_date'] . ' 00:00:00', $filters['end_date'] . ' 23:59:59'])
                    ->orWhereBetween('end_time', [$filters['start_date'] . ' 00:00:00', $filters['end_date'] . ' 23:59:59'])
                    ->orWhere(function ($q) use ($filters) {
                        $q->where('start_time', '<=', $filters['start_date'] . ' 00:00:00')
                            ->where('end_time', '>=', $filters['end_date'] . ' 23:59:59');
                    });
            });

            if (!empty($filters['leave_type_id'])) {
                $query->whereHas('leaveType', function ($q) use ($filters) {
                    $q->where('id', $filters['leave_type_id']);
                });
            }

            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['employee_id'])) {
                $query->where('user_id', $filters['employee_id']);
            }

            if (!empty($filters['department_id'])) {
                $query->whereHas('employee', function ($q) use ($filters) {
                    $q->where('department_id', $filters['department_id']);
                });
            }
        }
    }

    // 9. 計算特休天數（依照勞基法規則）
    private function calculateAnnualLeaveDays($years, $months): int
    {
        switch (true) {
            case ($months >= 6 && $years < 1):
                return 3;
            case ($years >= 1 && $years < 2):
                return 7;
            case ($years >= 2 && $years < 3):
                return 10;
            case ($years >= 3 && $years < 5):
                return 14;
            case ($years >= 5 && $years < 10):
                return 15;
            case ($years >= 10):
                return min(15 + ($years - 10), 30);
            default:
                return 0;
        }
    }

    // 10. 計算員工特休時數(自動新增特休)
    public function getAnnualLeaveHours($userId, $leaveStartTime)
    {
        $profile = EmployeeProfile::where('employee_id', $userId)->first();

        if (!$profile || !$profile->hire_date) {
            return 0;
        }

        // 	Years of Experience
        $leaveDate = Carbon::parse($leaveStartTime);
        $years = $profile->getYearsOfServiceAttribute($leaveDate);
        $months = $profile->getMonthsOfServiceAttribute($leaveDate);

        // Calculate Annual Leave Hours
        $newAnnualLeaveDays = $this->calculateAnnualLeaveDays($years, $months);
        $newAnnualLeaveHours = $newAnnualLeaveDays * 8;

        return $newAnnualLeaveHours;
    }

    //  11. 剩餘特休時數
    public function getRemainingAnnualLeaveHours($userId, $leaveStartTime, $excludeLeaveId = null)
    {
        // check Leave time
        $leaveDate = Carbon::parse($leaveStartTime, 'Asia/Taipei');
        $year = $leaveDate->year;

        // get Annual Leave Hours
        $totalHours = $this->getAnnualLeaveHours($userId, $leaveDate);

        // get be used Annual Leave Hours
        $usedHoursQuery = Leave::where('user_id', $userId)
            ->whereHas('leaveType', function ($query) {
                $query->where('name', 'Annual Leave');
            })
            ->whereIn('status', [0, 1, 3])
            ->whereYear('start_time', $year);

        // Exclude Leave
        if (!is_null($excludeLeaveId)) {
            $usedHoursQuery->where('id', '!=', $excludeLeaveId);
        }

        $usedHoursSum = $usedHoursQuery->sum('leave_hours');

        $remainingHours = max($totalHours - $usedHoursSum, 0);

        return $remainingHours;
    }

    // 12. 計算生理假剩餘時數
    public function getRemainingMenstrualLeaveHours($userId, $leaveStartTime, $excludeLeaveId = null)
    {
        $leaveDate = Carbon::parse($leaveStartTime, 'Asia/Taipei');
        $maxHours = LeaveType::where('name', 'Menstrual Leave')->value('total_hours') ?? 8;

        // Current Month	
        $thisMonthStart = $leaveDate->copy()->startOfMonth();
        $thisMonthEnd = $leaveDate->copy()->endOfMonth();

        // approved Menstrual Leave Hours
        $approvedHours = Leave::where('user_id', $userId)
            ->whereHas('leaveType', function ($query) {
                $query->where('name', 'Menstrual Leave');
            })
            ->whereIn('status', [1, 3])
            ->whereBetween('start_time', [$thisMonthStart, $thisMonthEnd])
            ->sum('leave_hours');

        // Pending Leave Hours
        $pendingQuery = Leave::where('user_id', $userId)
            ->whereHas('leaveType', function ($query) {
                $query->where('name', 'Menstrual Leave');
            })
            ->where('status', 0)
            ->whereBetween('start_time', [$thisMonthStart, $thisMonthEnd]);
        if (!is_null($excludeLeaveId)) {
            $pendingQuery->where('id', '!=', $excludeLeaveId);
        }

        $pendingHours = $pendingQuery->sum('leave_hours');

        // reset hours
        $resetHours = $this->resetMenstrualLeaveHours($userId, $leaveStartTime);

        // max 8 hours
        $totalAvailableHours = min($maxHours, $resetHours + $maxHours);

        // approved + Pending
        $usedHours = $approvedHours + $pendingHours;

        $remainingHours = max($totalAvailableHours - $usedHours, 0);

        return $remainingHours;
    }

    // 13. 重置員工生理假剩餘時數
    public function resetMenstrualLeaveHours($userId, $leaveStartTime)
    {
        // Current Month
        $leaveDate = Carbon::parse($leaveStartTime, 'Asia/Taipei');

        // Last Month
        $lastMonthStart = $leaveDate->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $leaveDate->copy()->subMonth()->endOfMonth();

        // 8 hours per month
        $maxHours = LeaveType::where('name', 'Menstrual Leave')->value('total_hours') ?? 8;

        // Used Hours
        $lastMonthUsedHours = Leave::where('user_id', $userId)
            ->whereHas('leaveType', function ($query) {
                $query->where('name', 'Menstrual Leave');
            })
            ->whereIn('status', [0, 1, 3])
            ->whereBetween('start_time', [$lastMonthStart, $lastMonthEnd])
            ->sum('leave_hours');

        // Max 8 hours
        $resetHours = min($maxHours, $lastMonthUsedHours);

        return $resetHours;
    }

    // 14. 計算剩餘的假別時數
    public function getGenericLeaveHours($leaveTypeId, $userId, $leaveStartTime = null, $excludeLeaveId = null)
    {
        $leaveType = LeaveType::find($leaveTypeId);

        if (!$leaveType) {
            return 0;
        }

        if (is_null($leaveType->total_hours)) {
            return null;
        }

        $totalHours = $leaveType->total_hours;
        $usedHoursQuery = Leave::where('user_id', $userId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereIn('status', [0, 1, 3]);

        if (!is_null($excludeLeaveId)) {
            $usedHoursQuery->where('id', '!=', $excludeLeaveId);
        }

        $usedHours = $usedHoursQuery->sum('leave_hours');

        return max($totalHours - $usedHours, 0);
    }
}
