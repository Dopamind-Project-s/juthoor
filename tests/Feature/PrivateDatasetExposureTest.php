<?php

use App\Services\Imports\AlQawasmehBranchImporter;
use App\Services\Imports\AlZoubiDataset;
use App\Services\Imports\UnifiedGenealogyDataset;
use Illuminate\Support\Facades\File;

/**
 * Raw genealogy datasets and handwritten source charts contain unreviewed people (living descendants and pending
 * Qawasmeh records). They must never be inside the web root, whatever the server's directory-listing settings.
 */
test('no raw genealogy dataset or source image is inside the web root', function () {
    $privateHashes = array_values(app(UnifiedGenealogyDataset::class)->snapshot()['checksums']);
    expect($privateHashes)->not->toBeEmpty();

    $public = collect(File::allFiles(public_path()));
    $exposed = $public->filter(fn ($file) => in_array(hash_file('sha256', $file->getPathname()), $privateHashes, true)
        || preg_match('/genealogy_dataset|\.xlsx$|قواسمه|رحايمه|عودتلات|بكر (عيد|خميس)/u', $file->getFilename()) === 1)
        ->map(fn ($file) => $file->getRelativePathname())->values()->all();

    expect($exposed)->toBe([]);
});

test('importers read embedded records with retained checksums and provenance paths', function () {
    expect(AlZoubiDataset::PATH)->toBe('docs/juthoor_alzoubi_genealogy_dataset_v4.json')
        ->and(app(AlZoubiDataset::class)->readyRootRows())->toHaveCount(77);
    foreach (app(AlQawasmehBranchImporter::class)->manifest()['sources'] as $row) {
        if (isset($row['file'], $row['sha256'])) {
            expect(array_values(app(UnifiedGenealogyDataset::class)->snapshot()['checksums']))->toContain($row['sha256']);
        }
    }
});

test('requests for the former public paths are not served', function () {
    foreach (['/docs/juthoor_alzoubi_genealogy_dataset_v4.json', '/docs/juthoor_alzoubi_genealogy_dataset_v4%20(1).json', '/docs/'.rawurlencode('قواسمه.pdf')] as $url) {
        $this->get($url)->assertNotFound();
    }
});
