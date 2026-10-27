<?php

namespace App\Services\Imports\LegacySeeders;

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local/testing demo accounts (SD-09). New accounts get DEV_SEED_PASSWORD, or a random password printed once; existing
 * accounts keep their password and roles.
 */
class DevelopmentAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Development accounts are restricted to local/testing environments.');
        }
        $configured = config('seeding.development_password');
        $password = filled($configured) ? (string) $configured : Str::password(20);
        $created = [];

        DB::transaction(function () use ($password, &$created): void {
            $family = Family::query()->where('slug', 'al-zoubi')->first()
                ?? Family::query()->first()
                ?? Family::query()->create(['name_ar' => 'عائلة تجريبية', 'slug' => 'demo-family', 'is_active' => true]);
            if (! $family->familyTrees()->exists()) {
                $family->familyTrees()->create(['name_ar' => 'الشجرة الأساسية', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
            }

            foreach ([
                'superadmin' => ['مدير الموقع التجريبي', null],
                'familyadmin' => ['مدير العائلة التجريبي', FamilyMembership::ROLE_ADMIN],
                'editor' => ['المحرر التجريبي', FamilyMembership::ROLE_EDITOR],
                'reader' => ['القارئ التجريبي', FamilyMembership::ROLE_READER],
            ] as $username => [$name, $role]) {
                $email = $username.'@juthoor.test';
                $user = User::query()->where('username', $username)->orWhere('email', $email)->first();
                if ($user && ($user->username !== $username || $user->email !== $email)) {
                    throw new RuntimeException('Demo account identifier already belongs to another account: '.$username);
                }
                if (! $user) {
                    $user = new User;
                    $user->forceFill([
                        'name' => $name, 'username' => $username, 'email' => $email,
                        'password' => Hash::make($password), 'email_verified_at' => now(),
                        'is_active' => true, 'is_super_admin' => $role === null,
                    ])->save();
                    $created[] = $username;
                }
                if ($role !== null) {
                    $family->memberships()->firstOrCreate(['user_id' => $user->id], ['role' => $role]);
                }
            }
        });

        if ($created !== [] && blank($configured)) {
            $this->command?->warn('Generated password for new demo accounts ('.implode(', ', $created).'): '.$password.' — shown once; set DEV_SEED_PASSWORD to choose it.');
        }
    }
}
