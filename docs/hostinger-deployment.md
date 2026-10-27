# Hostinger deployment

Prefer setting the domain document root to `<project>/public` where the hosting plan permits it. Keep the repository layout intact: `public/index.php` loads `../vendor/autoload.php` and `../bootstrap/app.php`.

If the document root is fixed to `public_html`, upload the complete project into that directory, including **both tracked .htaccess files**. The root file internally forwards every request into `public/` exactly once; public/.htaccess serves existing assets or invokes Laravel. No host-specific .htaccess edits, moved index.php or /public URL are required. Do not leave Hostinger placeholder index.html/index.php files in public/.

The previous root file forwarded requests twice without excluding the already rewritten public subtree. This could produce public/public paths and prevent requests from reaching Laravel. It also lacked a safe boundary for existing private files. The replacement removes duplicate forwarding and PHP logging directives. Client-requested /public URLs redirect to domain-root URLs with method-preserving 308 responses; internal rewrites do not redirect. Authorization/XSRF headers and query strings are retained. HTTPS enforcement stays with the existing hosting configuration; these rules never downgrade or force a scheme/hostname.

Enable Apache/LiteSpeed rewrite support and allow .htaccess overrides, including Options, FileInfo, AuthConfig and Indexes. These are hosting requirements: repository code cannot change the domain's document root or server override policy. Public directory listing and MultiViews are disabled. Dotfiles, logs, database/backup files, private storage paths and non-front-controller PHP scripts are denied. Existing public/vendor assets remain available. This application serves media through authorized Laravel controllers; do not use a public/storage symlink for private material.

Set production environment values outside Git: APP_ENV=production, APP_DEBUG=false and APP_URL=https://your-domain (without /public). Keep existing application keys and credentials. If cached configuration references the old URL/path, rebuild the normal deployment caches after updating environment settings. Host configuration and server logs were not accessible during this repository fix; no live deployment or tests were performed.

References: [Hostinger shared-hosting Laravel layout](https://support.hostinger.com/en/articles/6152127-how-to-deploy-laravel-8-at-hostinger), [Apache per-directory rewrites](https://httpd.apache.org/docs/2.4/rewrite/flags.html).


## Interactive tree requests

Tree graph/search/quick-add endpoints now use relative URLs, and the graph loader anchors them to the page origin. This prevents a misdetected HTTP origin behind TLS termination from turning the graph fetch into a mixed-content or cross-origin request. Full-tree requests load 200 records per batch instead of 1,000 and bypass browser caches. The graph loader rejects HTML and incompatible payloads with distinct messages and logs a non-sensitive error reason/status. Both tree scripts have filemtime-based version parameters and same-origin root paths so code uploads invalidate stale browser scripts.

A read-only direct invocation of the local graph service returned 79 publicly eligible nodes and 78 edges without an exception. This is not confirmation of the deployed Hostinger database or endpoint: live domain access was unavailable. No automated tests were run. After upload, php artisan optimize:clear refreshes views/routes/config; reload the tree page. If it still fails, use the new status message and the live graph request's Network status/server log to identify the server exception rather than reseeding blindly.
