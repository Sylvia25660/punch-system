<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeaveListRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    // 查詢請假紀錄格式驗證
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'leave_type_id' => ['nullable', 'exists:leave_types,id'],
            'status' => ['nullable', 'integer', 'in:0,1,2,3,4,5'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'rejected' => ['nullable', 'integer'],
            'leave_hours' => ['nullable', 'numeric'],
            'created_at' => ['nullable', 'date'],
        ];
    }
}
