<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateSuperAdmin extends Command
{
    protected $signature = 'site:make-super-admin {email : بريد المستخدم} {--name= : الاسم عند إنشاء حساب جديد} {--password= : كلمة مرور آمنة؛ تُطلب سرًا عند عدم تمريرها}';

    protected $description = 'إنشاء أول سوبر أدمن أو ترقية حساب موجود بأمان';

    public function handle(AuditLogger $audit): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();
        $password = $this->option('password') ?: ($user ? null : $this->secret('كلمة المرور (12 محرفًا على الأقل)'));
        $data = ['email' => $email, 'name' => $this->option('name') ?: $user?->name, 'password' => $password];
        $rules = ['email' => ['required', 'email'], 'name' => [$user ? 'nullable' : 'required', 'string', 'max:255']];
        if (! $user) {
            $rules['password'] = ['required', 'string', 'min:12'];
        }

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        DB::transaction(function () use (&$user, $data, $audit): void {
            $user ??= User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $before = $audit->snapshot($user);
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now(), 'is_super_admin' => true, 'is_active' => true])->save();
            $audit->record($user, null, $user, 'super_admin_granted', $before, $audit->snapshot($user));
        });

        $this->info("تم تفعيل السوبر أدمن: {$user->email}");

        return self::SUCCESS;
    }
}
