<?php

namespace App\Console\Commands;

use App\Services\Imports\FamilyBootstrapService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class BootstrapFamily extends Command
{
    protected $signature = 'family:bootstrap {manifest : مسار ملف JSON المراجع لتهيئة العائلة} {--dry-run : عرض ما سيُنشأ دون حفظ} {--json : إخراج التقرير بصيغة JSON}';

    protected $description = 'تهيئة عائلة جديدة من ملف معتمد: العائلة والشجرة والأدوار والمصادر والكيانات والفروع، دون اختلاق أنساب أو تعديل الموجود';

    public function handle(FamilyBootstrapService $bootstrap): int
    {
        $path = (string) $this->argument('manifest');
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($manifest)) {
            $this->error('تعذر قراءة ملف التهيئة أو أنه ليس JSON صالحًا: '.$path);

            return self::FAILURE;
        }
        try {
            $report = $bootstrap->bootstrap($manifest, (bool) $this->option('dry-run'));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->error($field.': '.implode(' ', $messages));
            }

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode($report + ['manifest_sha256' => hash_file('sha256', $path)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->info(($report['dry_run'] ? 'تشغيل تجريبي — لم يُحفظ شيء. ' : '').'العائلة: '.$report['family'].' · sha256: '.hash_file('sha256', $path));
        foreach (['created' => 'أُنشئ', 'kept' => 'موجود ولم يُعدّل', 'skipped' => 'تُرك'] as $kind => $label) {
            foreach ($report[$kind] as $line) {
                $kind === 'skipped' ? $this->warn("$label: $line") : $this->line("$label: $line");
            }
        }

        return self::SUCCESS;
    }
}
