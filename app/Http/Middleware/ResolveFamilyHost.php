<?php

namespace App\Http\Middleware;

use App\Services\Portal\FamilyHosts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With host routing enabled, every request must arrive on the platform host (or an alias) or on an active family host.
 * A family host serves only that family's public website (its home, its /families/{slug}/… pages and media); every
 * other path — administration, authentication, other families — goes to the platform host, so sessions, admin pages
 * and other families are never reachable through a family's address. Unknown hosts are refused.
 */
class ResolveFamilyHost
{
    public const ATTRIBUTE = 'portalFamily';

    public function __construct(private readonly FamilyHosts $hosts) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->hosts->routingEnabled()) {
            return $next($request);
        }
        $host = $request->getHost();
        if ($this->hosts->isPlatformHost($host)) {
            return $next($request);
        }
        $family = $this->hosts->familyFor($host);
        abort_unless($family, 404);

        $path = trim($request->path(), '/');
        $own = 'families/'.$family->slug;
        $allowed = $path === '' || $path === $own || str_starts_with($path, $own.'/') || str_starts_with($path, 'media/') || $path === 'up';
        if (! $allowed) {
            // Another family's pages are never served under this family's name.
            abort_if(str_starts_with($path, 'families/'), 404);
            abort_unless(in_array($request->method(), ['GET', 'HEAD'], true), 404);

            return redirect()->away($this->hosts->origin(config('portals.platform_host')).'/'.$path.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
        }
        $request->attributes->set(self::ATTRIBUTE, $family);

        return $next($request);
    }
}
