<?php

namespace Database\Seeders;

use App\Models\CommunityEntity;
use App\Models\CommunityEntityManager;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyRoleAssignment;
use App\Models\Role;
use App\Models\User;
use DomainException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Deterministic local accounts. Existing identities, credentials and assignments are preserved. */
class SiteUsersSeeder extends Seeder
{
    public function run(): void
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        if (! app()->environment(['local', 'testing']) || ! (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with((string) $host, '.test'))) {
            throw new DomainException('Demo accounts require local/testing and a localhost or .test application URL.');
        }
        DB::transaction(function (): void {
            $family = Family::query()->where('slug', 'al-zoubi')->sole();
            $create = function (string $username, string $label, ?string $membershipRole = null, bool $super = false) use ($family): array {
                $email = $username.'@juthoor.test';
                $user = User::query()->where('username', $username)->orWhere('email', $email)->first();
                if ($user && ($user->username !== $username || $user->email !== $email)) {
                    throw new DomainException('Demo account identity collision: '.$username);
                }
                $created = $user === null;
                if ($created) {
                    $user = new User;
                    $user->forceFill(['username' => $username, 'email' => $email, 'name' => $label,
                        'password' => Hash::make('password'), 'email_verified_at' => now(), 'is_active' => true, 'is_super_admin' => $super])->save();
                }
                $membership = null;
                if ($membershipRole) {
                    $membership = $created
                        ? $family->memberships()->firstOrCreate(['user_id' => $user->id], ['role' => $membershipRole])
                        : $family->memberships()->where('user_id', $user->id)->first();
                }

                return [$user, $membership, $created];
            };
            $create('superadmin', 'مدير الموقع التجريبي', null, true);
            foreach (['familyadmin' => FamilyMembership::ROLE_ADMIN, 'editor' => FamilyMembership::ROLE_EDITOR, 'reader' => FamilyMembership::ROLE_READER] as $username => $membershipRole) {
                $create($username, 'حساب تجريبي '.$username, $membershipRole);
            }
            foreach (Role::query()->assignable()->orderBy('key')->get() as $role) {
                [, $membership, $created] = $create('demo_'.str_replace('-', '_', $role->key), $role->label_ar, FamilyMembership::ROLE_READER);
                if ($created) {
                    FamilyRoleAssignment::query()->firstOrCreate(['family_membership_id' => $membership->id, 'role_id' => $role->id]);
                }
            }
            $entity = CommunityEntity::query()->where('family_id', $family->id)->where('slug', 'al-qawasmeh')->sole();
            foreach (CommunityEntityManager::ROLES as $role => $label) {
                [$user, , $created] = $create('entity_'.$role, $label, FamilyMembership::ROLE_READER);
                if ($created) {
                    CommunityEntityManager::query()->firstOrCreate(['community_entity_id' => $entity->id, 'user_id' => $user->id], ['role' => $role]);
                }
            }
        });
    }
}
