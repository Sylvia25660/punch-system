<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Models\File;
use App\Models\Leave;
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

    public function replaceLeaveAttachment(UploadedFile $file, Leave $leave, int $userId): File
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $attachmentPath = $file->storeAs('attachments', $filename, 'public');

        $oldFile = File::find($leave->attachment);

        if ($oldFile && Storage::exists($oldFile->leave_attachment)) {
            Storage::delete($oldFile->leave_attachment);
        }

        return $oldFile
            ? tap($oldFile)->update(['leave_attachment' => str_replace('public/', '', $attachmentPath)])
            : File::create([
                'user_id' => $userId,
                'leave_id' => $leave->id,
                'leave_attachment' => str_replace('public/', '', $attachmentPath),
            ]);
    }

    public function deleteAttachment(?int $fileId): void
    {
        if (!$fileId) return;

        $file = File::find($fileId);
        if ($file && Storage::exists($file->leave_attachment)) {
            Storage::delete($file->leave_attachment);
        }

        $file?->delete();
    }
}