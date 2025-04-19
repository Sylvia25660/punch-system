<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Models\File;
use Illuminate\Support\Str;

class FileService
{
    public function UploadLeaveAttachment(UploadedFile $file, int $userId): File
    {
        // only attachment name
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $attachmentPath = $file->storeAs('attachments', $filename, 'public');

        if (!$attachmentPath) {
            throw new \Exception('附件儲存失敗');
        }

        return File::create([
            'user_id' => $userId,
            'leave_id' => null,
            'leave_attachment' => str_replace('public/', '', $attachmentPath),
        ]);
    }
}
