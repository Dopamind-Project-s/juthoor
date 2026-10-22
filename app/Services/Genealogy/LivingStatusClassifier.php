<?php

namespace App\Services\Genealogy;

use App\Models\Person;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

/**
 * Living status is tri-state: true (living), false (deceased), null (unknown). Unknown is never public.
 * This class only classifies records with an explicit basis and records that basis in living_status_basis.
 */
class LivingStatusClassifier
{
    /** A recorded death year or date. */
    public const BASIS_DEATH_RECORDED = 'death_recorded';

    /** Verified/sourced record explicitly classified "historical" by its curators, with no recent birth year. */
    public const BASIS_HISTORICAL_RECORD = 'historical_record';

    /** Value existed before tri-state support; it may have been a form default and deserves review. */
    public const BASIS_LEGACY = 'legacy_recorded';

    /** Set by a user through a form, proposal or import. */
    public const BASIS_RECORDED = 'recorded';

    /** Supplied ancestry that its seeder declares deceased. */
    public const BASIS_SUPPLIED_ANCESTRY = 'supplied_ancestry';

    /** Anyone born within this many years is never classified deceased without a recorded death. */
    public const LIVING_AGE_GUARD_YEARS = 110;

    /**
     * Classifies unknown records that carry explicit evidence. Bulk updates bypass model events on purpose:
     * they must not be attributed to a user or re-trigger publication checks.
     *
     * @return array{death_recorded: int, historical_record: int}
     */
    public function classifyUnknown(?int $treeId = null): array
    {
        $scope = fn () => DB::table('people')->whereNull('is_living')->when($treeId, fn ($query) => $query->where('family_tree_id', $treeId));
        $guardYear = (int) now()->year - self::LIVING_AGE_GUARD_YEARS;

        $death = $scope()->where(fn ($query) => $query->whereNotNull('death_year')->orWhereNotNull('death_date'))
            ->update(['is_living' => false, 'living_status_basis' => self::BASIS_DEATH_RECORDED]);

        $historical = $scope()
            ->where('status', Person::STATUS_HISTORICAL)
            ->whereIn('verification_status', [Source::VERIFICATION_VERIFIED, Source::VERIFICATION_SOURCED])
            ->where(fn ($query) => $query->whereNull('birth_year')->orWhere('birth_year', '<=', $guardYear))
            ->where(fn ($query) => $query->whereNull('birth_date')->orWhere('birth_date', '<=', $guardYear.'-12-31'))
            ->update(['is_living' => false, 'living_status_basis' => self::BASIS_HISTORICAL_RECORD]);

        return ['death_recorded' => $death, 'historical_record' => $historical];
    }
}
