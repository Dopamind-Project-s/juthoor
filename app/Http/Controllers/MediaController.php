<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use App\Services\Community\MediaAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves media files. Files are never reachable by path: the route takes the item's random UUID, checks MediaAccess and
 * answers 404 for anything the viewer may not read, so existence is not revealed. Responses cannot be sniffed or run as
 * a document (nosniff, sandboxing CSP); only public published files are cacheable.
 */
class MediaController extends Controller
{
    public function show(Request $request, string $uuid, MediaAccess $access, string $variant = 'display'): StreamedResponse
    {
        $media = MediaItem::query()->where('uuid', $uuid)->with('ownerEntity')->firstOrFail();
        abort_unless($access->canView($request->user(), $media, $variant), 404);
        $path = $variant === 'display' && $media->display_path ? $media->display_path : $media->path;
        abort_unless(Storage::disk($media->disk)->exists($path), 404);
        $public = $media->publication_status === MediaItem::STATUS_PUBLISHED && $media->audience === 'public' && $variant === 'display';

        return Storage::disk($media->disk)->response($path, $media->original_name, [
            'Content-Type' => $media->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cache-Control' => $public ? 'public, max-age=3600' : 'private, no-store',
            'X-Robots-Tag' => $public ? 'all' : 'noindex, nofollow',
        ]);
    }
}
