<?php

namespace App\Services\Imports;

use JsonException;
use RuntimeException;

class AlZoubiDataset
{
    /** Provenance path recorded on the dataset source; the file itself is read from SourceDocuments. */
    public const PATH = 'docs/juthoor_alzoubi_genealogy_dataset_v4.json';

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /** @return array<int, array<string, mixed>> */
    public function readyRootRows(): array
    {
        return array_values(array_filter(
            $this->data()['seeder_staging'] ?? [],
            fn (array $row): bool => ($row['seeder_status'] ?? null) === 'READY_ROOT'
                && ($row['branch_key'] ?? null) === 'ROOT'
                && filled($row['person_key'] ?? null)
                && filled($row['name_ar'] ?? null),
        ));
    }

    /** @return array{ready: int, skipped: int, unresolved: int, conflicted: int} */
    public function statistics(): array
    {
        $rows = $this->data()['seeder_staging'] ?? [];

        return [
            'ready' => count($this->readyRootRows()),
            'skipped' => count(array_filter($rows, fn (array $row): bool => ($row['seeder_status'] ?? null) === 'NEEDS_PARENT_MATCH')),
            'unresolved' => count(array_filter($rows, fn (array $row): bool => ($row['seeder_status'] ?? null) === 'BLOCKED_BLANK_NAME')),
            'conflicted' => count(require resource_path('data/al-zoubi/branch-founder-review.php')),
        ];
    }

    /** @return array<string, mixed>|null */
    public function sourceNode(string $sourceRef): ?array
    {
        foreach ($this->data()['raw_source_nodes'] ?? [] as $node) {
            if (($node['raw_node_key'] ?? null) === $sourceRef) {
                return $node;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $path = SourceDocuments::path(self::PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Genealogy dataset not found at [{$path}].");
        }

        try {
            return $this->data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Genealogy dataset is invalid JSON: {$exception->getMessage()}", previous: $exception);
        }
    }
}
