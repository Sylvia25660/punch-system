<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeaveApplyRequest extends FormRequest
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
    public function rules(): array
    {
        return [
            'start_time' => ['required', 'date', 'before:end_time'],  // 必填，格式必須是日期時間
            'end_time' => ['required', 'date', 'after_or_equal:start_time'],  // 必填，要晚於或等於start_time
            'leave_type_id' => ['required', 'exists:leave_types,id'],  // 驗證 leave_type 是 leave_types 表中的有效 id
            'reason' => ['required', 'string'],  // 必填，必須是文字
            'attachment' => 'nullable|file|max:10240',  // 驗證 attachment 是 files 表中的有效 id
        ];
    }
}
