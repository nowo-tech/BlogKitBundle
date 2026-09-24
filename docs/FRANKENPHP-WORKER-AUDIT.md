# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/blog-kit-bundle` (`symfony-bundle`) |
| Audited revision | `v1.3.0` |
| Audit date | 2026-09-24 |
| Method | Manual review of `src/` (services, repositories, Doctrine listeners, event subscriber, controllers, Twig extension, form types and extension, command, DI extension, compiler pass, `Resources/config/services.yaml`) |
| Remediation (2026-09-24) | W-01…W-05 resolved: new `BlogKitWorkerStateSubscriber`, refreshed settings read, per-call Twig access functions, publish buffer cleared per flush, configurable captcha timeout. Regression tests simulate consecutive requests without `reset()`. |
| **Verdict** | ✅ **Viable under scenario B** — bundle memos are cleared at the start of each main request by a bundle-owned subscriber, per-user flags are Twig functions, and the publish buffer cannot outlive a failed flush. Residual host responsibility: clearing the Doctrine identity map between requests (see W-04) and migrating template overrides away from the deprecated `nowo_blog_kit_can_*` globals (see W-02). |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Memos in three repositories, `BlogSettingsProvider` and the publish buffer are cleared at every main request by `BlogKitWorkerStateSubscriber` (and still by `kernel.reset`) |
| Static properties / `static` locals | ✅ | None; only pure static helpers (`BlogUserIdResolver`, enum `values()`) |
| `ResetInterface` / `kernel.reset` coverage | ✅ | 5 stateful services implement `ResetInterface` (auto-tagged via autoconfigure), including `BlogArticlePublishedDoctrineSubscriber` |
| Request / user / locale captured in services | ✅ | Nothing captured in constructors; per-user access flags are Twig functions evaluated per call (legacy globals deprecated) |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used; config is compiled into container parameters |
| Doctrine / EntityManager | ✅ (host clears identity map) | Settings read with `HINT_REFRESH`; closed blog entity manager reset at the next main request; identity-map clearing stays the application's job |
| Output, headers, `exit`, shutdown functions | ✅ | None; controllers return `Response` objects |
| Resources (files, sockets, cURL) held open | ✅ | Captcha HTTP call uses a per-call stream context; nothing kept open |
| Memory growth across requests | ✅ | Tag caches live for one request only; Doctrine identity map growth is host responsibility |
| Blocking I/O and timeouts | ✅ Low | Captcha verification timeout configurable (`comments.captcha.timeout_seconds`, default 5 s) |
| Third-party static state | ✅ | None beyond Symfony / Doctrine / Twig |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Repository\BlogSettingsRepository` | yes | `?BlogSettings $blogSettings` (refreshed from DB on load) | ✅ reset | ✅ cleared per main request |
| `Repository\BlogArticleRepository` | yes | `$tagsByArticleCache` keyed `locale:articleId` | ✅ reset | ✅ cleared per main request |
| `Repository\BlogTagRepository` | yes | `$publishedTagSummariesCache` keyed by locale | ✅ reset | ✅ cleared per main request |
| `Service\BlogSettingsProvider` | yes | `?array $cached` (public settings array) | ✅ reset | ✅ cleared per main request |
| `EventSubscriber\BlogArticlePublishedDoctrineSubscriber` | yes | `$pending` (entities), `$flushing` flag | ✅ reset, buffer cleared per flush | ✅ buffer cleared per flush and per main request |
| `EventSubscriber\BlogKitWorkerStateSubscriber` | yes | none (`final readonly`) | ✅ | ✅ |
| `Twig\BlogKitExtension` | yes | none; access flags exposed as functions; deprecated globals still per-user | ✅ | ✅ (bundle templates); ⚠️ deprecated globals frozen |
| `Security\BlogProtection` | yes | none (`final readonly`; strategies built per call) | ✅ | ✅ (settings fresh per request) |
| `Service\BlogCatalog`, `BlogCommentManager`, `BlogTagRegistry`, `BlogHashtagProcessor`, `BlogPostBodyFormatter`, `BlogArticleBodyEnhancer`, `BlogLocalesLocaleResolver`, `Locale\BlogLocales` | yes | none (readonly deps; `RequestStack` read per call) | ✅ | ✅ |
| `Repository\BlogCommentRepository` and 3 translation/resource repositories | yes | none | ✅ | ✅ |
| Access checkers (`ConfigurableBlogKitAccessChecker`, `OwnerBlogKitResourceAccessChecker`, allow-all variants, `BlogKitAccessDenied`) | yes | none; `TokenStorage` / `AuthorizationChecker` queried per call | ✅ | ✅ |
| `EventSubscriber\BlogKitAdminAccessSubscriber`, `BlogArticleHtmlSanitizeSubscriber`, `DependencyInjection\TablePrefixListener` | yes | none (`final readonly`) | ✅ | ✅ |
| `Security\Captcha\StreamCaptchaHttpClient`, `PublicBlogCommentCaptchaTypeExtension` | yes | none | ✅ | ✅ |
| 15 form types (`Form\*`) | yes | stateless | ✅ | ✅ |
| 6 controllers (`Controller\*`, `Controller\Admin\*`) | yes | readonly deps only | ✅ | ✅ |
| `Command\SyncBlogHashtagsCommand` | CLI only | n/a | n/a | n/a |

Captcha strategies, rate limiters and HTML sanitizers are created per call by `BlogProtection` and never stored. Entities are plain Doctrine entities.

## Findings

### W-01 — Settings and tag caches only cleared by `kernel.reset` (Medium)

- **Where:** `src/Repository/BlogSettingsRepository.php:17,41,53` (cached `BlogSettings` entity), `src/Service/BlogSettingsProvider.php:22,59` (cached public array), `src/Repository/BlogArticleRepository.php:34,740-799` (`$tagsByArticleCache`), `src/Repository/BlogTagRepository.php:23,113-131` (`$publishedTagSummariesCache`). Each class implements `ResetInterface` and `reset()` clears the property; autoconfigure (`src/Resources/config/services.yaml:2-4`) tags them `kernel.reset`.
- **Worker impact:** under **A** these caches are correctly request-scoped. Under **B** they live for the whole worker: an admin change to settings (listing, aside placement, per-page) is not seen by other workers until restart, and neither are new/removed tags. More importantly, `BlogProtection` resolves the **comment captcha, rate-limit and HTML-sanitize strategies** from the cached `BlogSettings` (`src/Security/BlogProtection.php:107-174`), so tightening those settings from the admin UI would not take effect in other workers. `BlogSettingsController` calls `blogSettingsRepository->reset()` after saving (`src/Controller/Admin/BlogSettingsController.php:83`), but that only helps the worker that handled the save, and it does not clear `BlogSettingsProvider::$cached`. The data itself is public (published articles, global settings), so there is no cross-user leak. `$tagsByArticleCache` grows with `locales × articles` and is bounded by DB size.
- **Recommendation:** keep `services_resetter` enabled (default). If scenario B is ever required, drop the in-memory caches or move them to a PSR-6 pool with tag invalidation, and have the settings controller also reset `BlogSettingsProvider`.
- **Status:** Resolved — new `src/EventSubscriber/BlogKitWorkerStateSubscriber.php` (`KernelEvents::REQUEST`, priority 4096, main request only) calls `reset()` on the four caching services, so every memo lives for one request at most (and the tag caches are bounded by one request). `BlogSettingsRepository::findSingleton()` now loads the row with `Query::HINT_REFRESH`, so the first read of each request sees the latest DB values even when the entity is still managed by a long-lived identity map (freshness-critical: it drives comment protection). The settings controller redirects after saving, so the provider memo of the saving request no longer matters. No cross-request cache remains, so there is no cross-worker invalidation to handle. Tests: `tests/Integration/BlogKitWorkerStateSubscriberTest.php`.

### W-02 — Per-user access flags exposed as Twig globals (Medium)

- **Where:** `src/Twig/BlogKitExtension.php:106-121` — `getGlobals()` returns `nowo_blog_kit_can_manage`, `nowo_blog_kit_can_moderate` and `nowo_blog_kit_can_configure`, computed from the current security token. Used in `src/Resources/views/admin/index.html.twig:11,15` and `src/Resources/views/admin/_area_nav.html.twig:25,32`.
- **Worker impact:** `Twig\Environment::getGlobals()` memoizes the merged globals in `$resolvedGlobals` once extensions are initialized. Under **A**, TwigBundle tags the `twig` service `kernel.reset` with `resetGlobals`, so flags are recomputed per request. Under **B** the flags of the first user rendered by the worker are frozen for every later user: an editor can see "settings"/"moderation" links of an admin, or an admin can lose them. This is a UI-level leak only: authorization is still enforced server-side by `BlogKitAdminAccessSubscriber` (`src/EventSubscriber/BlogKitAdminAccessSubscriber.php:32-60`) and `BlogKitAccessDenied`, so it is not an access bypass.
- **Recommendation:** replace the three per-user globals with Twig functions (e.g. `nowo_blog_kit_can_manage()`) evaluated at render time, like the existing `nowo_blog_kit_can_manage_article()`. Keep only immutable config values in `getGlobals()`.
- **Status:** Resolved — `BlogKitExtension` adds `nowo_blog_kit_can_manage()`, `nowo_blog_kit_can_moderate()` and `nowo_blog_kit_can_configure()` (evaluated per call) and the bundle templates (`admin/index.html.twig`, `admin/_area_nav.html.twig`) use them. The three globals are documented public API (`docs/CONFIGURATION.md`), so they are kept for BC but **deprecated**: a boolean global cannot be made request-independent without breaking `{% if %}`. Host template overrides that still read them remain affected under B (UI only); migration is documented in `docs/UPGRADING.md`. Test: `BlogKitExtensionTest::accessFunctionsAreEvaluatedPerRenderOnTheSameTwigEnvironment` (two renders, same environment, different users).

### W-03 — Publish-event buffer not cleared when a flush fails (Medium)

- **Where:** `src/EventSubscriber/BlogArticlePublishedDoctrineSubscriber.php:21,40,58` (entities appended to `$pending` in `onFlush`), `:63-82` (`postFlush` empties the buffer). The class does not implement `ResetInterface`.
- **Worker impact:** if the flush fails after `onFlush` (DB error, constraint violation), Doctrine never calls `postFlush`, so the `BlogArticle` entities stay in `$pending`. Because the service is not reset, this happens under **both A and B**: the next successful flush in the same worker — in any later request, for any entity — dispatches `BlogArticlePublishedEvent` for an article whose publication was rolled back, using a detached entity from a closed/reset EntityManager. Host listeners (notifications, social posting) may act on a publication that never happened. The `$flushing` flag is safely restored in a `finally` block.
- **Recommendation:** implement `ResetInterface` and clear `$pending` / `$flushing` in `reset()`; also clear `$pending` at the start of each top-level `onFlush` (or listen to `onClear`) so a failed flush cannot leak into the next one.
- **Status:** Resolved — the subscriber implements `ResetInterface` (`reset()` clears `$pending` and `$flushing`), empties `$pending` at the start of every top-level `onFlush`, and is also reset by `BlogKitWorkerStateSubscriber` at each main request. Tests: `BlogArticlePublishedDoctrineSubscriberTest::failedFlushDoesNotLeakPendingArticlesIntoTheNextFlush`, `::resetClearsPendingArticlesAndFlushingFlag`, `BlogKitWorkerStateSubscriberTest::publishBufferFromAFailedFlushIsDroppedOnTheNextRequest`.

### W-04 — Relies on DoctrineBundle's reset for the EntityManager (Medium, scenario B only)

- **Where:** `src/Service/BlogCommentManager.php:42-43,65-66,78,88,94`, `src/Service/BlogTagRegistry.php:48`, admin controllers (`flush()` calls), `src/Repository/BlogSettingsRepository.php:38-39`.
- **Worker impact:** the bundle writes through the default EntityManager and never calls `ManagerRegistry::resetManager()`. Under **A**, DoctrineBundle's `kernel.reset` hook clears/resets the manager. Under **B** the identity map is never cleared (stale entities edited by other workers, memory growth), and after any exception that closes the EntityManager every later write in that worker fails with "EntityManager is closed".
- **Recommendation:** keep `services_resetter` enabled; this is standard for any Doctrine-based bundle.
- **Status:** Resolved (closed manager, freshness-critical read) / Accepted (identity map) — `BlogKitWorkerStateSubscriber` checks the manager of `BlogArticle` at each main request and calls `ManagerRegistry::resetManager(<name>)` when it is closed, so a failed flush no longer poisons the worker (DoctrineBundle resets the lazy manager service in place, so injected `EntityManagerInterface` / repositories recover). It never calls `clear()` on an open manager. The only freshness-critical read (settings that drive captcha / rate-limit / sanitizer strategies) uses `HINT_REFRESH` (W-01). Clearing the identity map between requests (stale non-critical entities, memory growth) remains the **application's responsibility** under scenario B. The bundle does not catch flush exceptions itself. Tests: `BlogKitWorkerStateSubscriberTest::closedEntityManagerIsResetByNameOnTheNextMainRequest`, `::openOrUnknownEntityManagersAreLeftUntouched`, `::closedEntityManagerIsNotResetOnSubRequests`.

### W-05 — Captcha verification timeout is fixed at 5 s (Low)

- **Where:** `src/Security/Captcha/StreamCaptchaHttpClient.php:41-50` (`file_get_contents` with `'timeout' => 5`), called from `src/Security/Captcha/RemoteCommentCaptchaStrategy.php:100-104`.
- **Worker impact:** a slow captcha provider blocks one worker thread for up to 5 s per comment submission (plus DNS resolution time, which the stream timeout does not cover). No state leaks. The timeout is explicit but not configurable.
- **Recommendation:** make the timeout configurable, or let hosts alias `CaptchaHttpClientInterface` to an implementation based on Symfony HttpClient with `timeout` / `max_duration`. Cap queued requests with FrankenPHP `max_wait_time`.
- **Status:** Resolved — new config key `nowo_blog_kit.comments.captcha.timeout_seconds` (float, 0.1–60, default `5.0`) passed by `NowoBlogKitExtension` to `StreamCaptchaHttpClient` (new optional second constructor argument, BC). DNS time is still not covered by stream timeouts (documented); hosts needing a hard cap can alias `CaptchaHttpClientInterface` to a Symfony HttpClient implementation. Tests: `ConfigurationTest`, `NowoBlogKitExtensionTest::loadPassesConfiguredCaptchaTimeoutToTheDefaultHttpClient`.

No other findings. No superglobals, `ini_set`, native headers/sessions, static caches or third-party global state were found in `src/`.

## Usage recommendations in worker mode

- `services_resetter` is no longer required for bundle-owned state; keeping it enabled (the default) is still recommended for framework and Doctrine state.
- Under scenario B, clear the Doctrine identity map between requests in the application (the bundle only resets a *closed* manager).
- In template overrides, use `nowo_blog_kit_can_manage()` / `nowo_blog_kit_can_moderate()` / `nowo_blog_kit_can_configure()` instead of the deprecated globals.
- Do not add per-user or per-request values to Twig globals in custom extensions or template overrides; use functions evaluated at render time.
- Custom `access_checker`, `object_access.service`, rate-limit, captcha or sanitizer services must stay stateless (read the token / request per call) or implement `ResetInterface`.
- Custom repositories extending `BlogArticleRepository` must clear any added cache in `reset()` (called per main request by `BlogKitWorkerStateSubscriber`).
- If you replace `CaptchaHttpClientInterface`, set explicit timeouts on the HTTP client; otherwise tune `comments.captcha.timeout_seconds`.
- The demo (`demo/symfony8/docker/frankenphp/Caddyfile`) runs FrankenPHP with a `worker` block by default (`FRANKENPHP_MODE=worker`), which exercises scenario A.

## Re-audit triggers

Re-run this audit when a change adds: a new memoized property to a repository or service (register it in `BlogKitWorkerStateSubscriber`), a new Doctrine listener that buffers entities, new values in `BlogKitExtension::getGlobals()`, a new HTTP client or external call, or any use of `$_SERVER` / `$_ENV` / static state at runtime.
