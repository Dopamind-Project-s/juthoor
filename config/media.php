<?php

/*
| Community media (archive files, diwan images, entity covers, galleries). Files live on a private disk under generated
| names and are delivered only through the media route, which applies publication, audience and role checks.
*/
return [
    // Any Laravel disk; it must not be publicly reachable (no public URL, no storage:link target).
    'disk' => env('MEDIA_DISK', 'media'),

    /*
    | Allowed types: sniffed MIME type => [category, allowed extensions]. SVG, HTML, scripts and archives are never accepted.
    */
    'types' => [
        'image/jpeg' => ['image', ['jpg', 'jpeg']],
        'image/png' => ['image', ['png']],
        'application/pdf' => ['document', ['pdf']],
        'audio/mpeg' => ['audio', ['mp3']],
        'audio/ogg' => ['audio', ['ogg', 'oga']],
    ],

    // Maximum size in kilobytes per category.
    'max_kb' => [
        'image' => (int) env('MEDIA_MAX_IMAGE_KB', 10240),
        'document' => (int) env('MEDIA_MAX_DOCUMENT_KB', 25600),
        'audio' => (int) env('MEDIA_MAX_AUDIO_KB', 51200),
    ],

    // Images above this many pixels on either side are rejected (decompression bombs).
    'max_pixels_side' => (int) env('MEDIA_MAX_PIXELS_SIDE', 12000),

    // Long edge of the display copy served to viewers when GD is available; the original is kept unchanged.
    'display_max' => (int) env('MEDIA_DISPLAY_MAX', 1600),
];
