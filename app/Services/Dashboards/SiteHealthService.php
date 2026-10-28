<?php

namespace App\Services\Dashboards;

use App\Models\ChangeRequest;
use App\Models\CrossFamilyLink;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Site-level operational indicators for the super admin dashboard.
 * Each check degrades to an "unknown" state instead of failing the page.
 */
class SiteHealthService
{
    /**
     * @return array<int, array{label: string, value: string, level: string, hint: ?string}>
     */
    public function checks(): array
    {
        $production = app()->environment('production');
        $queue = (string) config('queue.default');
        $mailer = (string) config('mail.default');
        $pendingJobs = $this->tableCount('jobs');
        $failedJobs = $this->tableCount('failed_jobs');
        $oldestJob = Schema::hasTable('jobs') ? DB::table('jobs')->min('available_at') : null;
        $stalled = $oldestJob !== null && now()->timestamp - (int) $oldestJob > 900;
        $privateRoot = (string) config('filesystems.disks.genealogy_private.root');

        return [
            ['label' => 'البيئة', 'value' => app()->environment(), 'level' => 'ok', 'hint' => null],
            ['label' => 'وضع التصحيح', 'value' => config('app.debug') ? 'مفعّل' : 'معطّل', 'level' => config('app.debug') && $production ? 'danger' : 'ok', 'hint' => config('app.debug') && $production ? 'عطّل APP_DEBUG في الإنتاج لمنع كشف تفاصيل الأخطاء.' : null],
            ['label' => 'قاعدة البيانات', 'value' => DB::connection()->getDriverName().' · '.$this->databaseSize(), 'level' => 'ok', 'hint' => null],
            ['label' => 'الطابور', 'value' => $queue.' · '.$this->format($pendingJobs).' مهمة معلقة', 'level' => $queue === 'sync' && $production ? 'warning' : ($stalled ? 'danger' : 'ok'), 'hint' => $stalled ? 'توجد مهام أقدم من 15 دقيقة؛ تحقق من تشغيل queue:work.' : ($queue === 'sync' && $production ? 'الطابور المتزامن يبطئ الطلبات ويفقد إعادة المحاولة.' : null)],
            ['label' => 'المهام الفاشلة', 'value' => $this->format($failedJobs), 'level' => $failedJobs ? 'danger' : 'ok', 'hint' => $failedJobs ? 'راجعها بـ queue:failed ثم أعد المحاولة أو احذفها.' : null],
            ['label' => 'البريد', 'value' => $mailer, 'level' => in_array($mailer, ['log', 'array'], true) && $production ? 'danger' : 'ok', 'hint' => in_array($mailer, ['log', 'array'], true) ? 'لا يُرسل بريد فعلي؛ الدعوات والاستعادة لن تصل.' : null],
            ['label' => 'مخزن الملفات الخاص', 'value' => is_dir($privateRoot) ? (is_writable($privateRoot) ? 'متاح للكتابة' : 'للقراءة فقط') : 'غير منشأ بعد', 'level' => is_dir($privateRoot) && ! is_writable($privateRoot) ? 'danger' : 'ok', 'hint' => null],
            ['label' => 'النسخ الاحتياطي', 'value' => 'غير مُعدّ في المستودع', 'level' => 'warning', 'hint' => 'لا توجد مهمة نسخ واحتفاظ مجدولة أو اختبار استعادة موثق؛ اشمل قاعدة البيانات ومخزن الملفات الخاص.'],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function statistics(): array
    {
        $families = Family::query()->toBase()->selectRaw('count(*) as total, count(case when is_active = ? then 1 end) as active', [true])->first();
        $users = User::query()->toBase()->selectRaw('count(*) as total, count(case when is_active = ? then 1 end) as inactive, count(case when email_verified_at is null then 1 end) as unverified, count(case when is_super_admin = ? and is_active = ? then 1 end) as administrators', [false, true, true])->first();

        return [
            'families' => (int) $families->total,
            'active_families' => (int) $families->active,
            'users' => (int) $users->total,
            'inactive_users' => (int) $users->inactive,
            'unverified_users' => (int) $users->unverified,
            'super_admins' => (int) $users->administrators,
            'pending_changes' => ChangeRequest::query()->where('status', 'pending')->count(),
            'pending_shared_ancestry' => CrossFamilyLink::query()->where('status', 'pending_review')->count(),
            'pending_imports' => ImportBatch::query()->where('status', 'preview')->count(),
            'failed_jobs' => $this->tableCount('failed_jobs'),
        ];
    }

    /**
     * Active families that cannot be administered by anyone except a super admin,
     * or that have no primary tree to publish.
     *
     * @return array<int, array{family: Family, issues: array<int, string>}>
     */
    public function familiesNeedingAttention(int $limit = 10): array
    {
        return Family::query()->active()
            ->withCount(['memberships as active_admins_count' => fn ($query) => $query->where('role', FamilyMembership::ROLE_ADMIN)
                ->whereHas('user', fn ($user) => $user->where('is_active', true))])
            ->withCount(['familyTrees as primary_trees_count' => fn ($query) => $query->where('is_primary', true)])
            ->with('primaryTree:id,family_id,root_person_id')
            ->where(fn ($query) => $query->whereDoesntHave('memberships', fn ($members) => $members->where('role', FamilyMembership::ROLE_ADMIN)->whereHas('user', fn ($user) => $user->where('is_active', true)))
                ->orWhereDoesntHave('familyTrees', fn ($trees) => $trees->where('is_primary', true))
                ->orWhereHas('primaryTree', fn ($tree) => $tree->whereNull('root_person_id')))
            ->orderBy('name_ar')->limit($limit)->get()
            ->map(fn (Family $family) => ['family' => $family, 'issues' => array_values(array_filter([
                $family->active_admins_count === 0 ? 'لا يوجد مدير عائلة نشط' : null,
                $family->primary_trees_count === 0 ? 'لا توجد شجرة أساسية' : null,
                $family->primaryTree && ! $family->primaryTree->root_person_id ? 'الشجرة الأساسية دون جذر' : null,
            ]))])
            ->filter(fn (array $row) => $row['issues'] !== [])
            ->take($limit)->values()->all();
    }

    /** Read-only operational facts remain available while optional diagnostics are off. */
    public function overviewChecks(array $statistics): array
    {
        return [
            ['label' => 'المهام الفاشلة', 'value' => (string) $statistics['failed_jobs'], 'level' => $statistics['failed_jobs'] ? 'danger' : 'ok', 'hint' => $statistics['failed_jobs'] ? 'راجع المهام الفاشلة باستخدام أدوات الطابور المعتمدة.' : null],
            ['label' => 'النسخ الاحتياطي', 'value' => 'غير مُعدّ في المستودع', 'level' => 'warning', 'hint' => 'لا توجد سياسة نسخ واستعادة مثبتة في المستودع؛ تحقق من إعدادات الاستضافة.'],
        ];
    }

    private function tableCount(string $table): int
    {
        try {
            return Schema::hasTable($table) ? DB::table($table)->count() : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function databaseSize(): string
    {
        try {
            $bytes = match (DB::connection()->getDriverName()) {
                'sqlite' => is_file((string) config('database.connections.sqlite.database')) ? filesize((string) config('database.connections.sqlite.database')) : null,
                'mysql', 'mariadb' => (int) DB::selectOne('select sum(data_length + index_length) as size from information_schema.tables where table_schema = database()')->size,
                'pgsql' => (int) DB::selectOne('select pg_database_size(current_database()) as size')->size,
                default => null,
            };
        } catch (Throwable) {
            $bytes = null;
        }

        return $bytes === null || $bytes === false ? 'الحجم غير متاح' : number_format($bytes / 1048576, 1).' MB';
    }

    private function format(int $value): string
    {
        return number_format($value);
    }
}
