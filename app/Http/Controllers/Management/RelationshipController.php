<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreRelationshipRequest;
use App\Models\Family;
use App\Models\FamilyTree;
use App\Models\PersonRelationship;
use App\Services\AuditLogger;
use App\Services\Genealogy\GenealogyDeletionService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RelationshipController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly GenealogyDeletionService $deletion,
    ) {}

    public function store(StoreRelationshipRequest $request, string $family): RedirectResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        Gate::authorize('create', [PersonRelationship::class, $familyModel]);
        $people = $tree->people()->whereKey([
            $request->integer('person_id'),
            $request->integer('father_id'),
        ])->get()->keyBy('id');

        if ($people->count() !== 2) {
            abort(404);
        }

        try {
            DB::transaction(function () use ($familyModel, $tree, $request): void {
                $relationship = $tree->personRelationships()->create([
                    'person_id' => $request->integer('person_id'),
                    'related_person_id' => $request->integer('father_id'),
                    'relationship_type' => PersonRelationship::TYPE_FATHER,
                    'is_primary' => true,
                    'verification_status' => (string) $request->string('verification_status'),
                ]);
                $this->audit->record($request->user(), $familyModel, $relationship, 'created', null, $this->audit->snapshot($relationship));
            });
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['relationship' => $this->arabicIntegrityMessage($exception)]);
        }

        return back()->with('status', 'تمت إضافة علاقة الأبوة.');
    }

    public function destroy(string $family, int $relationship): RedirectResponse
    {
        [$familyModel, $tree] = $this->familyTree($family);
        $relationshipModel = $tree->personRelationships()->whereKey($relationship)->firstOrFail();
        Gate::authorize('delete', $relationshipModel);
        $this->deletion->assertRelationshipCanBeDeleted($relationshipModel);

        DB::transaction(function () use ($familyModel, $relationshipModel): void {
            $before = $this->audit->snapshot($relationshipModel);
            $relationshipModel->delete();
            $this->audit->record(auth()->user(), $familyModel, $relationshipModel, 'deleted', $before, null);
        });

        return back()->with('status', 'تم حذف علاقة الأبوة.');
    }

    /** @return array{Family, FamilyTree} */
    private function familyTree(string $slug): array
    {
        $family = Family::query()->where('slug', $slug)->firstOrFail();
        Gate::authorize('view', $family);

        return [$family, $family->primaryTree()->firstOrFail()];
    }

    private function arabicIntegrityMessage(DomainException $exception): string
    {
        return match ($exception->getMessage()) {
            'A person cannot have more than one primary father.' => 'للشخص أب أساسي مسجل بالفعل.',
            'The father relationship would create a genealogy cycle.' => 'هذه العلاقة ستنشئ دورة غير صالحة في شجرة النسب.',
            'A person cannot be related to themselves.' => 'لا يمكن أن يكون الشخص أبًا لنفسه.',
            default => 'تعذر حفظ العلاقة لأنها تخالف سلامة شجرة النسب.',
        };
    }
}
