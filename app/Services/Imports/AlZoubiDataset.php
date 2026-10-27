<?php

namespace App\Services\Imports;

class AlZoubiDataset
{
    /** Historical provenance path; records are read from the embedded snapshot, not this filename. */
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
            'conflicted' => count(app(\App\Services\Imports\UnifiedGenealogyDataset::class)->snapshot()['legacy']['founder_reviews']),
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

        return $this->data = app(UnifiedGenealogyDataset::class)->snapshot()['legacy']['dataset'];
    }
}
