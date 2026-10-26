<?php

namespace App\Services\Community;

use App\Models\Family;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * File handling for the media library. The type is decided by the file's content (finfo signature), never by the
 * client: it must be on the allow-list and agree with the extension. Files are stored under generated names on a private
 * disk. Images get a display copy without metadata (EXIF/GPS, text chunks): re-encoded and resized when GD is available,
 * otherwise copied with metadata segments removed; the original is kept unchanged for the managers.
 */
class MediaStorage
{
    /**
     * @return array{mime: string, extension: string, category: string, size: int, checksum: string, width: ?int, height: ?int, original_name: string}
     */
    public function inspect(UploadedFile $file): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['file' => $message]);
        if (! $file->isValid() || ! is_file($file->getRealPath())) {
            $fail('تعذر استلام الملف.');
        }
        $path = $file->getRealPath();
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        $type = config('media.types')[$mime] ?? null;
        $extension = strtolower($file->getClientOriginalExtension());
        if (! $type) {
            $fail('نوع الملف غير مسموح. المسموح: صور JPEG وPNG، ووثائق PDF، وتسجيلات MP3 وOGG.');
        }
        [$category, $extensions] = $type;
        if (! in_array($extension, $extensions, true)) {
            $fail('امتداد الملف لا يطابق محتواه.');
        }
        $size = (int) filesize($path);
        if ($size === 0 || $size > config('media.max_kb.'.$category) * 1024) {
            $fail('حجم الملف يتجاوز الحد المسموح لهذا النوع.');
        }
        $head = (string) file_get_contents($path, false, null, 0, 1024);
        // Markup or script hidden behind a valid signature is refused outright.
        if (preg_match('/<\s*(script|html|svg|\?php|iframe)/i', $head)) {
            $fail('يحتوي الملف على شيفرة غير مسموح بها.');
        }
        $width = $height = null;
        if ($category === 'image') {
            $info = @getimagesize($path);
            if (! $info || $info['mime'] !== $mime) {
                $fail('الصورة تالفة أو غير صالحة.');
            }
            [$width, $height] = [$info[0], $info[1]];
            if ($width < 1 || $height < 1 || max($width, $height) > config('media.max_pixels_side')) {
                $fail('أبعاد الصورة غير مسموح بها.');
            }
        }
        if ($mime === 'application/pdf' && ! str_starts_with($head, '%PDF-')) {
            $fail('ملف PDF غير صالح.');
        }

        return ['mime' => $mime, 'extension' => $extensions[0], 'category' => $category, 'size' => $size, 'checksum' => hash_file('sha256', $path),
            'width' => $width, 'height' => $height, 'original_name' => $this->safeName($file->getClientOriginalName())];
    }

    /**
     * Writes the original (and an image display copy) under a generated name; nothing of the client's name is used.
     *
     * @param  array{mime: string, extension: string, category: string}  $inspected
     * @return array{disk: string, uuid: string, path: string, display_path: ?string}
     */
    public function store(Family $family, UploadedFile $file, array $inspected): array
    {
        $disk = config('media.disk');
        $uuid = (string) Str::uuid();
        $base = $family->id.'/'.substr($uuid, 0, 2).'/'.$uuid;
        $path = $base.'.'.$inspected['extension'];
        Storage::disk($disk)->put($path, (string) file_get_contents($file->getRealPath()));
        $display = null;
        try {
            if ($inspected['category'] === 'image') {
                $display = $base.'-display.'.$inspected['extension'];
                Storage::disk($disk)->put($display, $this->displayCopy((string) file_get_contents($file->getRealPath()), $inspected['mime']));
            }
        } catch (\Throwable $exception) {
            $this->delete($disk, [$path, $display]);
            throw $exception;
        }

        return ['disk' => $disk, 'uuid' => $uuid, 'path' => $path, 'display_path' => $display];
    }

    /** @param  array<int, ?string>  $paths */
    public function delete(string $disk, array $paths): void
    {
        foreach (array_filter($paths) as $path) {
            Storage::disk($disk)->delete($path);
        }
    }

    /** Image bytes without metadata, resized to the display size when GD is available. */
    public function displayCopy(string $bytes, string $mime): string
    {
        if (function_exists('imagecreatefromstring') && ($image = @imagecreatefromstring($bytes))) {
            [$width, $height] = [imagesx($image), imagesy($image)];
            $max = config('media.display_max');
            if (max($width, $height) > $max) {
                $scale = $max / max($width, $height);
                $image = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))) ?: $image;
            }
            if ($mime === 'image/png') {
                imagealphablending($image, false);
                imagesavealpha($image, true);
            }
            ob_start();
            $mime === 'image/png' ? imagepng($image, null, 6) : imagejpeg($image, null, 85);
            $bytes = (string) ob_get_clean();
        }

        // Also applied after GD, which writes its own comment segment.
        return $mime === 'image/png' ? $this->stripPng($bytes) : $this->stripJpeg($bytes);
    }

    /** Removes APP1–APP15 (EXIF, XMP, IPTC…) and comment segments; keeps JFIF (APP0) and Adobe colour (APP14). */
    public function stripJpeg(string $bytes): string
    {
        if (! str_starts_with($bytes, "\xFF\xD8")) {
            return $bytes;
        }
        $out = "\xFF\xD8";
        $offset = 2;
        $length = strlen($bytes);
        while ($offset + 4 <= $length && $bytes[$offset] === "\xFF") {
            $marker = ord($bytes[$offset + 1]);
            if ($marker === 0xDA) {
                return $out.substr($bytes, $offset);
            }
            $segment = unpack('n', substr($bytes, $offset + 2, 2))[1] + 2;
            $keep = ! (($marker >= 0xE1 && $marker <= 0xEF && $marker !== 0xEE) || $marker === 0xFE);
            if ($keep) {
                $out .= substr($bytes, $offset, $segment);
            }
            $offset += $segment;
        }

        return $out.substr($bytes, $offset);
    }

    /** Keeps only the chunks needed to render the image; text, EXIF and time chunks are dropped. */
    public function stripPng(string $bytes): string
    {
        $signature = "\x89PNG\r\n\x1A\n";
        if (! str_starts_with($bytes, $signature)) {
            return $bytes;
        }
        $keep = ['IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS', 'gAMA', 'cHRM', 'sRGB', 'iCCP', 'sBIT', 'pHYs', 'bKGD'];
        $out = $signature;
        $offset = 8;
        while ($offset + 12 <= strlen($bytes)) {
            $length = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);
            if (in_array($type, $keep, true)) {
                $out .= substr($bytes, $offset, $length + 12);
            }
            $offset += $length + 12;
            if ($type === 'IEND') {
                break;
            }
        }

        return $out;
    }

    /** Display-only version of the client's file name: no directories, no control characters. */
    public function safeName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', basename(str_replace('\\', '/', $name))) ?? '';

        return mb_substr(trim($name) !== '' ? trim($name) : 'file', 0, 200);
    }
}
