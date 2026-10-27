<?php

namespace App\Services\Imports;

use DomainException;

/** The single authoritative development seed proposal, independent of orchestration. */
class UnifiedGenealogyDataset
{
    public function data(): array
    {
        $data = json_decode(file_get_contents(resource_path('data/genealogy/al-zoubi/normalized.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->validate($data);

        return $data;
    }

    public function validate(array $data): void
    {
        $people = [];
        foreach ($data['people'] as $row) {
            if (empty($row['key']) || trim($row['name']) === '' || isset($people[$row['key']]) || empty($row['sources'])) {
                throw new DomainException('Invalid or duplicate genealogy identity.');
            }
            $people[$row['key']] = $row;
        }
        if (! isset($people[$data['root']], $people[$data['expansion']]) || $people[$data['root']]['father'] !== null) {
            throw new DomainException('Invalid genealogy root or expansion point.');
        }
        foreach ($people as $key => $row) {
            $seen = [];
            $current = $key;
            while ($current !== null) {
                if (! isset($people[$current])) {
                    throw new DomainException('Missing parent: '.$current);
                }
                if (isset($seen[$current])) {
                    throw new DomainException('Genealogy cycle: '.$current);
                }
                $seen[$current] = true;
                $current = $people[$current]['father'];
            }
            if (! isset($seen[$data['root']])) {
                throw new DomainException('Disconnected genealogy identity: '.$key);
            }
        }
        $branches = [];
        foreach ($data['branches'] as $branch) {
            if (isset($branches[$branch['slug']]) || ($branch['founder'] && ! isset($people[$branch['founder']]))
                || ($branch['parent'] && ! isset($branches[$branch['parent']]))) {
                throw new DomainException('Invalid branch hierarchy: '.$branch['slug']);
            }
            $branches[$branch['slug']] = $branch;
        }
    }
}
