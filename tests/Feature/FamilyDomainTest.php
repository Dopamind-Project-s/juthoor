<?php

use App\Models\AuditLog;
use App\Models\Family;
use App\Models\FamilyDomain;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Services\Imports\FamilyBootstrapService;
use App\Services\Portal\FamilyHosts;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    config(['portals.host_routing' => true, 'portals.platform_host' => 'juthoor.test', 'portals.platform_aliases' => ['localhost'], 'portals.subdomain_suffix' => 'juthoor.test', 'portals.scheme' => 'https']);
    $this->family = Family::query()->create(['name_ar' => 'عشيرة الألف', 'slug' => 'alif', 'is_active' => true]);
    $this->family->familyTrees()->create(['name_ar' => 'شجرة الألف', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $this->other = Family::query()->create(['name_ar' => 'عشيرة الباء', 'slug' => 'baa', 'is_active' => true]);
    $this->other->familyTrees()->create(['name_ar' => 'شجرة الباء', 'slug' => 'main', 'is_primary' => true, 'is_active' => true]);
    $this->member = function (Family $family, string $role): User {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        FamilyMembership::query()->create(['family_id' => $family->id, 'user_id' => $user->id, 'role' => $role]);

        return $user->fresh();
    };
    $this->superAdmin = User::factory()->create(['is_active' => true, 'is_super_admin' => true, 'email_verified_at' => now()]);
    $this->activate = function (Family $family, ?string $host = null): FamilyDomain {
        $hosts = app(FamilyHosts::class);
        $domain = $hosts->request($this->superAdmin, $family, $host ?? $family->slug.'.juthoor.test');
        $hosts->verify($this->superAdmin, $domain, 'DNS A record, TLS certificate and proxy checked');
        $hosts->activate($this->superAdmin, $domain->fresh());

        return $domain->fresh();
    };
});

test('with host routing off nothing changes: every host serves the platform as before', function () {
    config(['portals.host_routing' => false]);
    $this->get('http://anything.example.org/')->assertOk()->assertSee('جذور');
    $this->get('http://alif.juthoor.test/families/alif/portal')->assertOk()->assertSee('عشيرة الألف');
});

test('unknown, inactive and non-active hosts are refused; the platform host and aliases are served', function () {
    $this->get('https://juthoor.test/')->assertOk();
    $this->get('http://localhost/')->assertOk();
    $this->get('https://evil.example.org/')->assertNotFound();
    $this->get('https://unknown.juthoor.test/')->assertNotFound();
    $pending = app(FamilyHosts::class)->request($this->superAdmin, $this->family, 'alif.juthoor.test');
    $this->get('https://alif.juthoor.test/')->assertNotFound();
    app(FamilyHosts::class)->verify($this->superAdmin, $pending, 'checked');
    $this->get('https://alif.juthoor.test/')->assertNotFound();
    app(FamilyHosts::class)->activate($this->superAdmin, $pending->fresh());
    $this->get('https://alif.juthoor.test/')->assertOk()->assertSee('عشيرة الألف')->assertDontSee('عشيرة الباء');
    // Host matching is case-insensitive and ignores a trailing dot; a disabled family is not served.
    $this->get('https://ALIF.juthoor.test./')->assertOk();
    $this->family->update(['is_active' => false]);
    $this->get('https://alif.juthoor.test/')->assertNotFound();
});

