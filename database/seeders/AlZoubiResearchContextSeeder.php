<?php

namespace Database\Seeders;

use App\Models\Family;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Optional research context. Never creates people or changes verification. */
class AlZoubiResearchContextSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $family = Family::query()->where('slug', 'al-zoubi')->first();
            $tree = $family?->familyTrees()->where('slug', 'main')->first();
            $adnan = $tree?->people()->where('canonical_key', 'ROOT-029')->where('full_name_ar', 'عدنان')->first();
            if (! $adnan) {
                return;
            }
            foreach ([
                ['research-dar-ifta-4031', 'دار الإفتاء المصرية — نسب النبي صلى الله عليه وآله وسلم', 'https://www.dar-alifta.org/ar/ourreligion/details/4031/نسب-النبي-صلى-الله-عليه-وآله-وسلم', 'فقرة النسب إلى عدنان', 'تذكر الصفحة اتفاق العلماء على النسب النبوي إلى عدنان. لا تقدم إثباتًا لسلسلة الزعبي المحددة أو جميع حلقاتها.'],
                ['research-islamweb-202633', 'إسلام ويب — نسب النبي المعلوم الصحة (فتوى 202633)', 'https://islamweb.net/ar/fatwa/202633/نسب-النبي-المعلوم-الصحة', 'نقل زاد المعاد عن حدود الاتفاق والخلاف', 'تميز الفتوى بين الاتفاق إلى عدنان والخلاف في تفاصيل ما فوقه؛ لا تعتمد أسماء التسلسل المقدم لهذا المشروع.'],
                ['research-islamweb-16525', 'موسوعة السيرة النبوية والتاريخ الإسلامي — نسب عدنان', 'https://www.islamweb.net/ar/library/content/200/16525/نسب-عدنان', 'فقرة اختلاف عدد وأسماء الآباء بين عدنان وإسماعيل', 'تورد تقاليد نسب مختلفة وتوضح اختلاف العدد والأسماء. سياق بحث للمراجعة وليس تصحيحًا أو تضعيفًا آليًا لسجلات المشروع.'],
            ] as [$reference, $title, $url, $locator, $notes]) {
                $source = $family->sources()->firstOrCreate(['reference' => $reference], ['source_type' => 'website', 'title' => $title, 'url' => $url, 'notes' => $notes.' تاريخ الاطلاع: 2026-10-17. لا يغير حالة أي سجل.', 'verification_status' => 'pending_review']);
                $source->citations()->firstOrCreate(['person_id' => $adnan->id, 'field_key' => 'research_context', 'locator' => $locator], ['family_tree_id' => $tree->id, 'url' => $url, 'notes' => $notes, 'verification_status' => 'pending_review']);
                $edge = $adnan->outgoingRelationships()->where('relationship_type', 'father')->where('is_primary', true)->first();
                if ($edge) {
                    $source->citations()->firstOrCreate(['person_relationship_id' => $edge->id, 'field_key' => 'research_context', 'locator' => $locator], ['family_tree_id' => $tree->id, 'url' => $url, 'notes' => 'سياق عن حدود الأدلة، لا إثبات مباشر لهذه الأبوة. '.$notes, 'verification_status' => 'pending_review']);
                }
            }
        });
    }
}
