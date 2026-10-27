<?php

namespace App\Services\Portal;

use App\Models\Family;
use App\Models\FamilyDomain;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Host ⇄ family mapping. The family is the only tenant identity; a host is served for a family only when its mapping is
 * active, host routing is enabled and the family is active. Requests, verification, activation and disabling are
 * audited; only platform administrators verify, activate or disable.
 */
class FamilyHosts
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function routingEnabled(): bool
    {
        return (bool) config('portals.host_routing');
    }

    public function normalize(string $host): string
    {
        return rtrim(strtolower(trim($host)), '.');
    }

    public function isPlatformHost(string $host): bool
    {
        $host = $this->normalize($host);

        return $host === config('portals.platform_host') || in_array($host, config('portals.platform_aliases', []), true);
    }

    /** The family served on this host, or null when the host is not an active family host. */
    public function familyFor(string $host): ?Family
    {
        $domain = FamilyDomain::query()->active()->where('host', $this->normalize($host))->with('family')->first();

        return $domain?->family?->is_active ? $domain->family : null;
    }

    public function isReservedLabel(string $label): bool
    {
        return in_array(strtolower($label), config('portals.reserved_labels', []), true);
    }

    /** scheme://host[:port]; the port follows APP_URL so local development on a non-default port keeps working. */
    public function origin(string $host): string
    {
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);

        return config('portals.scheme').'://'.$host.($port ? ':'.$port : '');
    }

    /** Canonical public address of a family website: its active host when routing is on, else the platform path. */
    public function portalUrl(Family $family): string
    {
        if ($this->routingEnabled() && ($domain = $family->domains()->active()->first())) {
            return $this->origin($domain->host).'/';
        }

        return route('portal.show', $family->slug);
    }

    /**
     * Records a request; nothing is served until a platform administrator verifies and activates it. A family
     * administrator may only request its own slug as subdomain, or a custom domain proven by a TXT record.
     */
    public function request(User $user, Family $family, string $host): FamilyDomain
    {
        $host = $this->normalize($host);
        $suffix = config('portals.subdomain_suffix');
        $error = fn (string $message) => throw ValidationException::withMessages(['host' => $message]);

        if (strlen($host) > 253 || ! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $host)) {
            $error('أدخل اسم نطاق صالحًا بأحرف لاتينية صغيرة (مثل al-zoubi.example.com)؛ لا تُقبل العناوين الرقمية أو الرموز.');
        }
        if ($this->isPlatformHost($host) || filter_var($host, FILTER_VALIDATE_IP)) {
            $error('هذا العنوان محجوز للمنصة.');
        }
        $kind = $suffix && str_ends_with($host, '.'.$suffix) ? FamilyDomain::KIND_SUBDOMAIN : FamilyDomain::KIND_CUSTOM;
        if ($kind === FamilyDomain::KIND_SUBDOMAIN) {
            $label = substr($host, 0, -strlen('.'.$suffix));
            if (str_contains($label, '.')) {
                $error('النطاق الفرعي للعائلة يتكون من مقطع واحد قبل '.$suffix.'.');
            }
            if ($this->isReservedLabel($label)) {
                $error('هذا الاسم محجوز ولا يُستخدم لعائلة.');
            }
            if (! $user->isSuperAdmin() && $label !== $family->slug) {
                $error('يطلب مدير العائلة النطاق الفرعي المطابق لمعرّف العائلة فقط: '.$family->slug.'.'.$suffix);
            }
        } else {
            $platformSuffixes = array_filter([config('portals.platform_host'), $suffix]);
            foreach ($platformSuffixes as $platform) {
                if (str_ends_with($host, '.'.$platform)) {
                    $error('النطاقات التابعة للمنصة تُطلب بصيغة النطاق الفرعي المعتمد فقط.');
                }
            }
        }

        return DB::transaction(function () use ($user, $family, $host, $kind, $error): FamilyDomain {
            // Uniqueness across all families; the reply never says which family holds a host.
            if (FamilyDomain::query()->where('host', $host)->lockForUpdate()->exists()) {
                $error('هذا النطاق مستخدم أو مطلوب مسبقًا.');
            }
            $domain = FamilyDomain::query()->create([
                'family_id' => $family->id, 'host' => $host, 'kind' => $kind, 'status' => FamilyDomain::STATUS_PENDING,
                'verification_token' => Str::lower(Str::random(40)), 'requested_by' => $user->id,
            ]);
            $this->audit->record($user, $family, $domain, 'domain.requested', null, $this->snapshot($domain));

            return $domain;
        });
    }

    /** Platform administrator confirms DNS, TLS and proxy for the host (and the TXT record for a custom domain). */
    public function verify(User $admin, FamilyDomain $domain, string $notes): void
    {
        $this->transition($admin, $domain, [FamilyDomain::STATUS_PENDING], FamilyDomain::STATUS_VERIFIED, 'domain.verified', [
            'verified_at' => now(), 'verified_by' => $admin->id, 'notes' => $notes,
        ]);
    }

    public function activate(User $admin, FamilyDomain $domain): void
    {
        if (! $domain->family->is_active) {
            throw ValidationException::withMessages(['domain' => 'العائلة غير نشطة؛ فعّلها قبل تفعيل نطاقها.']);
        }
        if ($domain->family->domains()->active()->whereKeyNot($domain->id)->exists()) {
            throw ValidationException::withMessages(['domain' => 'للعائلة نطاق مفعّل؛ عطّله أولًا (نطاق واحد مفعّل لكل عائلة).']);
        }
        $this->transition($admin, $domain, [FamilyDomain::STATUS_VERIFIED], FamilyDomain::STATUS_ACTIVE, 'domain.activated', [
            'activated_at' => now(), 'activated_by' => $admin->id, 'disabled_at' => null,
        ]);
    }

    /** Disabling is immediate; a disabled host must be verified again before it can be activated. */
    public function disable(User $admin, FamilyDomain $domain, ?string $notes = null): void
    {
        $this->transition($admin, $domain, [FamilyDomain::STATUS_PENDING, FamilyDomain::STATUS_VERIFIED, FamilyDomain::STATUS_ACTIVE], FamilyDomain::STATUS_DISABLED, 'domain.disabled', [
            'disabled_at' => now(), 'notes' => $notes ?? $domain->notes,
        ]);
    }

    public function reopen(User $admin, FamilyDomain $domain): void
    {
        $this->transition($admin, $domain, [FamilyDomain::STATUS_DISABLED], FamilyDomain::STATUS_PENDING, 'domain.reopened', [
            'verified_at' => null, 'verified_by' => null, 'activated_at' => null, 'activated_by' => null,
        ]);
    }

    /** A family administrator may withdraw its own request before it is active. */
    public function withdraw(User $user, FamilyDomain $domain): void
    {
        if (! in_array($domain->status, [FamilyDomain::STATUS_PENDING, FamilyDomain::STATUS_VERIFIED], true)) {
            throw ValidationException::withMessages(['domain' => 'لا يُسحب إلا طلب لم يُفعّل بعد.']);
        }
        DB::transaction(function () use ($user, $domain): void {
            $before = $this->snapshot($domain);
            $domain->delete();
            $this->audit->record($user, $domain->family, $domain, 'domain.withdrawn', $before, null);
        });
    }

    /** @param array<int, string> $from @param array<string, mixed> $changes */
    private function transition(User $admin, FamilyDomain $domain, array $from, string $to, string $action, array $changes): void
    {
        abort_unless($admin->isSuperAdmin(), 403);
        DB::transaction(function () use ($admin, $domain, $from, $to, $action, $changes): void {
            $domain = FamilyDomain::query()->lockForUpdate()->findOrFail($domain->id);
            if (! in_array($domain->status, $from, true)) {
                throw ValidationException::withMessages(['domain' => 'لا يمكن الانتقال من الحالة «'.FamilyDomain::STATUSES[$domain->status].'» إلى «'.FamilyDomain::STATUSES[$to].'».']);
            }
            $before = $this->snapshot($domain);
            $domain->forceFill(['status' => $to] + $changes)->save();
            $this->audit->record($admin, $domain->family, $domain, $action, $before, $this->snapshot($domain));
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(FamilyDomain $domain): array
    {
        return collect($this->audit->snapshot($domain))->except('verification_token')->all();
    }
}