test('a family host serves only that family: other families 404, administration and authentication go to the platform host', function () {
    ($this->activate)($this->family);
    ($this->activate)($this->other);
    $this->get('https://alif.juthoor.test/families/alif/tree')->assertOk();
    $this->get('https://alif.juthoor.test/families/baa/tree')->assertNotFound();
    $this->get('https://alif.juthoor.test/families/baa/portal')->assertNotFound();
    $this->get('https://baa.juthoor.test/')->assertOk()->assertSee('عشيرة الباء')->assertDontSee('عشيرة الألف');
    $this->get('https://alif.juthoor.test/manage/families/alif/dashboard')->assertRedirect('https://juthoor.test/manage/families/alif/dashboard');
    $this->get('https://alif.juthoor.test/site-admin')->assertRedirect('https://juthoor.test/site-admin');
    $this->get('https://alif.juthoor.test/login')->assertRedirect('https://juthoor.test/login');
    $this->post('https://alif.juthoor.test/login', ['email' => 'x', 'password' => 'y'])->assertNotFound();
    // Signed-in administrators still get no administration page on a family host.
    $admin = ($this->member)($this->family, 'admin');
    $this->actingAs($admin)->get('https://alif.juthoor.test/manage/families/alif/portal')->assertRedirect('https://juthoor.test/manage/families/alif/portal');
    // The session cookie stays host-only unless SESSION_DOMAIN is configured deliberately.
    $cookie = collect($this->get('https://alif.juthoor.test/')->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
    expect($cookie?->getDomain())->toBeNull();
});

test('requests: family administrators may request only their own slug or a custom domain; reserved, platform and taken hosts are refused', function () {
    $admin = ($this->member)($this->family, 'admin');
    $editor = ($this->member)($this->family, 'editor');
    $this->actingAs($editor)->post(route('management.domains.store', 'alif'), ['host' => 'alif.juthoor.test'])->assertForbidden();
    $this->actingAs($admin)->post(route('management.domains.store', 'alif'), ['host' => 'baa.juthoor.test'])->assertSessionHasErrors('host');
    $this->actingAs($admin)->post(route('management.domains.store', 'alif'), ['host' => 'Alif.Juthoor.Test'])->assertSessionHasNoErrors();
    expect(FamilyDomain::query()->sole()->only(['host', 'kind', 'status']))->toBe(['host' => 'alif.juthoor.test', 'kind' => 'subdomain', 'status' => 'pending_verification']);
    foreach (['juthoor.test', 'www.juthoor.test', 'admin.juthoor.test', 'a.b.juthoor.test', '127.0.0.1', 'bad_host!.com', 'localhost'] as $host) {
        $this->actingAs($admin)->post(route('management.domains.store', 'alif'), ['host' => $host])->assertSessionHasErrors('host');
    }
    // A super admin may choose another label, but never a reserved one or one held by another family.
    expect(fn () => app(FamilyHosts::class)->request($this->superAdmin, $this->other, 'www.juthoor.test'))->toThrow(ValidationException::class);
    expect(fn () => app(FamilyHosts::class)->request($this->superAdmin, $this->other, 'alif.juthoor.test'))->toThrow(ValidationException::class, 'مستخدم أو مطلوب مسبقًا');
    // Another family's administrator cannot take the host either way.
    $otherAdmin = ($this->member)($this->other, 'admin');
    $this->actingAs($otherAdmin)->post(route('management.domains.store', 'baa'), ['host' => 'alif.juthoor.test'])->assertSessionHasErrors('host');
    $this->actingAs($otherAdmin)->post(route('management.domains.store', 'alif'), ['host' => 'alif.example.org'])->assertForbidden();
    // Custom domains carry a TXT verification record.
    $this->actingAs($admin)->post(route('management.domains.store', 'alif'), ['host' => 'alif-family.org'])->assertSessionHasNoErrors();
    $custom = FamilyDomain::query()->where('host', 'alif-family.org')->sole();
    expect($custom->kind)->toBe('custom')->and($custom->verificationRecord())->toStartWith('juthoor-verify=')
        ->and(AuditLog::query()->where('action', 'domain.requested')->count())->toBe(2);
});

test('lifecycle: only platform administrators verify, activate and disable, with confirmations and audit', function () {
    $admin = ($this->member)($this->family, 'admin');
    $domain = app(FamilyHosts::class)->request($admin, $this->family, 'alif.juthoor.test');
    foreach (['verify', 'activate', 'disable'] as $action) {
        $this->actingAs($admin)->post(route('site-admin.domains.transition', [$domain->id, $action]), ['notes' => 'x'])->assertForbidden();
    }
    $this->actingAs($this->superAdmin)->post(route('site-admin.domains.transition', [$domain->id, 'activate']))->assertSessionHasErrors('domain');
    $this->actingAs($this->superAdmin)->post(route('site-admin.domains.transition', [$domain->id, 'verify']), ['notes' => 'checked'])->assertSessionHasErrors();
    expect($domain->fresh()->status)->toBe('pending_verification');
    $this->actingAs($this->superAdmin)->post(route('site-admin.domains.transition', [$domain->id, 'verify']), ['notes' => 'A record, certificate and proxy verified', 'dns_confirmed' => 1, 'tls_confirmed' => 1, 'proxy_confirmed' => 1])->assertSessionHasNoErrors();
    $this->actingAs($this->superAdmin)->post(route('site-admin.domains.transition', [$domain->id, 'activate']))->assertSessionHasNoErrors();
    expect($domain->fresh()->status)->toBe('active');
    $this->get('https://alif.juthoor.test/')->assertOk();
    // One active domain per family.
    $second = app(FamilyHosts::class)->request($this->superAdmin, $this->family, 'alif-family.org');
    app(FamilyHosts::class)->verify($this->superAdmin, $second, 'checked');
    expect(fn () => app(FamilyHosts::class)->activate($this->superAdmin, $second->fresh()))->toThrow(ValidationException::class);
    // Administration always happens on the platform host (the same POST on a family host is refused).
    $platform = fn (string $name, array $parameters) => 'https://juthoor.test'.route($name, $parameters, false);
    $this->actingAs($this->superAdmin)->post('https://alif.juthoor.test'.route('site-admin.domains.transition', [$domain->id, 'disable'], false))->assertNotFound();
    $this->actingAs($this->superAdmin)->post($platform('site-admin.domains.transition', [$domain->id, 'disable']))->assertSessionHasNoErrors();
    $this->get('https://alif.juthoor.test/')->assertNotFound();
    // Reopening requires verification again.
    $this->actingAs($this->superAdmin)->post($platform('site-admin.domains.transition', [$domain->id, 'reopen']))->assertSessionHasNoErrors();
    expect($domain->fresh()->status)->toBe('pending_verification')->and($domain->fresh()->verified_at)->toBeNull()
        ->and(AuditLog::query()->where('auditable_type', (new FamilyDomain)->getMorphClass())->pluck('action')->unique()->values()->all())
        ->toContain('domain.requested', 'domain.verified', 'domain.activated', 'domain.disabled', 'domain.reopened');
    // Withdrawal by the family administrator before activation; the audit record keeps no verification token.
    $this->actingAs($admin)->delete($platform('management.domains.destroy', ['alif', $second->id]))->assertSessionHasNoErrors();
    expect(FamilyDomain::query()->whereKey($second->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->get()->every(fn (AuditLog $log) => ! array_key_exists('verification_token', $log->after ?? []) && ! array_key_exists('verification_token', $log->before ?? [])))->toBeTrue();
    $this->actingAs($this->superAdmin)->get($platform('site-admin.domains.index', []))->assertOk()->assertSee('alif.juthoor.test');
    $this->actingAs($admin)->get($platform('site-admin.domains.index', []))->assertForbidden();
});

test('reserved labels cannot become family slugs', function () {
    expect(fn () => app(FamilyBootstrapService::class)->bootstrap(['family' => ['slug' => 'www', 'name_ar' => 'محجوز']]))->toThrow(ValidationException::class);
    $this->actingAs($this->superAdmin)->put(route('site-admin.families.update', $this->family->id), ['name_ar' => 'عشيرة الألف', 'slug' => 'admin', 'is_active' => 1])->assertSessionHasErrors('slug');
    expect($this->family->fresh()->slug)->toBe('alif');
});
