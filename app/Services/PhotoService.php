<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PhotoService
{
    public function path(string $std): ?string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $std)) {
            return null;
        }$p = storage_path('app/private/photos/'.$std.'.jpg');

        return is_file($p) ? $p : null;
    }

    public function count(): int
    {
        return count(glob(storage_path('app/private/photos/*.jpg')) ?: []);
    }

    public function save(string $std, UploadedFile $file): void
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $std)) {
            RequestService::fail('Invalid student number.');
        }
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP]) || $info[0] * $info[1] > 16000000) {
            RequestService::fail('Use a JPEG, PNG or WebP photo up to 16 megapixels.');
        }
        $img = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getRealPath()),IMAGETYPE_PNG => @imagecreatefrompng($file->getRealPath()),default => @imagecreatefromwebp($file->getRealPath())
        };
        if (! $img) {
            RequestService::fail('Image could not be decoded.');
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file->getRealPath());
            $rot = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
            if ($rot) {
                $img = imagerotate($img, $rot, 0);
            }
        }
        $scale = min(1, 800 / max(imagesx($img), imagesy($img)));
        $w = max(1, (int) round(imagesx($img) * $scale));
        $h = max(1, (int) round(imagesy($img) * $scale));
        $out = imagecreatetruecolor($w, $h);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));
        Storage::disk('local')->makeDirectory('photos');
        $dir = storage_path('app/private/photos');
        $tmp = tempnam($dir, 'upload-');
        try {
            if (! imagejpeg($out, $tmp, 85) || ! rename($tmp, $dir.'/'.$std.'.jpg')) {
                throw new \RuntimeException('Could not save photo.');
            }chmod($dir.'/'.$std.'.jpg', 0600);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }imagedestroy($img);
            imagedestroy($out);
        }
    }
}
