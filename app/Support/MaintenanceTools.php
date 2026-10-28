<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MaintenanceTools
{
    public const LABELS = [
        'seed_audit' => 'تدقيق بيانات السيدر',
        'reconciliation' => 'مصالحة الأسماء التاريخية',
        'diagnostics' => 'الفحوص التشخيصية',
        'audit_viewer' => 'عرض سجل التدقيق والاسترجاع',
        'genealogy_administration' => 'إدارة الأنساب والاستيراد والمراجعة',
        'permissions_administration' => 'إدارة المستخدمين والصلاحيات والدعوات',
    ];

    /** All tool states for a single page; do not cache across requests or settings changes. */
    public static function states(): array
    {
        $stored = Schema::hasTable('maintenance_tool_settings') ? DB::table('maintenance_tool_settings')->pluck('enabled', 'name')->all() : [];
        $states = [];
        foreach (self::LABELS as $name => $label) {
            $states[$name] = (bool) ($stored[$name] ?? in_array($name, ['audit_viewer', 'genealogy_administration', 'permissions_administration'], true));
        }

        return $states;
    }

    public static function enabled(string $name): bool
    {
        if (! isset(self::LABELS[$name])) {
            return false;
        }
        $default = in_array($name, ['audit_viewer', 'genealogy_administration', 'permissions_administration'], true);
        if (! Schema::hasTable('maintenance_tool_settings')) {
            return $default;
        }

        return (bool) (DB::table('maintenance_tool_settings')->where('name', $name)->value('enabled') ?? $default);
    }
}
