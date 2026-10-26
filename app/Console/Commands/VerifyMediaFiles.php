<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Consistency check between media rows and files: missing files, checksum mismatches and stored files without a row
 * (e.g. left by an interrupted upload). Read-only unless --delete-orphans is given; rows are never changed.
 */
class VerifyMediaFiles extends Command
{
    protected $signature = 'media:verify {--delete-orphans : حذف الملفات التي لا يقابلها سجل}';

    protected $description = 'التحقق من تطابق ملفات الوسائط مع سجلاتها (الوجود والبصمة والملفات اليتيمة)';

    public function handle(): int
    {
        $disk = Storage::disk(config('media.disk'));
        $known = [];
        $problems = 0;
        MediaItem::query()->where('disk', config('media.disk'))->orderBy('id')->each(function (MediaItem $media) use ($disk, &$known, &$problems): void {
            foreach (array_filter([$media->path, $media->display_path]) as $path) {
                $known[$path] = true;
                if (! $disk->exists($path)) {
                    $this->error("ملف مفقود: #{$media->id} {$path}");
                    $problems++;
                }
            }
            if ($disk->exists($media->path) && ! hash_equals($media->checksum, hash('sha256', $disk->get($media->path)))) {
                $this->error("بصمة غير مطابقة: #{$media->id} {$media->path}");
                $problems++;
            }
        });
        $orphans = array_values(array_filter($disk->allFiles(), fn (string $path) => ! isset($known[$path])));
        foreach ($orphans as $path) {
            $this->warn("ملف بلا سجل: {$path}");
        }
        if ($orphans !== [] && $this->option('delete-orphans')) {
            $disk->delete($orphans);
            $this->info('حُذفت الملفات اليتيمة: '.count($orphans));
        }
        $this->info('اكتمل الفحص: '.($problems + count($orphans)).' ملاحظة.');

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }
}
