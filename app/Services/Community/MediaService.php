<?php

namespace App\Services\Community;

use App\Models\CommunityEntity;
use App\Models\Diwan;
use App\Models\Family;
use App\Models\MediaAttachment;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Uploads, replaces, attaches and deletes media library files. Metadata, links, publication and audit go through
 * CommunityContentService like every other module. Files are written before the database row and removed again if
 * the row is not saved; replaced or deleted files are removed only after the transaction commits.
 */
class MediaService
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly CommunityContentService $content,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $governance
     */
    public function upload(User $user, CommunityEntity $owner, UploadedFile $file, array $fields, array $governance): MediaItem
    {
        abort_unless(Gate::forUser($user)->allows('update', $owner), 403);
        $inspected = $this->storage->inspect($file);
        $this->assertNotDuplicate($owner->family_id, $inspected['checksum']);
        $stored = $this->storage->store($owner->family, $file, $inspected);
        $media = (new MediaItem)->forceFill([
            'family_id' => $owner->family_id, 'uuid' => $stored['uuid'], 'disk' => $stored['disk'], 'path' => $stored['path'], 'display_path' => $stored['display_path'],
            ...collect($inspected)->only(['original_name', 'extension', 'size', 'checksum', 'width', 'height', 'category'])->all(), 'mime_type' => $inspected['mime'],
        ]);
        try {
            return $this->content->save($user, $owner, $media, $fields, $governance);
        } catch (Throwable $exception) {
            $this->storage->delete($stored['disk'], [$stored['path'], $stored['display_path']]);
            throw $exception instanceof UniqueConstraintViolationException ? $this->duplicate() : $exception;
        }
    }

    /** Swaps the file of an existing item (same category); the old file is removed after commit. */
    public function replace(User $user, MediaItem $media, UploadedFile $file): void
    {
        $owner = $media->ownerEntity;
        $ability = in_array($media->publication_status, [MediaItem::STATUS_PUBLISHED, MediaItem::STATUS_ARCHIVED], true) ? 'publish' : 'update';
        abort_unless(Gate::forUser($user)->allows($ability, $owner), 403);
        $inspected = $this->storage->inspect($file);
        if ($inspected['category'] !== $media->category) {
            throw ValidationException::withMessages(['file' => 'يجب أن يكون الملف البديل من النوع نفسه ('.MediaItem::CATEGORIES[$media->category].').']);
        }
        $this->assertNotDuplicate($media->family_id, $inspected['checksum'], $media->id);
        $stored = $this->storage->store($owner->family, $file, $inspected);
        $old = [$media->disk, [$media->path, $media->display_path]];
        try {
            DB::transaction(function () use ($user, $media, $owner, $inspected, $stored, $old): void {
                Family::query()->whereKey($owner->family_id)->lockForUpdate()->firstOrFail();
                $before = $this->content->snapshot($media);
                $media->forceFill([
                    'disk' => $stored['disk'], 'path' => $stored['path'], 'display_path' => $stored['display_path'], 'mime_type' => $inspected['mime'],
                    ...collect($inspected)->only(['original_name', 'extension', 'size', 'checksum', 'width', 'height'])->all(),
                ])->save();
                $this->audit->record($user, $owner->family, $media, 'file_replaced', $before, $this->content->snapshot($media));
                DB::afterCommit(fn () => $this->storage->delete($old[0], $old[1]));
            });
        } catch (Throwable $exception) {
            $this->storage->delete($stored['disk'], [$stored['path'], $stored['display_path']]);
            throw $exception instanceof UniqueConstraintViolationException ? $this->duplicate() : $exception;
        }
    }

    /** Attaches a family file to a content record; changing a published record needs a publisher. */
    public function attach(User $user, Model $record, MediaItem $media, ?string $caption): MediaAttachment
    {
        $owner = $record->ownerEntity;
        $this->authorizeRecord($user, $record);
        if ((int) $media->family_id !== (int) $owner->family_id) {
            throw ValidationException::withMessages(['media_item_id' => 'الملف ليس من مكتبة العائلة نفسها.']);
        }
        if ($record instanceof Diwan && ! $media->isImage()) {
            throw ValidationException::withMessages(['media_item_id' => 'يُرفق بالديوان صور فقط.']);
        }

        return DB::transaction(function () use ($user, $record, $media, $owner, $caption): MediaAttachment {
            $attachment = $record->mediaAttachments()->firstOrCreate(['media_item_id' => $media->id], ['caption' => $caption, 'sort_order' => $record->mediaAttachments()->count()]);
            $this->audit->record($user, $owner->family, $attachment, 'created', null, $this->audit->snapshot($attachment));

            return $attachment;
        });
    }

    public function detach(User $user, Model $record, MediaAttachment $attachment): void
    {
        $this->authorizeRecord($user, $record);
        DB::transaction(function () use ($user, $record, $attachment): void {
            $before = $this->audit->snapshot($attachment);
            $attachment->delete();
            $this->audit->record($user, $record->ownerEntity->family, $attachment, 'deleted', $before, null);
        });
    }

    /** Only unpublished, unused files are deleted; published files are archived instead. */
    public function delete(User $user, MediaItem $media): void
    {
        $owner = $media->ownerEntity;
        abort_unless(Gate::forUser($user)->allows($media->publication_status === MediaItem::STATUS_ARCHIVED ? 'publish' : 'update', $owner), 403);
        DB::transaction(function () use ($user, $media, $owner): void {
            Family::query()->whereKey($owner->family_id)->lockForUpdate()->firstOrFail();
            $media->refresh();
            if ($media->publication_status === MediaItem::STATUS_PUBLISHED) {
                throw ValidationException::withMessages(['media' => 'الملف منشور؛ أرشفه بدل حذفه.']);
            }
            if ($media->attachments()->exists() || CommunityEntity::query()->where('cover_media_id', $media->id)->exists()) {
                throw ValidationException::withMessages(['media' => 'الملف مستخدم في سجلات أو صورة غلاف؛ أزل الاستخدام أولًا.']);
            }
            $before = $this->content->snapshot($media);
            $files = [$media->disk, [$media->path, $media->display_path]];
            $media->citations()->delete();
            $media->entityLinks()->delete();
            $media->delete();
            $this->audit->record($user, $owner->family, $media, 'deleted', $before, null);
            DB::afterCommit(fn () => $this->storage->delete($files[0], $files[1]));
        });
    }

    private function authorizeRecord(User $user, Model $record): void
    {
        $published = in_array($record->publication_status, [$record::STATUS_PUBLISHED, $record::STATUS_ARCHIVED], true);
        abort_unless(Gate::forUser($user)->allows($published ? 'publish' : 'update', $record->ownerEntity), 403);
    }

    private function assertNotDuplicate(int $familyId, string $checksum, ?int $except = null): void
    {
        if (MediaItem::query()->where('family_id', $familyId)->where('checksum', $checksum)->when($except, fn ($query) => $query->whereKeyNot($except))->exists()) {
            throw $this->duplicate();
        }
    }

    private function duplicate(): ValidationException
    {
        return ValidationException::withMessages(['file' => 'هذا الملف موجود مسبقًا في مكتبة العائلة؛ أرفق السجل الموجود بدل رفعه مرة أخرى.']);
    }
}
