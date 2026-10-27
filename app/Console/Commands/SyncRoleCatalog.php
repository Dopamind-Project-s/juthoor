<?php

namespace App\Console\Commands;

use App\Services\Access\RoleCatalogSynchronizer;
use Illuminate\Console\Command;

class SyncRoleCatalog extends Command
{
    protected $signature = 'rbac:sync {--dry-run : عرض الفروق دون أي تعديل} {--apply-upgrades : منح الصلاحيات الجديدة لقوالب الأدوار غير المخصصة بعد مراجعة الفروق} {--json : إخراج التقرير بصيغة JSON}';

    protected $description = 'مزامنة كتالوج الأدوار والصلاحيات دون تصعيد صامت أو مساس بالإسنادات والتخصيصات';

    public function handle(RoleCatalogSynchronizer $synchronizer): int
    {
        $report = $synchronizer->sync((bool) $this->option('dry-run'), (bool) $this->option('apply-upgrades'));
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        self::render($report, fn (string $line) => $this->line($line), fn (string $line) => $this->warn($line));

        return self::SUCCESS;
    }

    /**
     * Human-readable report, shared with RolePermissionSeeder.
     *
     * @param  array<string, mixed>  $report
     */
    public static function render(array $report, callable $info, callable $warn): void
    {
        $info(sprintf('كتالوج الصلاحيات %s%s', $report['catalog_version'], $report['dry_run'] ? ' — تشغيل تجريبي، لم يُحفظ شيء' : ''));
        foreach ($report['permissions'] as $kind => $keys) {
            if ($keys !== []) {
                $info("permissions.$kind: ".collect($keys)->map(fn ($value, $key) => is_string($key) ? "$key → $value" : $value)->implode(', '));
            }
        }
        foreach (['created', 'in_sync', 'deprecated', 'conflicts'] as $kind) {
            if ($report['roles'][$kind] !== []) {
                $info("roles.$kind: ".implode(', ', $report['roles'][$kind]));
            }
        }
        foreach (['granted', 'revoked'] as $kind) {
            foreach ($report['roles'][$kind] as $role => $keys) {
                $info("roles.$kind [$role]: ".implode(', ', $keys));
            }
        }
        foreach ($report['roles']['pending_grants'] as $role => $keys) {
            $warn("roles.pending_grants [$role]: ".implode(', ', $keys).' — لم تُمنح؛ راجعها ثم شغّل rbac:sync --apply-upgrades');
        }
        foreach ($report['roles']['customized'] as $role => $difference) {
            $warn("roles.customized [$role]: محفوظ كما عدّله المسؤول؛ ناقص عن القالب: ".(implode(', ', $difference['missing']) ?: '—').' · إضافي: '.(implode(', ', $difference['extra']) ?: '—'));
        }
        $info('custom_roles: '.(implode(', ', $report['custom_roles']) ?: '—').' · assignments (unchanged): '.$report['assignments']);
    }
}
