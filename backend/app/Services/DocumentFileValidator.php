<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class DocumentFileValidator
{
    public function inspect(UploadedFile $file, string $key): array
    {
        $fail = fn ($message) => throw ValidationException::withMessages([$key => $message]);
        $name = $file->getClientOriginalName();
        if (preg_match('/[\x00-\x1f\x7f\/\\\\]/', $name) || $name === '.' || $name === '..' || str_contains($name, ':') || mb_strlen($name) > 240 || preg_match('/[. ]$/', $name)) {
            $fail('Unsafe filename. Rename the document before uploading.');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($ext, config('documents.extensions'))) {
            $fail('Unsupported document format.');
        }
        $path = $file->getRealPath();
        $head = file_get_contents($path, false, null, 0, 8192);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($ext === 'pdf' && (! str_starts_with($head, '%PDF-') || $mime !== 'application/pdf')) {
            $fail('This file is not a valid PDF.');
        }
        if (in_array($ext, ['docx', 'xlsx'])) {
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                $fail('Unable to open this Office document.');
            }
            try {
                $required = $ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
                if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName($required) === false) {
                    $fail('The Office file content does not match its extension.');
                }
                if ($zip->numFiles > 5000) {
                    $fail('Office archive contains too many entries.');
                }
                $size = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->statIndex($i);
                    $size += $entry['size'];
                    $entryName = str_replace('\\', '/', $entry['name']);
                    if (str_contains($entryName, '../') || str_starts_with($entryName, '/') || preg_match('/(?:vbaProject\.bin|\.(?:exe|dll|bat|cmd|ps1|js|vbs))$/i', $entryName)) {
                        $fail('Unsafe or macro-enabled Office archive rejected.');
                    }
                    if ($size > config('documents.max_expanded_mb') * 1024 * 1024) {
                        $fail('Expanded Office document exceeds the safety limit.');
                    }
                    if (($entry['encryption_method'] ?? 0) !== 0 || preg_match('/^[a-z]:/i', $entryName)) {
                        $fail('Encrypted or unsafe Office archive rejected.');
                    }
                }
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    if (preg_match('/\.(xml|rels)$/i', $entryName) && preg_match('/<!DOCTYPE|<!ENTITY/i', $zip->getFromIndex($i))) {
                        $fail('Office XML entity declarations are not accepted.');
                    }
                }
            } finally {
                $zip->close();
            }
        }
        if ($ext === 'xls' && substr($head, 0, 8) !== hex2bin('D0CF11E0A1B11AE1')) {
            $fail('This file is not a legacy Excel workbook.');
        }
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'])) {
            $info = @getimagesize($path);
            $allowed = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
            if (! $info || $mime !== $allowed[$ext] || ($info['mime'] ?? null) !== $allowed[$ext]) {
                $fail('Image content does not match its extension.');
            }
            if ($info[0] * $info[1] > 40000000) {
                $fail('Image exceeds the 40-megapixel safety limit.');
            }
        }
        if ($ext === 'csv') {
            $isUtf16 = str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF");
            if ($isUtf16) {
                $head = mb_convert_encoding($head, 'UTF-8', str_starts_with($head, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
            }
            if (str_contains($head, "\0") || (! $isUtf16 && ! in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'text/x-csv']))) {
                $fail('CSV must be a readable text file, not binary or executable content.');
            }
            if (preg_match('/<\?(?:php|=)|<script|^\s*(?:MZ|#!)/i', $head)) {
                $fail('Executable content is not accepted as CSV.');
            }
        }

        return ['original_filename' => $name, 'extension' => $ext, 'mime_type' => $mime, 'file_size' => $file->getSize(), 'checksum' => hash_file('sha256',$path)];
    }
}
