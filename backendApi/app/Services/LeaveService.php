<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\LeaveType;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\EmployeeProfile;

class LeaveService
{
    const WORK_HOURS_PER_DAY = 8;  // 每天上班時數

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
            ->orderByRaw('FIELD(status, 0, 1, 3, 2, 4)') // 依照 0 -> 1 -> 3 -> 2 -> 4 排序
            ->orderBy('created_at', 'asc') // 申請時間越早，排越前
            ->paginate(10);
    }

    // 3. 查詢「部門」請假紀錄（主管 & HR）
    public function getDepartmentLeaveList($user, array $filters)
    {
        $query = Leave::with(['user', 'file', 'employee']) // ✅ 同時載入 `user` 和 `file`
            ->whereHas('user.employee', fn($q) => $q->where('department_id', $user->employee->department_id));

        // ✅ 確保過濾條件生效
        $this->applyFilters($query, $filters);

        return $query->select('leaves.*')
            ->orderByRaw('FIELD(status, 0, 1, 3, 2, 4)') // 依照 0 -> 1 -> 3 -> 2 -> 4 排序
            ->orderBy('created_at', 'asc') // 申請時間越早，排越前
            ->paginate(10);
    }

    // 4. 查詢「全公司」請假紀錄（HR）
    public function getCompanyLeaveList(array $filters)
    {
        // Log::info('getCompanyLeaveList called with filters:', $filters);

        $query = Leave::with(['user', 'file']); // ✅ 同時載入 `user` 和 `file` 和 `employee`

        // ✅ 確保過濾條件生效
        $this->applyFilters($query, $filters);

        // 查詢所有請假單，分頁 10 筆
        $leaves = $query->select('*')
            ->orderByRaw('FIELD(status, 1, 0, 3, 2, 4)') // 指定狀態排序順序
            ->orderBy('created_at', 'asc') // 其次依據 start_time 排序
            ->paginate(10);

        Log::info('Query Result:', ['leaves' => $leaves->items()]);

        return $leaves;
    }

    // 5. 更新單筆紀錄
    public function updateLeave(Leave $leave, array $data, $user, $leaveStartTime): Leave
    {
        // 1️⃣ **是否有修改請假時數**
        $isUpdatingHours = isset($data['start_time'], $data['end_time']);

        if ($isUpdatingHours) {
            $startTime = Carbon::parse($data['start_time']);
            $endTime = Carbon::parse($data['end_time']);

            if ($startTime->greaterThanOrEqualTo($endTime)) {
                throw new \Exception("請假結束時間必須大於開始時間", 400);
            }
        }

        // 2️⃣ **取得假別資訊**
        $leaveTypeId = $data['leave_type_id'] ?? $leave->leave_type_id;
        $leaveType = LeaveType::find($leaveTypeId);

        if (!$leaveType) {
            throw new \Exception("請假類型無效", 400);
        }

        // 3️⃣ **生理假檢查**
        if ($leaveType->name === 'Menstrual Leave' && $user->gender !== 'female') {
            throw new \Exception('您無法申請生理假', 403);
        }

        // 4️⃣ **計算新的請假時數**
        $hours = $isUpdatingHours
            ? $this->calculateHours($data['start_time'], $data['end_time'])
            : $leave->leave_hours;

        if ($isUpdatingHours && $hours <= 0) {
            throw new \Exception("請假時間區間無效，請重新選擇有效的請假時段", 400);
        }

        // 6️⃣ **檢查剩餘請假時數**
        if ($isUpdatingHours) {
            $remainingHours = $this->getRemainingLeaveHours($leaveTypeId, $leave->user_id, $leaveStartTime, $leave->id);

            if ($remainingHours < $hours) {
                throw new \Exception("剩餘時數不足，請重新修改請假區間", 400);
            }
        }

        // 7️⃣ **更新 `leaves` 表**
        $leave->update([
            'leave_type_id' => $leaveTypeId,
            'start_time' => $data['start_time'] ?? $leave->start_time,
            'end_time' => $data['end_time'] ?? $leave->end_time,
            'leave_hours' => $hours,
            'reason' => $data['reason'] ?? $leave->reason,
            'status' => $data['status'] ?? $leave->status,
            'attachment' => isset($data['attachment']) ? $data['attachment'] : null,
        ]);

        return $leave->fresh();
    }

    // 5. 計算跨天請假時數 (支援單日、跨日)
    private function calculateHours(string $startTime, string $endTime): float
    {
        $startDate = date('Y-m-d', strtotime($startTime));
        $endDate = date('Y-m-d', strtotime($endTime));

        if ($startDate === $endDate) {
            // 同一天直接算時數
            $hours = $this->calculateOneDayHours($startTime, $endTime);
            if ($hours < 1) {
                throw new \Exception("請假時間不在上班時間內，請重新選擇", 400);
            }
            return ceil($hours); // ✅ 無條件進位
        }

        $totalHours = 0;

        // 🧮 第一天：從開始時間到當天18:00
        $firstDayEnd = $startDate . ' 18:00:00';
        $totalHours += $this->calculateOneDayHours($startTime, $firstDayEnd);

        // 🧮 中間天（整天請假）
        $current = date('Y-m-d', strtotime($startDate . ' +1 day'));
        while ($current < $endDate) {
            $dayStart = $current . ' 09:00:00';
            $dayEnd = $current . ' 18:00:00';
            $totalHours += $this->calculateOneDayHours($dayStart, $dayEnd);
            $current = date('Y-m-d', strtotime($current . ' +1 day'));
        }

        // 🧮 最後一天：從 09:00 到實際結束時間
        $lastDayStart = $endDate . ' 09:00:00';
        $totalHours += $this->calculateOneDayHours($lastDayStart, $endTime);

        if ($totalHours < 1) {
            throw new \Exception("請假時間不在上班時間內，請重新選擇", 400);
        }

        return ceil($totalHours); // ✅ 最後無條件進位成整數小時
    }

    // 6. 計算單天請假時數 (考慮上下班時間)
    private function calculateOneDayHours(string $start, string $end): float
    {
        $startTime = strtotime($start);
        $endTime = strtotime($end);

        if ($startTime >= $endTime) {
            return 0;
        }

        // 如果時間不符合上班時間(可依公司規定調整)
        $workStart = strtotime(date('Y-m-d', $startTime) . ' 09:00:00');
        $workEnd = strtotime(date('Y-m-d', $startTime) . ' 18:00:00');

        // 限制只計算上班時段
        $startTime = max($startTime, $workStart);
        $endTime = min($endTime, $workEnd);

        if ($startTime >= $endTime) {
            return 0;
        }

        // 計算小時數 (包含中午休息時間可以加上去)
        $hours = ($endTime - $startTime) / 3600;

        // 例如：12:00-13:00是午休，這段不算工時
        $lunchStart = strtotime(date('Y-m-d', $startTime) . ' 12:00:00');
        $lunchEnd = strtotime(date('Y-m-d', $startTime) . ' 13:00:00');

        if ($startTime < $lunchEnd && $endTime > $lunchStart) {
            $hours -= 1;  // 扣掉午休1小時
        }

        return ceil($hours);
    }

    // 7. 計算特殊假別剩餘小時數
    public function getRemainingLeaveHours($leaveTypeId, $userId, $leaveStartTime = null, $excludeLeaveId = null)
    {
        $leaveType = LeaveType::find($leaveTypeId);

        if (!$leaveType) {
            return null; // 假別不存在
        }

        // 針對特休和生理假使用專門的方法計算
        if ($leaveType->name === 'Annual Leave') {
            return $this->getRemainingAnnualLeaveHours($userId, $leaveStartTime, $excludeLeaveId);
        } elseif ($leaveType->name === 'Menstrual Leave') {
            return $this->getRemainingMenstrualLeaveHours($userId, $leaveStartTime, $excludeLeaveId);
        }

        // 其他假別使用通用計算方式
        return $this->getGenericLeaveHours($leaveTypeId, $userId, $excludeLeaveId);
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

            if (!empty($filters['leave_type'])) {
                $query->whereHas('leaveType', function ($q) use ($filters) {
                    $q->where('id', $filters['leave_type']);
                });
            }

            if (isset($filters['status'])) { // 檢查 status 是否存在(防止0被empty過濾掉改使用isset)
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
                return 0;  // 不滿6個月沒特休
        }
    }

    // 10. 計算員工特休時數(自動新增特休)
    public function getAnnualLeaveHours($userId, $leaveStartTime)
    {
        $profile = EmployeeProfile::where('employee_id', $userId)->first();

        if (!$profile || !$profile->hire_date) {
            return 0;
        }

        // ✅ **動態計算年資**
        $leaveDate = Carbon::parse($leaveStartTime);
        $years = $profile->getYearsOfServiceAttribute($leaveDate); // ✅ 使用 `getYearsOfServiceAttribute()`
        $months = $profile->getMonthsOfServiceAttribute($leaveDate); // ✅ 也計算月數


        // ✅ **計算該年度可請的特休天數**
        $newAnnualLeaveDays = $this->calculateAnnualLeaveDays($years, $months);
        $newAnnualLeaveHours = $newAnnualLeaveDays * 8;


        return $newAnnualLeaveHours;
    }

    //  11. 剩餘特休時數
    public function getRemainingAnnualLeaveHours($userId, $leaveStartTime, $excludeLeaveId = null)
    {
        // ✅ **解析使用者請假的時間**
        $leaveDate = Carbon::parse($leaveStartTime, 'Asia/Taipei');
        $year = $leaveDate->year; // 取得請假年份

        // ✅ **獲取該年份的特休時數**
        $totalHours = $this->getAnnualLeaveHours($userId, $leaveDate);

        // ✅ **查詢該年度已請的特休時數**
        $usedHoursQuery = Leave::where('user_id', $userId)
            ->whereHas('leaveType', function ($query) {
                $query->where('name', 'Annual Leave');
            })
            ->whereIn('status', [0, 1, 3]) // ✅ 只計算「待審核、已批准」的假單
            ->whereYear('start_time', $year); // ✅ 確保是當年度的特休

        // 若為編輯假單，排除當前假單
        if (!is_null($excludeLeaveId)) {
            $usedHoursQuery->where('id', '!=', $excludeLeaveId);
        }

        $usedHoursSum = $usedHoursQuery->sum('leave_hours');

        // ✅ **確保特休時數不為負數**
        $remainingHours = max($totalHours - $usedHoursSum, 0);

        return $remainingHours;
    }

    // 12. 計算生理假剩餘時數
    public function getRemainingMenstrualLeaveHours($userId, $leaveStartTime, $excludeLeaveId = null)
    {
        $leaveDate = Carbon::parse($leaveStartTime, 'Asia/Taipei'); // ✅ 依據使用者輸入的時間來決定月份
        $maxHours = LeaveType::where('name', 'Menstrual Leave')->value('total_hours') ?? 8;

        // ✅ **當月範圍**
        $thisMonthStart = $leaveDate->copy()->startOfMonth();
        $thisMonthEnd = $leaveDate->copy()->endOfMonth();


        // ✅ **計算當月已批准的請假時數**
        $approvedHours = Leave::where('user_id', $userId)
            ->whereHas('leaveType', function ($query) {
                $query->where('name', 'Menstrual Leave');
            })
            ->whereIn('status', [1, 3])
            ->whereBetween('start_time', [$thisMonthStart, $thisMonthEnd])
            ->sum('leave_hours');


        // ✅ **計算當月待審核的請假時數**
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

        // ✅ **補回的生理假時數**
        $resetHours = $this->resetMenstrualLeaveHours($userId, $leaveStartTime);

        // ✅ **當月總額度 = 8 小時 + 上個月請假時數（最多 8 小時）**
        $totalAvailableHours = min($maxHours, $resetHours + $maxHours);

        // ✅ **計算總已請假時數**
        $usedHours = $approvedHours + $pendingHours;

        // ✅ **計算剩餘可請時數**
        $remainingHours = max($totalAvailableHours - $usedHours, 0);

        return $remainingHours;
    }

    // 13. 重置員工生理假剩餘時數
    public function resetMenstrualLeaveHours($userId, $leaveStartTime)
    {
        // ✅ **根據使用者輸入的請假時間，決定該請假屬於哪一個月份**
        $leaveDate = Carbon::parse($leaveStartTime, 'Asia/Taipei');

        // ✅ **找「上個月」的時間範圍**
        $lastMonthStart = $leaveDate->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $leaveDate->copy()->subMonth()->endOfMonth();


        // ✅ **每個月固定 8 小時**
        $maxHours = LeaveType::where('name', 'Menstrual Leave')->value('total_hours') ?? 8;

        // ✅ **計算上個月已使用的生理假時數**
        $lastMonthUsedHours = Leave::where('user_id', $userId)
            ->whereHas('leaveType', function ($query) {
                $query->where('name', 'Menstrual Leave');
            })
            ->whereIn('status', [0, 1, 3]) // ✅ 包含「待審核」、「主管通過」、「HR 通過」
            ->whereBetween('start_time', [$lastMonthStart, $lastMonthEnd])
            ->sum('leave_hours');


        // ✅ **當月的總額度 = 8 小時 + 上個月使用的時數（最多 8 小時）**
        $resetHours = min($maxHours, $lastMonthUsedHours);

        return $resetHours;
    }

    // 14. 計算剩餘的假別時數
    public function getGenericLeaveHours($leaveTypeId, $userId, $leaveStartTime = null, $excludeLeaveId = null)
    {
        $leaveType = LeaveType::find($leaveTypeId);

        if (!$leaveType) {
            Log::warning("⚠️ 假別 ID {$leaveTypeId} 不存在，回傳 0");
            return 0;
        }

        if (is_null($leaveType->total_hours)) {
            Log::warning("⚠️ 假別 `{$leaveType->name}` 沒有時數上限，回傳 5000");
            return 5000;
        }

        // **計算已使用時數**
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
