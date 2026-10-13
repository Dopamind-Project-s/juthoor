<?php

namespace App\Services\Imports;

use App\Http\Requests\Management\StorePersonRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FamilyTree;
use App\Models\ImportBatch;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\ArabicNameNormalizer;
use App\Services\AuditLogger;
use App\Services\Genealogy\GenealogyMutationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class CsvImportService
{
    public const COLUMNS = ['external_id', 'first_name_ar', 'full_name_ar', 'gender', 'birth_year', 'death_year', 'is_living', 'status', 'verification_status', 'father_external_id', 'branch_slug', 'branch_name_ar'];

    public function __construct(private AuditLogger $audit, private GenealogyMutationService $mutations, private ArabicNameNormalizer $normalizer) {}

    public function fingerprint(FamilyTree $tree): string
    {
        return hash('sha256', json_encode([$tree->fresh()->getAttributes(), $tree->people()->orderBy('id')->get()->map->getAttributes()->all(), $tree->personRelationships()->orderBy('id')->get()->map->getAttributes()->all(), $tree->branches()->orderBy('id')->get()->map->getAttributes()->all(), AuditLog::query()->where('family_id', $tree->family_id)->whereIn('auditable_type', [Person::class, PersonRelationship::class, Branch::class, FamilyTree::class])->where(function ($query) use ($tree) {
            $query->where('before->family_tree_id', $tree->id)->orWhere('after->family_tree_id', $tree->id)->orWhere(fn ($query) => $query->where('auditable_type', FamilyTree::class)->where('auditable_id', $tree->id));
        })->max('id')], JSON_THROW_ON_ERROR));
    }

    public function upload(FamilyTree $tree, User $user, UploadedFile $file): ImportBatch
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        $existing = ImportBatch::query()->where('family_tree_id', $tree->id)->where('checksum', $checksum)->first();
        if ($existing) {
            return $existing;
        }
        $path = $file->store('imports/'.$tree->id, 'genealogy_private');
        try {
            return DB::transaction(function () use ($tree, $user, $checksum, $path) {
                FamilyTree::query()->whereKey($tree->id)->lockForUpdate()->firstOrFail();
                $existing = ImportBatch::query()->where('family_tree_id', $tree->id)->where('checksum', $checksum)->first();
                if ($existing) {
                    Storage::disk('genealogy_private')->delete($path);

                    return $existing;
                }
                $results = $this->analyze($tree, $path);
                $batch = ImportBatch::query()->create(['family_tree_id' => $tree->id, 'uploaded_by' => $user->id, 'checksum' => $checksum, 'file_path' => $path, 'state_fingerprint' => $this->fingerprint($tree), 'results' => $results]);
                $this->audit->record($user, $tree->family, $batch, 'import_preview', null, $this->audit->snapshot($batch));

                return $batch;
            });
        } catch (Throwable $e) {
            Storage::disk('genealogy_private')->delete($path);
            throw $e;
        }
    }

    public function analyze(FamilyTree $tree, string $path): array
    {
        $text = Storage::disk('genealogy_private')->get($path);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $this->mutations->reject('يجب أن يكون CSV بترميز UTF-8.');
        }
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, preg_replace('/^\xEF\xBB\xBF/', '', $text));
        rewind($handle);
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (! $header || count($header) !== count(array_unique($header)) || array_diff($header, self::COLUMNS) || array_diff(['external_id', 'first_name_ar', 'full_name_ar', 'status', 'verification_status'], $header)) {
            fclose($handle);
            $this->mutations->reject('عناوين CSV غير صالحة. استخدم القالب المرفق؛ الحقول غير المدعومة لا تُتجاهل.');
        }
        $rows = [];
        $number = 1;
        while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $number++;
            if ($values === [null]) {
                continue;
            }
            if (count($rows) >= 1000) {
                fclose($handle);
                $this->mutations->reject('الحد الأقصى 1000 صف لكل دفعة.');
            }
            $row = ['line' => $number, 'external_id' => '', 'data' => [], 'father_external_id' => '', 'branch_slug' => '', 'branch_name_ar' => '', 'errors' => [], 'conflicts' => []];
            if (count($values) !== count($header)) {
                $row['errors'][] = 'عدد الأعمدة لا يطابق العناوين.';
                $rows[] = $row;

                continue;
            }
            $input = array_map('trim', array_combine($header, $values));
            $row['external_id'] = $input['external_id'];
            $row['father_external_id'] = $input['father_external_id'] ?? '';
            $row['branch_slug'] = $input['branch_slug'] ?? '';
            $row['branch_name_ar'] = $input['branch_name_ar'] ?? '';
            $data = array_intersect_key($input, (new StorePersonRequest)->rules());
            $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
            $data['canonical_key'] = $row['external_id'];
            $data['is_living'] = $input['is_living'] ?? '0';
            $rules = (new StorePersonRequest)->rules();
            $rules['canonical_key'] = ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'];
            $validator = Validator::make($data, $rules, (new StorePersonRequest)->messages());
            $row['errors'] = array_values($validator->errors()->all());
            $row['data'] = $validator->fails() ? [] : $validator->validated();
            if ($row['branch_slug'] !== '' && (! preg_match('/^[A-Za-z0-9_-]+$/', $row['branch_slug']) || mb_strlen($row['branch_slug']) > 255 || $row['branch_name_ar'] === '' || mb_strlen($row['branch_name_ar']) > 255)) {
                $row['errors'][] = 'الفرع يحتاج رابطًا صالحًا واسمًا (حتى 255 محرفًا).';
            }
            if ($row['branch_name_ar'] !== '' && $row['branch_slug'] === '') {
                $row['errors'][] = 'اسم الفرع يحتاج رابط الفرع.';
            }
            $rows[] = $row;
        }
        fclose($handle);
        if (! $rows) {
            $this->mutations->reject('الملف لا يحتوي على سجلات.');
        }
        $existing = $tree->people()->get();
        $keys = $existing->filter(fn ($p) => $p->canonical_key !== null)->keyBy('canonical_key');
        $names = $existing->groupBy('name_search');
        $ids = [];
        $newNames = [];
        $branchSlugs = [];
        foreach ($rows as $i => $row) {
            $ids[$row['external_id']][] = $i;
            if ($row['data']) {
                $newNames[$this->normalizer->normalize($row['data']['full_name_ar'])][] = $i;
            }
            if ($row['branch_slug'] !== '') {
                $branchSlugs[$row['branch_slug']][] = $i;
            }
        }
        $existingBranchSlugs = $tree->branches()->pluck('slug')->all();
        $parents = [];
        foreach ($tree->personRelationships()->where('relationship_type', 'father')->get() as $edge) {
            $parents['existing:'.$edge->person_id] = 'existing:'.$edge->related_person_id;
        }
        foreach ($rows as $i => &$row) {
            if (count($ids[$row['external_id']]) > 1) {
                $row['errors'][] = 'المعرف مكرر داخل الملف.';
            }
            if ($keys->has($row['external_id'])) {
                $row['conflicts']['identity'] = 'المعرف موجود بالفعل؛ لا تُحدّث الأشخاص أو تُدمج تلقائيًا.';
            }
            if ($row['data']) {
                $name = $this->normalizer->normalize($row['data']['full_name_ar']);
                if ($names->has($name) || count($newNames[$name]) > 1) {
                    $row['conflicts']['name'] = 'اسم مشابه موجود. راجع الهوية؛ لا يُدمج بالاسم.';
                }
            }
            if ($row['branch_slug'] !== '' && (in_array($row['branch_slug'], $existingBranchSlugs, true) || count($branchSlugs[$row['branch_slug']]) > 1)) {
                $row['errors'][] = 'رابط الفرع موجود أو مكرر في الملف.';
            }
            $father = $row['father_external_id'];
            if ($father !== '') {
                if ($father === $row['external_id']) {
                    $row['errors'][] = 'لا يمكن أن يكون الشخص أبًا لنفسه.';
                } elseif (! isset($ids[$father]) && ! $keys->has($father)) {
                    $row['errors'][] = 'معرف الأب غير موجود في الملف أو الشجرة المختارة.';
                } else {
                    $parents['new:'.$row['external_id']] = isset($ids[$father]) ? 'new:'.$father : 'existing:'.$keys[$father]->id;
                }
            }
        }unset($row);
        foreach ($rows as &$row) {
            $node = 'new:'.$row['external_id'];
            $seen = [];
            while (isset($parents[$node])) {
                if (isset($seen[$node])) {
                    $row['errors'][] = 'العلاقات تُنشئ دورة في الأنساب.';
                    break;
                }$seen[$node] = true;
                $node = $parents[$node];
            }
        }unset($row);
        // Invalid referenced rows cannot be imported as parents of otherwise valid rows.
        for ($pass = 0; $pass < count($rows); $pass++) {
            $changed = false;
            foreach ($rows as &$row) {
                $father = $row['father_external_id'];
                if (isset($ids[$father]) && ! $row['errors']) {
                    foreach ($ids[$father] as $i) {
                        if ($rows[$i]['errors'] || isset($rows[$i]['conflicts']['identity'])) {
                            $row['errors'][] = 'صف الأب متعارض أو غير صالح.';
                            $changed = true;
                            break;
                        }
                    }
                }
            }unset($row);
            if (! $changed) {
                break;
            }
        }
        foreach ($rows as &$row) {
            $row['status'] = $row['errors'] ? 'error' : ($row['conflicts'] ? 'conflict' : 'ready');
        }unset($row);

        return $rows;
    }

    public function revalidate(FamilyTree $tree, ImportBatch $batch, User $user): void
    {
        DB::transaction(function () use ($tree, $batch, $user): void {
            FamilyTree::query()->whereKey($tree->id)->lockForUpdate()->firstOrFail();
            $batch = ImportBatch::query()->where('family_tree_id', $tree->id)->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'committed') {
                $this->mutations->reject('الدفعة معتمدة بالفعل؛ لا يمكن إعادة تنفيذها.');
            }
            $this->verifyFile($batch);
            $before = $this->audit->snapshot($batch);
            $batch->update(['status' => 'preview', 'failure_reason' => null, 'results' => $this->analyze($tree, $batch->file_path), 'state_fingerprint' => $this->fingerprint($tree)]);
            $this->audit->record($user, $tree->family, $batch, 'import_revalidated', $before, $this->audit->snapshot($batch));
        });
    }

    private function verifyFile(ImportBatch $batch): void
    {
        if (! Storage::disk('genealogy_private')->exists($batch->file_path) || ! hash_equals($batch->checksum, hash('sha256', Storage::disk('genealogy_private')->get($batch->file_path)))) {
            $this->mutations->reject('ملف الدفعة مفقود أو تغيرت بصمته؛ أعد رفع ملف صالح.');
        }
    }

    public function commit(FamilyTree $tree, ImportBatch $batch, User $user, string $reason, bool $allowNames = false, string $duplicateReason = '', ?string $rootKey = null, ?string $expectedFingerprint = null): void
    {
        try {
            DB::transaction(function () use ($tree, $batch, $user, $reason, $allowNames, $duplicateReason, $rootKey, $expectedFingerprint): void {
                FamilyTree::query()->whereKey($tree->id)->lockForUpdate()->firstOrFail();
                $batch = ImportBatch::query()->where('family_tree_id', $tree->id)->lockForUpdate()->findOrFail($batch->id);
                if ($batch->status !== 'preview') {
                    $this->mutations->reject('الدفعة ليست جاهزة للاعتماد أو سبق اعتمادها. أعد التحقق عند الفشل.');
                }
                if ($expectedFingerprint !== null && ! hash_equals($batch->state_fingerprint, $expectedFingerprint)) {
                    $this->mutations->reject('تغيرت المعاينة منذ فتحها؛ افتح النتائج الحالية ثم أكدها.');
                }
                $this->verifyFile($batch);
                if (! hash_equals($batch->state_fingerprint, $this->fingerprint($tree))) {
                    $this->mutations->reject('تغيرت الشجرة منذ المعاينة؛ أعد التحقق قبل الاعتماد.');
                }
                $rows = $this->analyze($tree, $batch->file_path);
                foreach ($rows as $row) {
                    if ($row['errors'] || isset($row['conflicts']['identity'])) {
                        $this->mutations->reject('توجد صفوف غير صالحة أو معرفات متعارضة؛ أصلح الملف وأعد رفعه.');
                    }
                    if (isset($row['conflicts']['name']) && (! $allowNames || trim($duplicateReason) === '')) {
                        $this->mutations->reject('تشابه الأسماء يحتاج تأكيد إبقاء الأشخاص منفصلين وسببًا صريحًا.');
                    }
                }
                $before = $this->audit->snapshot($batch);
                $mapping = $tree->people()->whereNotNull('canonical_key')->pluck('id', 'canonical_key')->all();
                $created = ['people' => [], 'relationships' => [], 'branches' => []];
                foreach ($rows as $row) {
                    $data = $this->mutations->attributes($tree, 'person', $row['data']);
                    $log = $this->mutations->apply($tree, $user, 'person', 'create', $data);
                    $mapping[$row['external_id']] = $log->auditable_id;
                    $created['people'][] = $log->auditable_id;
                }
                foreach ($rows as $row) {
                    if ($row['father_external_id'] !== '') {
                        $data = $this->mutations->attributes($tree, 'relationship', ['person_id' => $mapping[$row['external_id']], 'father_id' => $mapping[$row['father_external_id']], 'verification_status' => $row['data']['verification_status']]);
                        $log = $this->mutations->apply($tree, $user, 'relationship', 'create', $data);
                        $created['relationships'][] = $log->auditable_id;
                    }
                }
                foreach ($rows as $row) {
                    if ($row['branch_slug'] !== '') {
                        $data = $this->mutations->attributes($tree, 'branch', ['name_ar' => $row['branch_name_ar'], 'slug' => $row['branch_slug'], 'root_person_id' => $mapping[$row['external_id']], 'is_active' => true, 'verification_status' => $row['data']['verification_status']]);
                        $log = $this->mutations->apply($tree, $user, 'branch', 'create', $data);
                        $created['branches'][] = $log->auditable_id;
                    }
                }
                if ($rootKey) {
                    if (! isset($mapping[$rootKey])) {
                        $this->mutations->reject('معرف الجذر غير موجود.');
                    }$tree->refresh();
                    if ($tree->root_person_id !== null) {
                        $this->mutations->reject('للشجرة جذر قائم؛ غيّره من إدارة الشجرة بعد مراجعة الأثر.');
                    }$treeBefore = $this->audit->snapshot($tree);
                    $tree->update(['root_person_id' => $mapping[$rootKey]]);
                    $this->audit->record($user, $tree->family, $tree, 'updated', $treeBefore, $this->audit->snapshot($tree));
                }
                $batch->update(['status' => 'committed', 'results' => $rows, 'created_ids' => $created, 'committed_by' => $user->id, 'committed_at' => now(), 'decision_reason' => $reason.($allowNames ? ' — قرار تشابه الأسماء: '.$duplicateReason : ''), 'failure_reason' => null]);
                $this->audit->record($user, $tree->family, $batch, 'import_committed', $before, $this->audit->snapshot($batch));
            });
        } catch (Throwable $e) {
            DB::transaction(function () use ($tree, $batch, $user, $e): void {
                $current = ImportBatch::query()->where('family_tree_id', $tree->id)->lockForUpdate()->findOrFail($batch->id);
                if ($current->status === 'committed') {
                    return;
                }$message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : 'فشل تنفيذ الدفعة؛ لم تُحفظ بيانات جزئية. أعد التحقق ثم حاول مجددًا.';
                $before = $this->audit->snapshot($current);
                $current->update(['status' => 'failed', 'failure_reason' => $message]);
                $this->audit->record($user, $tree->family, $current, 'import_failed', $before, $this->audit->snapshot($current));
            });
            if ($e instanceof ValidationException) {
                throw $e;
            }report($e);
            $this->mutations->reject('فشل تنفيذ الدفعة؛ لم تُحفظ بيانات جزئية. أعد التحقق ثم حاول مجددًا.');
        }
    }
}
