<?php

namespace App\Services\Genealogy;

use App\Models\Assertion;
use App\Models\Branch;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonRelationship;
use Illuminate\Validation\ValidationException;

class GenealogyDeletionService
{
    public function assertPersonCanBeDeleted(Person $person): void
    {
        if (Assertion::query()->where('person_id', $person->id)->orWhere('related_person_id', $person->id)->exists()) {
            $this->reject('person', 'الشخص مرتبط بروايات ووقائع مسجلة؛ يُحفظ سجلها التاريخي ولا يُحذف.');
        }
        if ($person->citations()->exists() || $person->sources()->exists()) {
            $this->reject('person', 'الشخص مرتبط بمصادر واستشهادات. أزل الربط قبل الحذف.');
        }
        if (FamilyTree::query()->where('root_person_id', $person->id)->exists()) {
            $this->reject('person', 'لا يمكن حذف جذر الشجرة. عيّن جذرًا آخر أولًا.');
        }

        if ($person->foundedBranches()->exists()) {
            $this->reject('person', 'لا يمكن حذف مؤسس فرع. غيّر مؤسس الفرع أو احذف الفرع أولًا.');
        }

        if ($person->outgoingRelationships()->exists() || $person->incomingRelationships()->exists()) {
            $this->reject('person', 'لا يمكن حذف شخص مرتبط بعلاقات نسب. احذف العلاقات المعنية أولًا.');
        }
    }

    public function assertRelationshipCanBeDeleted(PersonRelationship $relationship): void
    {
        if ($relationship->citations()->exists()) {
            $this->reject('relationship', 'العلاقة مرتبطة باستشهادات. أزل الربط قبل الحذف.');
        }
        if (Branch::query()->where('root_person_id', $relationship->person_id)->where('is_active', true)->exists()) {
            $this->reject('relationship', 'لا يمكن حذف مسار الأبوة لمؤسس فرع نشط. عطّل الفرع أو غيّر مؤسسه أولًا.');
        }
    }

    public function assertBranchCanBeDeleted(Branch $branch): void
    {
        if ($branch->childBranches()->exists()) {
            $this->reject('branch', 'لا يمكن حذف فرع له فروع فرعية. انقلها أو احذفها أولًا.');
        }
        if ($branch->communityEntity()->exists()) {
            $this->reject('branch', 'الفرع مرتبط بصفحة كيان مجتمعي. أزل الربط من صفحة الكيان أولًا.');
        }
    }

    private function reject(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
