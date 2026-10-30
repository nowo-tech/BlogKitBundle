# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.8.1] - 2026-10-30](#181---2026-10-30)
- [[1.8.0] - 2026-10-28](#180---2026-10-28)
- [[1.7.0] - 2026-10-27](#170---2026-10-27)
- [[1.6.3] - 2026-10-24](#163---2026-10-24)
- [[1.6.2] - 2026-10-21](#162---2026-10-21)
- [[1.6.1] - 2026-10-20](#161---2026-10-20)
- [[1.6.0] - 2026-10-19](#160---2026-10-19)
- [[1.5.3] - 2026-10-18](#153---2026-10-18)
- [[1.5.2] - 2026-10-13](#152---2026-10-13)
- [[1.5.1] - 2026-10-13](#151---2026-10-13)
- [[1.5.0] - 2026-10-13](#150---2026-10-13)
- [[1.4.5] - 2026-10-13](#145---2026-10-13)
- [[1.4.4] - 2026-10-13](#144---2026-10-13)
- [[1.4.3] - 2026-10-13](#143---2026-10-13)
- [[1.4.2] - 2026-10-09](#142---2026-10-09)
- [[1.4.1] - 2026-09-28](#141---2026-09-28)
- [[1.4.0] - 2026-09-28](#140---2026-09-28)
- [[1.3.1] - 2026-09-27](#131---2026-09-27)
- [[1.3.0] - 2026-09-24](#130---2026-09-24)
- [[1.2.0] - 2026-08-28](#120---2026-08-28)
- [[1.1.7] - 2026-08-24](#117---2026-08-24)
- [[1.1.6] - 2026-08-19](#116---2026-08-19)
- [[1.1.5] - 2026-08-19](#115---2026-08-19)
- [[1.1.3] - 2026-08-19](#113---2026-08-19)
- [[1.1.2] - 2026-08-19](#112---2026-08-19)
- [[1.1.1] - 2026-08-19](#111---2026-08-19)
- [[1.1.0] - 2026-08-18](#110---2026-08-18)
- [[1.0.0] - 2026-08-18](#100---2026-08-18)

## [Unreleased]

### Security

- **Blog routes registered without being imported (REQ-SF-004 rule 7):** the controller services were autoconfigured, so on Symfony >= 7.3 their `#[Route]` tagged them `routing.controller` and a host's default `controllers: { resource: routing.controllers }` registered every blog route at its bare path — the public pages and the unauthenticated comment endpoint (`POST /blog/{slug}/comments`) included — even when the host never imported `@NowoBlogKitBundle/Resources/config/routing.yaml`, or a second time without the host prefix. (The admin routes stayed behind `BlogKitAdminAccessSubscriber`, which guards by route name.) The controllers are now registered with `autoconfigure: false` and explicit `controller.service_arguments` / `container.service_subscriber` tags: the routes exist only where the host imports `routing.yaml`. Regression test with a kernel whose routing is only `routing.controllers`.

## [1.8.1] - 2026-10-30

BlogKit: 1.x layout bridge wired (Twig autowired by type) See [UPGRADING](UPGRADING.md#to-181).

### Fixed

- **1.x layout bridge never used:** `BlogKitPages` received `null` instead of the Twig environment because
  `#[Autowire('@?twig')]` is not an optional reference (the attribute keeps `?` in the service id, `"?twig"`, and the
  nullable argument turned the missing service into `null`). A configured 1.x `web_ui.layout_template` /
  `web_ui.public_layout_template` was therefore extended directly instead of through the deprecated
  `admin/_legacy_layout.html.twig` / `public/_legacy_layout.html.twig` bridges. The argument is now autowired by type;
  a kernel test (`tests/Integration/LegacyLayoutBridgeWiringTest.php`) covers the wiring.

## [1.8.0] - 2026-10-28

Security: the admin UI refuses to compile without form CSRF protection (CsrfProtectionPass, fail closed). See [UPGRADING](UPGRADING.md#to-180).

### Security

- CSRF fails closed: new `CsrfProtectionPass` makes the container refuse to compile when form CSRF protection is unavailable (`security.csrf.token_manager` or `form.type_extension.csrf` missing, or `framework.form.csrf_protection: false`). Before, the admin delete / comment moderation actions (FormKit `CsrfOnlyType`) and the admin and public comment forms would accept tokenless cross-site POSTs in that configuration. Pairs with the FormKit CSRF fail-closed fix (`CsrfOnlyType` throws without the extension). See [UPGRADING](UPGRADING.md#unreleased) and [SECURITY](SECURITY.md#csrf).

## [1.7.0] - 2026-10-27

UiKit page contract (REQ-UI-003) + SEC-004 hardening: stored XSS fixes (sanitize last, DOM hashtag rewrite, escaped strip), empty role list denies, controller-class guard, prefixed native SQL See [UPGRADING](UPGRADING.md#to-170).

Admin and public pages on the UiKit page contract (REQ-UI-003, UiKit 2.15), and the SEC-004 security hardening. See [UPGRADING](UPGRADING.md#unreleased).

### Security

- **M1 — stored XSS past the default allowlist (affects 1.1.0–1.6.3):** the public article body was sanitized *before* the hashtag-link rewrite and the body enhancer, and the regex hashtag rewrite could cut a single-quoted attribute in half, turning editor text into live attributes (`autofocus onfocus=…`) for every visitor. The hashtag links are now rewritten on the DOM and the sanitizer runs **last**, after every rewrite. Regression test with the PoC payload.
- **M2 — XSS through the `strip` sanitizer (affects 1.1.0–1.6.3):** `strip` decoded entities into a body printed `|raw`, so `&lt;img onerror…&gt;` (or its double-encoded form after a persist + render pass) became live markup. It now outputs plain text escaped as HTML.
- **M3 — empty role lists granted everybody:** `security.roles.manage` / `moderate` / `configure` (or the deprecated `*_roles`) set to `[]` granted the capability to every visitor, anonymous included. An empty list now grants nothing (only `access_roles`); all lists empty fails at compile time.
- **L1:** the article LinkedIn URL is http(s) only (form constraint and render-time check): no `javascript:` links on the public article.
- **L2 / L3:** the admin guard picks the capability by controller class (comments → moderate, settings → configure, articles / tags → manage); the comment actions `admin_blog_comment_approve|reject|reply|delete` required only `canManage()`, and a routing `name_prefix` skipped the guard entirely.
- **L4:** the HTML sanitizer result on update is persisted (`PreUpdateEventArgs::setNewValue()`); edited bodies used to be stored unsanitized.
- **L5:** `comments.rate_limit`, `comments.captcha` or `html.sanitize` with strategy `service` and no service id now fail at compile time instead of silently disabling the protection.
- **L6:** `doctrine.table_prefix` applies to the `content_blog_article_tag` join table and to the repository native SQL (36 statements hard-coded `content_blog_*`).
- **L7:** the moderation queue is paginated (`web_ui.page_size`) and uses one CSRF token id per action instead of three per comment.
- Info: the inline CMS update redirects to the article (not to the `Referer`); public comments are refused when `show_comments` is off; page numbers capped (no float `OFFSET`) and search terms capped at 200 characters; article slugs validated (`[a-z0-9-]`); staff replies signed with `comments.staff_name` / `BlogStaffDisplayNameInterface` instead of the login identifier; the deprecated `nowo_blog_kit_can_*` Twig globals fail closed (`false`); IPv6 commenters rate-limited per /64; `privacy_url` escaped in the comment form label.

### Added

- **UiKit page contract (REQ-UI-003):** `Ui\BlogKitPageFactory` builds the UiKit view models (`ListPage`, `FormPage`, `SettingsPage`, `PageHeader`, `Action`, `Confirm`, `Pagination`, `EmptyState`) for every admin page; `Ui\BlogKitPages` resolves the layouts and the per-page overrides; `Ui\BlogKitAreaNav` publishes the blog navigation to the UiKit area-nav registry (`nowo_ui_area_nav('blog_kit')`, `'blog_kit_settings'`) for DashboardMenu.
- `web_ui.templates.<page>` per-page template overrides (`article_index`, `article_form`, `tag_index`, `tag_form`, `comment_index`, `settings`, `public_index`, `public_show`).
- `security.roles.{manage,moderate,configure}` (canonical REQ-UI-003 access keys).
- UiKit `PageContractTestCase` contract test (`tests/Integration/Contract/PageContractTest.php`: every admin page, both public pages, strict missing-translation rule) and a render test of the 1.x layout bridges.
- Translation key `admin.column.reply` (7 locales).
- `comments.staff_name` and `Model\BlogStaffDisplayNameInterface` (public name of staff replies).
- `BlogCommentRepository::findFilteredPaginated()`.
- Demo: UiKit host layouts (`templates/layouts/admin.html.twig` / `guest.html.twig` on `@NowoUiKit/layout/_bridge.html.twig`), Playwright e2e of the admin pages (lists, delete confirm dialog, settings, article form tabs) and refreshed WebP screenshots.

### Changed

- **Template contract:** the admin pages extend the UiKit scaffolds — `admin/index.html.twig`, `admin/tags/index.html.twig`, `admin/comments/index.html.twig` → `page/list` (data block `nowo_ui_list_row`), `admin/form.html.twig`, `admin/tags/form.html.twig` → `page/form` (`nowo_ui_form_body`), `admin/settings.html.twig` → `page/settings` (`nowo_ui_settings_section`); the public `public/index.html.twig` / `public/show.html.twig` are unique pages on the UiKit guest layout (`nowo_ui_page_header`, `nowo_ui_content`). Header actions, row actions, filters, pagination, empty states, delete confirms and the section tabs come from UiKit; the blog area nav is a page-header tab bar.
- `web_ui.layout_template` / `public_layout_template` default to `null` (`nowo_ui_kit.layouts.admin` / `guest`); the former defaults map to `null`; a 1.x layout without the UiKit canonical blocks keeps working through the deprecated bridges `admin/_legacy_layout.html.twig` / `public/_legacy_layout.html.twig`.
- The inline CMS editor (`admin/_modal_form.html.twig`) is the UiKit `dialog` partial (`#blog-kit-cms-modal`, tabs id `cms-locale` unchanged); its form body is the shared `admin/_form_body.html.twig`.
- Bundle markup carries no framework utility classes: buttons, muted text, badges and the comment form use UiKit theme slots (`nowo_ui_class()` / `nowo_ui_attrs()`); `sr-only` → `visually-hidden`; the prepended FormKit `blog_kit` profile no longer adds `form-control` / `mb-2` (the host form theme does).
- `nowo-tech/ui-kit-bundle` constraint raised to `^2.15.3`; `symfony/deprecation-contracts` required explicitly; `phpunit/phpunit` 13.4.5 (dev, advisory PKSA-qhjm-rpqc-qkn6).
- Demo: `nowo_blog_kit` config on `security.roles` and `nowo_ui_kit.layouts`; the stray Flex `compose.override.yaml` (published database port) removed.
- Flex recipe on the canonical keys (`security.roles.*`, layouts / CSS stack from `nowo_ui_kit`, `comments.staff_name`).
- `docs/SECURITY.md` threat model refreshed (roles, comment routes, `strip`, empty list = deny, backups / images).

### Deprecated

- `web_ui.css_framework`, `web_ui.icon_set`, `web_ui.row_actions_display` (default now `null`; inherited from `nowo_ui_kit`, still copied to UiKit when set) and `security.manage_roles` / `moderate_roles` / `configure_roles` (mapped to `security.roles.*`); removed in 2.0.
- Twig globals `nowo_blog_kit_layout`, `nowo_blog_kit_public_layout`, `nowo_blog_kit_css_framework`, `nowo_blog_kit_icon_set`, `nowo_blog_kit_row_actions_display` and the function `nowo_blog_kit_container_class()`.
- 1.x layouts (no UiKit canonical blocks) through `web_ui.layout_template` / `public_layout_template`.

### Removed

- Local chrome templates replaced by UiKit: `admin/layout.html.twig`, `admin/base.html.twig`, `public/layout.html.twig`, `public/base.html.twig`, `admin/_pagination.html.twig`, `admin/_delete_confirm.html.twig`, `admin/_csrf_action_form.html.twig`, `admin/_row_actions.html.twig`, `admin/_list_filters.html.twig`, `admin/_nav_tabs.html.twig`, `admin/_area_nav.html.twig`, `admin/_settings_section_tabs.html.twig`, `admin/_audit_table_heads.html.twig`, `admin/_audit_table_cells.html.twig` (→ `admin/_audit_cells.html.twig`), `admin/_form.html.twig` (→ `admin/_form_body.html.twig`).

### Documentation

- `docs/TEMPLATES.md` rewritten (pages → scaffolds, data blocks, view models, unique pages, per-page overrides, navigation, layout bridges); REQ-TWIG-001 freeze rule, upgrade-safe alternatives, recovery procedure and `<subpath>` table in `docs/TEMPLATES.md` and `docs/USAGE.md`; CONFIGURATION / INSTALLATION / README / SECURITY on `nowo_ui_kit.layouts` and `security.roles`.

## [1.6.3] - 2026-10-24

Bundle views render form bodies through the child loop (REQ-TWIG-003). See [UPGRADING](UPGRADING.md#to-163).

### Changed

- **Twig (REQ-TWIG-003):** `admin/settings.html.twig` renders each settings tab through the child loop (`{% for child in form %}` + `form_row(child)`) instead of one `form_row(form.x)` per field (the form already builds only the tab's fields); section headings and the article sub-headings are kept, `form_end` no longer passes `render_rest: false`.

## [1.6.2] - 2026-10-21

Dependency refresh (bundle and demos). See [UPGRADING](UPGRADING.md#to-162).

### Changed

- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19); shipped assets rebuilt.

## [1.6.1] - 2026-10-20

Documentation: wave-1 REQ compliance (README, CONTRIBUTING, issue templates, Spec Kit inventory). See [UPGRADING](UPGRADING.md#to-161).

### Changed

- Documentation: wave-1 REQ compliance — README documentation list order, CONTRIBUTING Cursor Agent queue settings and post-commit co-author check, canonical issue templates, TOCs for long docs, spec-driven ↔ Engram relationship, tailored Spec Kit constitution, baseline success criteria and inventory refresh (153/153).
- `AbstractBlogFormType` receives the optional `BlogLocales` through its constructor (third argument) instead of the
  `#[Required]` setter `setBlogLocales()`, so form types hold no mutable state under FrankenPHP workers (Igor clean,
  REQ-CS-008). See [UPGRADING](UPGRADING.md#to-161).
- `BlogCommentManager` takes moderation timestamps from the injected PSR-20 clock and logs approve / reject / delete
  (comment id only) through PSR-3; `StreamCaptchaHttpClient` logs siteverify outcomes (provider host only, never
  secrets or tokens) (REQ-DI-001, REQ-OBS-001, REQ-CS-007: PSR-3 adopted).
- Demo: PHP `max_execution_time` 30 s and FrankenPHP `max_wait_time` 30 s complete the timeout hierarchy above the 5 s
  CAPTCHA deadline (REQ-RUNTIME-001), documented in CONFIGURATION, DEMO-FRANKENPHP and SECURITY (new Logging and
  Timeouts sections).
- Tooling: CI runs PHPStan, Rector (dry run) and Igor; Rector targets the PHP 8.4 floor; php-cs-fixer is capped at 2
  parallel workers; `make help` follows the standard block order; the co-author check accepts git worktrees.

### Fixed

- CI coverage gate is back at 100% (tests for the CAPTCHA client timeout and logging, clock-driven moderation).

## [1.6.0] - 2026-10-19

Admin translation tabs now use FormKit 2.13 locale_tabs through the UiKit 2.5 partial (default locale first, keyboard navigation, invalid tabs opened); requires FormKit ^2.13 and UiKit ^2.5.1. See [UPGRADING](UPGRADING.md#to-160).

### Changed

- **Admin translation tabs** (article new/edit, tag new/edit, inline CMS modal) render through FormKit 2.13 `locale_tabs`
  (mode `entries`) and UiKit 2.5's `locale_tabs` partial instead of the bundle's own `admin/_locale_tabs.html.twig`:
  default locale (`nowo_blog_kit.default_locale`) listed first and marked `★` / `*`, the locale child a hidden input,
  WAI-ARIA tabs with roving `tabindex` and arrow / Home / End keys (fixes keyboard users being unable to reach the
  other tabs: every inactive tab had `tabindex="-1"` and nothing moved focus), invalid tabs marked `!` and opened
  first, `aria-invalid` on the invalid widgets, stacked fieldsets without JavaScript, theme slots
  `tabs` / `tab` / `tab_active` / `tab_panel`. Translation entries use `error_bubbling: false`, so entry errors show
  inside their tab.
- The default-locale panel no longer forces `required` on optional fields (meta title, meta description, excerpt,
  body); each field keeps its own `required` (server-side validation unchanged).
- `admin/base.html.twig` loads the UiKit runtimes through UiKit's `scripts` partial instead of listing
  `nowo-ui-tabs.js`, `nowo-ui-confirm.js` and `nowo-ui-modal.js` by hand.
- Requires `nowo-tech/form-kit-bundle ^2.13` and `nowo-tech/ui-kit-bundle ^2.5.1` (UiKit 1.x is no longer
  supported).

### Removed

- `@NowoBlogKitBundle/admin/_locale_tabs.html.twig` and its `admin.locale_tabs.label` translation key.

## [1.5.3] - 2026-10-18

Allows nowo-tech/ui-kit-bundle 2.x (^1.4 || ^2.2) so hosts can move to UiKit 2.2. See [UPGRADING](UPGRADING.md#to-153).

### Changed

- Allows nowo-tech/ui-kit-bundle 2.x (`^1.5 || ^2.2`); the dev lock and the demo lock use UiKit 2.2.0 (with RoutingKit 1.9.x, whose 1.7 line still capped UiKit at 1.x). Templates are unchanged (compatibility release). With UiKit 2.x `nowo-ui.css` ships in `@layer nowo-ui`, so unlayered host/framework CSS wins equal-specificity conflicts with kit rules; to keep the 1.x cascade, override the public/admin `stylesheets` block and link `css/nowo-ui.unlayered.css` (UiKit 2.x only).

## [1.5.2] - 2026-10-13

### Changed

- **Twig coding standard**: `make twig-lint` (twig-cs-fixer) now passes with 0 errors. `admin/_delete_confirm.html.twig` and `admin/_row_actions.html.twig` use the `include()` function (with `with_context = false`, equivalent to the former `{% include … only %}`) and `admin/settings.html.twig` drops hash-literal padding; rendered output is byte-identical (`-}}` keeps the trailing newline trimmed as the tag did). CI runs `composer twig:lint` in a new **Twig lint** job.

## [1.5.1] - 2026-10-13

### Fixed

- **English copies in the fr/de/it/nl/pt catalogues**: about 150–160 messages per locale (admin lists, filters, flashes, settings labels/help, public comment form, e.g. `blog.comments.privacy_required`) were shipped in English. They are now translated into French, German, Italian, Dutch and (European) Portuguese; placeholders and HTML are unchanged. A new `CatalogueTranslationTest` checks key parity, placeholder/HTML parity and flags any message identical to English unless it is on an allowlist of legitimately identical strings (brand and technical names such as Blog, Slug, LinkedIn, reCAPTCHA, plus per-locale words like fr "Actions"/"Date" or de "Name"/"Status").

## [1.5.0] - 2026-10-13

### Fixed

- **Translations:** raw translation keys no longer leak into the UI. The admin list filters get real placeholders (`admin_blog_article_filter.{title,slug,published}.placeholder`, comment and tag filter equivalents); the audit column headers (`admin.audit.updated_at` / `updated_by`) and the locale tabs now translate in the `NowoBlogKitBundle` domain (both partials lacked `trans_default_domain`; locale tabs fall back to the bare code when no `locale.<code>` name exists); the public comment form gets real name / email / comment placeholders and the missing `public_blog_comment.author_email.label`; the staff reply textarea gets a placeholder. All keys added to the seven shipped locales.
- **Forms:** blog kit form types (`AbstractBlogFormType`) no longer render FormKit's convention `{form}.{field}.help` / `.placeholder` keys unless the field passes them explicitly (they were shown verbatim in the article, tag, settings and comment forms). Settings fields that ship help copy pass it explicitly. New regression tests render every bundle form and check that every label / help / placeholder / choice key exists in every catalogue, and that every translating template declares the bundle domain.
- **Article form:** the tag choices (`BlogArticleType::tags`) were always labelled with the Spanish tag name (`getName('es')`). They now use the current request locale when it is one of `nowo_blog_kit.locales`, falling back to `nowo_blog_kit.default_locale` (also when the tag has no translation in the request locale).
- **Translations:** the admin pagination showed a bare "Page" (the `admin.pagination.page` messages dropped `%page%` / `%total%`), and the public comment privacy checkbox lost its link to `web_ui.privacy_url` (`blog.comments.privacy_accept` dropped `%url%`). All seven catalogues now keep the parameters (real translations for these two messages); a regression test checks that every parameter a template passes appears in every catalogue.

## [1.4.5] - 2026-10-13

### Changed

- **Assets:** removed `src/Resources/public/blog.scss`, a byte-identical copy of `blog.css` that `assets:install` published to `public/bundles/nowoblogkit/` without any use (no build step reads it). `blog.css` stays the stylesheet source.
- **Dev:** Vitest 4 (`vitest` / `@vitest/coverage-v8` `^4.1.11`).

## [1.4.4] - 2026-10-13

### Security

- **HTML allowlist:** an `<iframe>` is now kept only when its `src` is an absolute `https://` URL on the YouTube/Vimeo list. Previously only the host was checked, so `<iframe src="javascript://www.youtube.com/%0Aalert(document.cookie)">` survived and ran on the public article page. Protocol-relative, `http`, userinfo (`user@host`) and backslash forms are rejected as well.
- **Remote CAPTCHA (`recaptcha_v2`, `recaptcha_v3`, `hcaptcha`, `turnstile`):** a token is rejected when siteverify reports a `hostname` other than the current request host, and reCAPTCHA v3 tokens whose `action` is not `blog_comment` are rejected too (a token solved on another site that uses the same keys is no longer accepted).

## [1.4.3] - 2026-10-13

### Changed

- Development: `composer.json` pins `config.platform.php` to 8.4.1 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell.
- Repository: `.twig-cs-fixer.cache` is now git-ignored.

### Dependencies

- `happy-dom` 20.14.5 → 20.14.6 (frontend tooling; #25).
- `vite` 8.3.3 → 8.3.4 (frontend tooling; #26).
- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #28).
- `nowo-tech/form-kit-bundle` 2.6.2 → 2.7.1 (development lock; #29).
- `nowo-tech/ui-kit-bundle` 1.9.2 → 1.11.0 (development lock; #30).
- `@types/node` 26.6.4 → 26.6.5 (frontend tooling; #27).

## [1.4.2] - 2026-10-09

### Security

- `AllowlistBlogHtmlSanitizer`: children of an unwrapped (disallowed) element are now sanitized — they were hoisted after the walk had moved past them, so `<section><script>…</script><img onerror>…</section>` survived unchanged.
- Executable / raw-text elements (`script`, `style`, `template`, `svg`, `math`, `object`, `embed`, `meta`, `link`, `base`, …) are removed with their content instead of being unwrapped.
- `href` / `src` starting with `/\` (read as `//host` by browsers) are rejected like protocol-relative URLs; ASCII tab / LF / CR are stripped before the check (`/<TAB>/host` is `//host`).

### Dependencies

- Bundle lockfile: `nowo-tech/audit-kit-bundle` 1.1.18, `nowo-tech/form-kit-bundle` 2.6.0, `nowo-tech/ui-kit-bundle` 1.9.1; dev `phpstan/phpstan` 2.3.1, `rector/rector` 2.7.0.
- Already on `main`: Symfony group bumps, Vite 8.3.2 / `@types/node` 26.6.4 with `pnpm-lock.yaml` sync, PHP CS Fixer bot fixes.
- Demo (`demo/symfony8`): Symfony 8.1.8, `doctrine/dbal` 4.5.0, `doctrine/orm` 3.7.4, `twig/twig` 3.30.0, `nowo-tech/routing-kit-bundle` 1.5.0; regenerated `config/reference.php`.

## [1.4.1] - 2026-09-28

### Changed

- Dev dependencies: bump `@types/node` / Vite and sync `pnpm-lock.yaml` so CI `pnpm install --frozen-lockfile` succeeds.

## [1.4.0] - 2026-09-28

### Security

- Default `html.sanitize.strategy` is **`allowlist`** (was `none`). Flex recipe ships allowlist; `when@prod` still forces allowlist. Set `none` only for fully trusted editors.

### Added

- **REQ-DEMO-013:** Playwright e2e under `demo/symfony8/e2e/` (`make test-e2e`), `demo-screenshots` target, and README gallery with full demo context (masthead + public index / article / admin; `docs/images/demo/overview.png`, `article.png`, `admin.png`).

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

## [1.3.1] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.4.0]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.4.0
[1.3.1]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.3.1

## [1.3.0] - 2026-09-24

FrankenPHP worker mode with kernel **not** reset between requests (scenario B / `reset_kernel=false`): bundle-owned state is request-scoped without relying on `services_resetter`. Full write-up: [`docs/FRANKENPHP-WORKER-AUDIT.md`](FRANKENPHP-WORKER-AUDIT.md).

### Added

- **`BlogKitWorkerStateSubscriber`:** clears the bundle memos (settings, settings provider, tag caches, publish-event buffer) at the start of every main request and resets the blog entity manager when a previous request closed it.
- **Twig functions** `nowo_blog_kit_can_manage()`, `nowo_blog_kit_can_moderate()`, `nowo_blog_kit_can_configure()` evaluated per call.
- **`comments.captcha.timeout_seconds`** (default `5.0`) for the default `StreamCaptchaHttpClient`.

### Changed

- **Admin templates** use the new access functions instead of the per-user Twig globals.
- **`BlogSettingsRepository::findSingleton()`** refreshes the settings row from the database (`HINT_REFRESH`) when loading it, so a long-lived identity map cannot serve stale comment protection settings.

### Deprecated

- Twig globals `nowo_blog_kit_can_manage`, `nowo_blog_kit_can_moderate`, `nowo_blog_kit_can_configure` (frozen per Twig environment in worker mode without reset).

### Fixed

- **`BlogArticlePublishedDoctrineSubscriber`:** a failed flush no longer leaks its pending articles into the next flush (buffer cleared per top-level flush; implements `ResetInterface`).

[1.3.0]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.3.0

## [1.2.0] - 2026-08-28

### Changed

- **Admin blog settings:** split into section routes (`/admin/blog/settings/{listing|cards|index-aside|article|comments}`); `/admin/blog/settings` redirects to listing.
- **`BlogSettingsType`:** optional `section` form option; `listingMode`, `masonryStrategy`, and `heroImageMode` render as `<select>` (`expanded: false`).
- **`BlogKitAdminAccessSubscriber`:** authorize all `admin_blog_settings*` routes via `canConfigure`.
- **Templates:** portable area nav, section tabs, and sectioned settings UI (`_area_nav`, `_settings_section_tabs`, `_nav_tabs`).

### Fixed

- **Demo (symfony8):** install `pdo_mysql` so Compose MySQL `DATABASE_URL` works (REQ-DEMO-011).

### Notes

- Hosts that overrode settings with a custom controller/form for section tabs can remove those overrides and use the bundle routes/form.
- Override Twig templates if you need host-specific admin chrome; form fields come from `BlogSettingsType`.

[1.2.0]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.2.0

## [1.1.7] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).
- **Style:** PHP CS Fixer alignment.

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.1.7]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.1.7

## [1.1.6] - 2026-08-19

Restore **100%** PHP line coverage after the v1.1.5 query-memoization changes.

### Fixed

- **`BlogCatalog`:** cover sidebar tag resolution when no search/tag filters are active (published tag summaries path).

## [1.1.5] - 2026-08-19

Reduce duplicate Doctrine queries on public blog index and detail pages.

### Fixed

- **`BlogArticleRepository`:** memoize tags-by-article lookups per request (`ResetInterface`) so paginated lists and sidebars reuse cached tag rows instead of re-querying overlapping article ids.
- **`BlogTagRepository`:** memoize `findPublishedTagSummaries()` per locale per request.
- **`BlogCatalog`:** sidebar without search/tag filters reuses published tag summaries instead of a heavier filtered SQL query.

## [1.1.3] - 2026-08-19

Restore 100% PHP coverage for `BlogProtection` when no settings row exists yet.

### Fixed

- Test coverage for YAML fallback rate-limit limits when the settings singleton is absent

## [1.1.2] - 2026-08-19

Patch release fixing demo seeding on fresh databases (CI demo smoke).

### Fixed

- `BlogProtection` resolves comment/HTML strategies from YAML when no settings row exists yet, avoiding a nested Doctrine flush while seeding demo articles (`app:load-demo-blog`) with `html.sanitize.strategy: allowlist`

## [1.1.1] - 2026-08-19

Patch release restoring Symfony 7.4 CI matrix compatibility and hardening demo smoke on GitHub Actions.

### Fixed

- Test support user implements `UserInterface::eraseCredentials()` so PHPUnit runs on Symfony 7.4 (the method was removed from the interface in Symfony 8)
- Demo smoke retries longer and falls back to in-container HTTP checks when host port mapping is slow on CI runners

## [1.1.0] - 2026-08-18

Comment protection strategies, object-level publication access, listing YAML defaults, and a Bootstrap-ready FrankenPHP admin chrome.

### Added

- Configurable public-comment **rate-limit strategies**: `none`, `fixed_window` (per IP), `per_ip_article`, `sliding_window`, or a host `service`
- Configurable comment **CAPTCHA strategies**: `none`, `honeypot` (default), `recaptcha_v2`, `recaptcha_v3`, `hcaptcha`, `turnstile`, or a host `service`
- Optional article **HTML sanitizer**: `none` (default), `strip`, `allowlist`, or a host `service` — applied on persist and public render
- Admin settings can override YAML strategies (`inherit` keeps YAML). CAPTCHA secrets stay in YAML only
- YAML `listing.mode` (`paginated` / `infinite`) with admin `inherit` override for the public index
- YAML `listing.masonry` (`strategy`: `masonry` / `grid` / `list`, plus column counts) with admin `inherit` / `0` override
- Object-level admin access for publications: `security.object_access.strategy` `none` / `owner` / host `service`, enforced by `BlogKitAccessDenied` (not Symfony voters)
- FormKit `type_map.entity` prepend so admin article tags (`EntityType`) resolve without a host type map

### Changed

- Demo FrankenPHP admin uses host `admin/layout.html.twig` (Bootstrap 5 + Icons, UiKit flashes) with FormKit Bootstrap profiles and `twig.form_themes` (`bootstrap_5_layout`)
- Admin list tables compose UiKit `card` / `table_wrap` macros so Bootstrap `table-responsive` applies without forking pages

### Security

- Default comment protection: 5 posts / 60s per IP plus a honeypot field
- Public staff replies now require `canModerate()` (not only an authenticated `BlogUserInterface`)
- Optional `owner` object access so editors cannot mutate another author's publications (configure roles still see all)

## [1.0.0] - 2026-08-18

Initial public release of **Blog Kit Bundle** (`nowo-tech/blog-kit-bundle`).

### Added

- Reusable Symfony blog domain: multilingual articles, tags, comments, resources, and settings
- Public index and article pages at `/blog` and `/blog/{slug}`
- Moderated public comments and staff replies
- Admin CRUD for articles, tags, comments, and singleton settings
- Paginated or infinite-scroll listing with configurable asides
- `nowo:blog:sync-hashtags` for LinkedIn-style trailing hashtags
- `BlogArticlePublishedEvent` after an article becomes published
- Canonical `security.access_roles` plus manage / moderate / configure roles, custom checker, and `allow_unauthenticated`
- Admin and public shells: `layout_template`, `public_layout_template`, `css_framework`, `icon_set`, `row_actions_display`
- Semantic public `blog-*` markup and UiKit composition so hosts pick Bootstrap, Tailwind, Foundation, or `custom` without forking pages
- Admin/public `base.html.twig` wrappers that stack CSS/JS with `{{ parent() }}` and nested `nowo_ui_styles` / `nowo_ui_scripts` (REQ-UI-001)
- Native `<dialog>` delete confirms (UiKit `_confirm` + CSRF in the footer) and inline CMS editor
- Vite + pnpm asset pipeline (`blog-kit.js`) for infinite scroll and CollectionType add/remove
- Named Symfony asset package `nowo_blog_kit` (`asset('blog.css', 'nowo_blog_kit')`)
- Optional Doctrine `table_prefix`
- Symfony Flex recipe (including `assets:install` post-install) and FrankenPHP demo (`demo/symfony8`, port `8105`)
- Demo seed command `app:load-demo-blog` and host chrome via `public_layout_template`
- Integrator documentation and Spec Kit baseline

### Security

- Default role guards: `ROLE_ADMIN` (`access_roles` / `configure_roles`), `ROLE_EDITOR` (`manage_roles`), `ROLE_MODERATOR` (`moderate_roles`)
- Symfony Security required by default when `allow_unauthenticated` is `false`
- CSRF-protected admin mutations and public comment forms; deletes go through native confirm dialogs
- Comment bodies escaped in Twig; article HTML documented as trusted-editor `|raw`

[Unreleased]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.8.1...HEAD
[1.8.1]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.8.0...v1.8.1
[1.8.0]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.6.3...v1.7.0
[1.6.3]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.6.2...v1.6.3
[1.6.2]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.6.1...v1.6.2
[1.6.1]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.6.0...v1.6.1
[1.6.0]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.5.3...v1.6.0
[1.5.3]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.5.2...v1.5.3
[1.5.2]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.5.1...v1.5.2
[1.5.1]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.4.5...v1.5.0
[1.4.5]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.4.4...v1.4.5
[1.4.4]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.4.3...v1.4.4
[1.4.3]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.4.2...v1.4.3
[1.4.2]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.4.1...v1.4.2
[1.1.3]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.1.3
[1.1.2]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.1.2
[1.1.1]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.1.1
[1.1.0]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.1.0
[1.0.0]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.0.0
