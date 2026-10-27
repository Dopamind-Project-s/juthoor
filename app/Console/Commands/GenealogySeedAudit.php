<?php

namespace App\Console\Commands;

use App\Services\Imports\SeedAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seeder preflight (D2): reports counts, conflicts, preserved administrator edits, duplicates, integrity, privacy and
 * dataset checksums. Always a dry run: no seeder is called and the read runs inside a transaction that is rolled back.
 * Exits with 1 when a conflict or missing dependency would make seeding refuse.
 */
class GenealogySeedAudit extends Command
{
    protected $signature = 'genealogy:seed-audit
        {--family= : معرّف العائلة (al-zoubi افتراضيًا)}
        {--json : إخراج التقرير بصيغة JSON}
        {--dry-run : للتوضيح فقط؛ الأمر لا يغيّر قاعدة البيانات أبدًا}';

    protected $description = 'تدقيق قبلي للقراءة فقط لبيانات السيدرز والأنساب دون أي تعديل';

    public function handle(SeedAuditService $audit): int
    {
        DB::beginTransaction();
        try {
            $report = $audit->report($this->option('family') ?: null);
        } finally {
            DB::rollBack();
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $report['status'] === SeedAuditService::STATUS_CONFLICTS ? self::FAILURE : self::SUCCESS;
        }

        $this->info("تدقيق السيدرز (قراءة فقط) — {$report['family_slug']} — الحالة: {$report['status']}");
        $this->table(['البند', 'العدد'], collect($report['counts'])->map(fn ($value, $key) => [$key, $value])->values()->all());
        if ($report['expected']['ready_root'] > 0) {
            $this->table(['المتوقع من البيانات', 'العدد'], collect($report['expected'])->map(fn ($value, $key) => [$key, $value])->values()->all());
        }
        $this->table(['الخصوصية والنشر', 'القيمة'], collect($report['privacy'])->except('webroot_datasets')->map(fn ($value, $key) => [$key, $value])->values()->all());
        if ($report['datasets'] !== []) {
            $this->table(['الملف', 'موجود', 'الإصدار', 'SHA256', 'مطابق'], array_map(fn (array $file) => [
                $file['path'], $file['exists'] ? 'نعم' : 'لا', $file['version'] ?? '—', $file['sha256'] ? substr($file['sha256'], 0, 16).'…' : '—',
                $file['matches'] === null ? '—' : ($file['matches'] ? 'نعم' : 'لا'),
            ], $report['datasets']));
        }
        foreach ($report['missing_dependencies'] as $missing) {
            $this->error('اعتمادية ناقصة: '.$missing);
        }
        foreach ($report['identity_conflicts'] as $conflict) {
            $this->error("تعارض هوية ({$conflict['type']}): {$conflict['key']} — القائم [{$conflict['existing']}]، البيانات [{$conflict['expected']}]");
        }
        foreach ($report['integrity'] as $check => $items) {
            if ($items !== []) {
                $this->error("سلامة النسب ({$check}): ".json_encode($items));
            }
        }
        foreach ($report['preserved_edits'] as $edit) {
            $this->warn("تعديل محفوظ لن يُستبدل: {$edit['record']} {$edit['key']}.{$edit['field']} = [{$edit['current']}] (قيمة السيدر [{$edit['seed']}])");
        }
        foreach ($report['duplicate_candidates'] as $duplicate) {
            $this->warn('تكرار محتمل للمراجعة: '.$duplicate['name'].' — '.collect($duplicate['people'])->pluck('id')->implode(', '));
        }
        foreach ($report['privacy']['webroot_datasets'] as $path) {
            $this->warn('ملف بيانات خام داخل المجلد العام: '.$path);
        }
        $this->line('لم يُعدَّل أي سجل.');

        return $report['status'] === SeedAuditService::STATUS_CONFLICTS ? self::FAILURE : self::SUCCESS;
    }
}
