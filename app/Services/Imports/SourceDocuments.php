<?php

namespace App\Services\Imports;

/**
 * Private location of raw genealogy datasets and source images (outside the web root). Files keep their original names
 * and checksums; the paths recorded on sources (for example `docs/<file>`) remain the provenance of where a file was
 * first published, while reading always happens from this directory.
 */
class SourceDocuments
{
    public const DIRECTORY = 'data/source-documents';

    /** Absolute path of a source document by its original file name or recorded `docs/…` path. */
    public static function path(string $file): string
    {
        return resource_path(self::DIRECTORY.'/'.basename($file));
    }
}
