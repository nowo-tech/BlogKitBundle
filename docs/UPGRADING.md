# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.8.1](#to-181)
- [To 1.8.0](#to-180)
  - [CSRF protection is now required at compile time](#csrf-protection-is-now-required-at-compile-time)
- [To 1.7.0](#to-170)
  - [Template contract](#template-contract)
  - [Upgrade steps to the page contract](#upgrade-steps-to-the-page-contract)
  - [Security hardening — behaviour changes](#security-hardening--behaviour-changes)
- [To 1.6.3](#to-163)
- [To 1.6.2](#to-162)
- [To 1.6.1](#to-161)
- [To 1.6.0](#to-160)
- [To 1.5.3](#to-153)
- [To 1.5.2](#to-152)
- [To 1.5.1](#to-151)
- [To 1.5.0](#to-150)
- [To 1.4.5](#to-145)
- [To 1.4.4](#to-144)
- [To 1.4.3](#to-143)
- [To 1.4.2](#to-142)
- [To 1.4.1](#to-141)
- [To 1.4.0](#to-140)
- [To 1.3.1](#to-131)
- [From 1.2.0 to 1.3.0](#from-120-to-130)
- [From 1.1.7 to 1.2.0](#from-117-to-120)
  - [Breaking / behaviour changes](#breaking--behaviour-changes)
  - [Upgrade steps](#upgrade-steps)
- [From 1.1.6 to 1.1.7](#from-116-to-117)
- [1.1.6](#116)
- [1.1.5](#115)
- [1.1.3](#113)
- [1.1.2](#112)
- [1.1.1](#111)
- [1.1.0](#110)
- [1.0.0](#100)
- [Future releases](#future-releases)

## Unreleased

## To 1.8.1

From **1.8.0**:

```bash
composer update nowo-tech/blog-kit-bundle
```

## To 1.8.0

From **1.7.0**:

```bash
composer update nowo-tech/blog-kit-bundle
```

### CSRF protection is now required at compile time

The container no longer compiles when NowoBlogKitBundle is enabled without form CSRF protection: you get a `LogicException` ("require CSRF protection"). Standard Flex hosts (`symfony/security-csrf` installed, `framework.csrf_protection` enabled, `framework.form.csrf_protection` not disabled) need no change. Otherwise run `composer require symfony/security-csrf` and enable `framework.csrf_protection: true` — also in the `test` environment of functional suites that boot the bundle.

## To 1.7.0

From **1.6.3**:

```bash
composer update nowo-tech/blog-kit-bundle
```

From **1.6.3**:

```bash
composer update nowo-tech/blog-kit-bundle nowo-tech/ui-kit-bundle
```

The admin and public pages move to the UiKit page contract (REQ-UI-003); UiKit **2.15.3** or later is required.

### Template contract

| 1.6 | Now |
| --- | --- |
| Pages extend `admin/base.html.twig` → `web_ui.layout_template` (block `admin`) | Admin pages extend `@NowoUiKit/page/list`, `page/form` or `page/settings` → `nowo_ui_kit.layouts.admin` (or `web_ui.layout_template`) |
| Public pages extend `public/base.html.twig` → `web_ui.public_layout_template` (block `body`) | Unique pages on `nowo_ui_kit.layouts.guest` (or `web_ui.public_layout_template`): `nowo_ui_page_header`, `nowo_ui_content` |
| Content block `admin` / `body`, `title`, `stylesheets`, `javascripts` | Canonical blocks `nowo_ui_document_title`, `nowo_ui_head`, `nowo_ui_styles`, `nowo_ui_navigation`, `nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`, `nowo_ui_scripts` |
| Bundle data in page markup | Data blocks `nowo_ui_list_row` (lists), `nowo_ui_form_body` (forms), `nowo_ui_settings_section` (settings); view model `page` |
| `admin/_pagination`, `_delete_confirm`, `_csrf_action_form`, `_row_actions`, `_list_filters`, `_nav_tabs`, `_area_nav`, `_settings_section_tabs`, `_audit_table_*`, `admin/_form` | Removed: UiKit partials (`pagination`, `confirm`, `action`, `list_filters`, `tabs`), `admin/_audit_cells.html.twig`, `admin/_form_body.html.twig` |
| `admin/_modal_form.html.twig`: `<dialog class="nowo-ui-modal">` | UiKit `dialog` partial, same `#blog-kit-cms-modal` id, `cms-locale` tabs and form id |

1.x variables stay in the page context (`page_title`, `items`, `pagination`, `filters`, `filter_form`, `delete_forms`,
`action_forms`, `reply_forms`, `form`, `edit_section`, …) for host templates.

### Upgrade steps to the page contract

1. **Layouts.** Point `nowo_ui_kit.layouts.admin` / `guest` at layouts that render the UiKit canonical blocks (the
   `@NowoUiKit/layout/_bridge.html.twig` one-file bridge on top of your base layout, see
   [CONFIGURATION](CONFIGURATION.md#examples)) and drop `web_ui.layout_template` / `public_layout_template` (or point
   them at those layouts). Until then a 1.x layout still works through a deprecated bridge (`admin` / `body` block),
   and the former defaults `@NowoBlogKitBundle/admin/layout.html.twig` / `public/layout.html.twig` mean the UiKit layouts.
2. **CSS stack.** Move `web_ui.css_framework` / `icon_set` / `row_actions_display` to `nowo_ui_kit` (deprecated; still
   copied when UiKit has no value).
3. **Roles.** Rename `security.manage_roles` / `moderate_roles` / `configure_roles` to
   `security.roles.manage` / `moderate` / `configure` (the old keys are mapped, deprecated).
4. **Overrides.** Delete application copies of the removed partials and of `admin/*` pages under
   `templates/bundles/NowoBlogKitBundle/`; theme UiKit once (`nowo_ui_kit.theme`, scaffold overrides under
   `templates/bundles/NowoUiKitBundle/`) or use `web_ui.templates.<page>` with a data-block override
   ([TEMPLATES](TEMPLATES.md#per-page-overrides)).
5. **Form classes.** The prepended FormKit `blog_kit` profile no longer adds `form-control` / `mb-2`: Bootstrap hosts
   get them from `bootstrap_5_layout.html.twig` in `twig.form_themes` (or set them in `nowo_form_kit.profiles.blog_kit`).
6. **Twig globals.** Stop reading `nowo_blog_kit_layout`, `nowo_blog_kit_public_layout`,
   `nowo_blog_kit_css_framework`, `nowo_blog_kit_icon_set`, `nowo_blog_kit_row_actions_display` and
   `nowo_blog_kit_container_class()` (deprecated; the CSS / icon globals are `null` unless the deprecated keys are set).
7. `php bin/console cache:clear` and `assets:install`.

### Security hardening — behaviour changes

1. **Empty role lists deny.** `security.roles.manage` / `moderate` / `configure: []` (or `*_roles: []`) used to grant
   that capability to **everyone, anonymous included**. They now grant nothing (only `access_roles` then). If you
   relied on an empty list, list the roles explicitly. With `access_roles` and every `roles.*` empty the container
   no longer compiles (use `allow_unauthenticated: true` only for a trusted demo).
2. **`html.sanitize.strategy: strip` outputs escaped text.** Entities are no longer decoded into the `|raw` body: a
   body like `a &lt; b` renders as the text `a < b`. Stored bodies are re-escaped on render; no migration needed.
3. **`service` strategies need a service id.** `comments.rate_limit`, `comments.captcha` or `html.sanitize` with
   `strategy: service` and no `service` now fail at compile time (they used to disable the protection).
4. **`doctrine.table_prefix` now also prefixes the join table and the native SQL.** With a prefix configured, the
   article / tag join table becomes `<prefix>content_blog_article_tag` and the public listings read the prefixed
   tables (they used to read the unprefixed ones). **Migration:** generate a migration
   (`doctrine:migrations:diff`) and rename the join table instead of letting Doctrine drop / recreate it, e.g.
   `ALTER TABLE content_blog_article_tag RENAME TO tenant_a_content_blog_article_tag;` (MySQL / SQLite /
   PostgreSQL). Without a prefix nothing changes.
5. **Moderation queue paginated** (`web_ui.page_size`, `?page=`); comment action forms use one CSRF token id per
   action (`blog_comment_approve|reject|delete`) — host templates that build these forms themselves must use them.
6. **Admin guard by controller class.** The comment action routes now require `canModerate()` (they required
   `canManage()`); routes of the bundle controllers are guarded whatever their name.
7. **Smaller changes:** the inline CMS update redirects to the article page (not to the `Referer`); public comments
   answer 404 when `show_comments` is off; staff replies are signed with `comments.staff_name` (default `Team`) or
   `BlogStaffDisplayNameInterface::getBlogDisplayName()` instead of the e-mail local part; article slugs must match
   `[a-z0-9]+(-[a-z0-9]+)*` and the LinkedIn URL must be http(s); the deprecated `nowo_blog_kit_can_*` Twig globals
   are always `false` (use the `nowo_blog_kit_can_*()` functions); IPv6 commenters share the rate limit of their /64.

## To 1.6.3

From **1.6.2**:

```bash
composer update nowo-tech/blog-kit-bundle
```

## To 1.6.2

From **1.6.1**:

```bash
composer update nowo-tech/blog-kit-bundle
```

## To 1.6.1

From **1.6.0**:

```bash
composer update nowo-tech/blog-kit-bundle
```

Only if your application **extends `AbstractBlogFormType`** with its own constructor: `setBlogLocales()` was removed;
pass the optional `BlogLocales` as the third constructor argument instead:

```php
public function __construct(FormOptionsMerger $merger, FormTypeMap $map, ?BlogLocales $blogLocales = null)
{
    parent::__construct($merger, $map, $blogLocales);
}
```

Autowired subclasses without a constructor need no change. Optionally route the bundle logs (channel `app`, context
`bundle: blog_kit`) — see [SECURITY.md](SECURITY.md#logging).

## To 1.6.0

From **1.5.3**:

```bash
composer update nowo-tech/blog-kit-bundle
```

From **1.5.3**:

```bash
composer update nowo-tech/blog-kit-bundle nowo-tech/form-kit-bundle nowo-tech/ui-kit-bundle -W
```

Requires **FormKit ^2.13** and **UiKit ^2.5.1** (UiKit 1.x dropped). The admin translation tabs now come from FormKit
`locale_tabs` rendered through UiKit's `locale_tabs` partial.

What hosts may notice:

- **Removed partial:** `@NowoBlogKitBundle/admin/_locale_tabs.html.twig`. Delete any override in
  `templates/bundles/NowoBlogKitBundle/admin/_locale_tabs.html.twig`; customise the tabs with UiKit theme slots
  (`tabs`, `tab`, `tab_active`, `tab_panel`) or `theme.partials.locale_tabs` instead. Overrides of `admin/_form.html.twig`
  / `admin/_modal_form.html.twig` that include the old partial must include
  `@NowoFormKitBundle/form/_locale_tabs.html.twig` (see [TEMPLATES](TEMPLATES.md)).
- **Changed DOM ids / classes:** tab buttons `locale-tab-<locale>` → `tab-locale-<locale>`, panels
  `locale-panel-<locale>` → `tab-locale-<locale>-panel` (modal: `cms-locale-tab-<locale>` → `tab-cms-locale-<locale>`,
  `cms-locale-panel-<locale>` → `tab-cms-locale-<locale>-panel`); the root is `#locale` / `#cms-locale`
  (`[data-nowo-ui-locale-tabs]`). The `admin-locale-tabs*` classes and the `is-active` class are gone: style
  `.nowo-ui-locale-tabs*` or the theme slots, select the open tab with `[aria-selected="true"]`. `data-nowo-ui-tab-id`
  values are now `<tabs id>-<locale>` (were the bare locale). E2E selectors based on the old ids must be updated.
- **JavaScript:** nothing to add or remove. `nowo-ui-tabs.js` still drives the tabs (now loaded via UiKit's `scripts`
  partial from `admin/base.html.twig`); hosts that mount editors in the panels listen to `nowo-ui:tabs:change`
  (`event.detail.panel`). Without JavaScript every locale renders as a stacked fieldset.
- **Translation key removed:** `admin.locale_tabs.label` (the tab list label now comes from FormKit / UiKit
  `locale_tabs.label`).
- The default-locale panel no longer adds `required` to optional fields.

## To 1.5.3

From **1.5.2**:

```bash
composer update nowo-tech/blog-kit-bundle
```

## To 1.5.2

From **1.5.1** — Twig coding-standard cleanup; twig-lint in CI

```bash
composer update nowo-tech/blog-kit-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.5.1

From **1.5.0** — real fr/de/it/nl/pt translations

```bash
composer update nowo-tech/blog-kit-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.5.0

From **1.4.5** — no raw translation keys in admin and public forms, localized tag labels

```bash
composer update nowo-tech/blog-kit-bundle
```

- Blog kit form types no longer render FormKit's convention `{form}.{field}.help` / `.placeholder` keys implicitly (they were untranslated and appeared verbatim). If your app translated any of those keys in its own `NowoBlogKitBundle` catalogue and wants them back, override the form type (or use a form type extension) to pass `help` / `placeholder` explicitly. No action needed otherwise.

## To 1.4.5

From **1.4.4** — duplicate blog.scss removed from public assets; vitest 4.

```bash
composer update nowo-tech/blog-kit-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.4.4

From **1.4.3** — security - https-only embed iframes, CAPTCHA hostname/action checks.

```bash
composer update nowo-tech/blog-kit-bundle
```

- **Embeds:** stored article iframes with `http://`, protocol-relative (`//…`) or otherwise non-`https` YouTube/Vimeo URLs are now dropped by the `allowlist` sanitizer. Switch them to `https://`.
- **Remote CAPTCHA:** the siteverify `hostname` must match the request host. Behind a reverse proxy, configure `framework.trusted_proxies` / `trusted_headers` so `Request::getHost()` is the public host. Provider test keys that report a fixed hostname (e.g. reCAPTCHA `testkey.google.com`) now fail verification. reCAPTCHA v3 tokens must use the `blog_comment` action (the bundled script already does).

## To 1.4.3

From **1.4.2** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/blog-kit-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.4.2

From **1.4.1** — `allowlist` HTML sanitizer security fix and dependency updates.

```bash
composer update nowo-tech/blog-kit-bundle
```

- No breaking changes. **No application upgrade steps.**
- With `html.sanitize.strategy: allowlist`, `script` / `style` / `template` / `svg` / `math` / `object` / `embed` / `meta` / `link` / `base` (and other raw-text elements) are now removed together with their content, and children of other disallowed wrappers are sanitized. Article bodies saved before this release are re-sanitized on public render; re-save them if you also want the stored HTML cleaned.

## To 1.4.1

From **1.4.0** — maintainers npm lockfile only. **No application upgrade steps.**

```bash
composer update nowo-tech/blog-kit-bundle
```

## To 1.4.0

From **1.3.1** — default HTML sanitize allowlist; Doctrine `SortDirection`; demo e2e.

```bash
composer update nowo-tech/blog-kit-bundle
php bin/console cache:clear
```

- Default `html.sanitize.strategy` is **`allowlist`** (was `none`). Hosts that need unsanitized editor HTML must set `strategy: none` explicitly (trusted editors only).
- Ensure `doctrine/orm` is `^3.7` (SortDirection).

## To 1.3.1

From **1.3.0** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/blog-kit-bundle
php bin/console cache:clear
```

- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

This document describes how to upgrade **Blog Kit Bundle** between released versions.

## From 1.2.0 to 1.3.0

No breaking changes.

```bash
composer update nowo-tech/blog-kit-bundle
```

1. **Twig globals `nowo_blog_kit_can_manage`, `nowo_blog_kit_can_moderate`, `nowo_blog_kit_can_configure` are deprecated.** In template overrides, replace `{% if nowo_blog_kit_can_moderate %}` with `{% if nowo_blog_kit_can_moderate() %}` (same for `_manage` / `_configure`). The globals keep the first user's value for the lifetime of a FrankenPHP/RoadRunner worker when `services_resetter` does not run.
2. **Optional:** tune `nowo_blog_kit.comments.captcha.timeout_seconds` (default `5.0`, previous hard-coded value).
3. Host listeners of `BlogArticlePublishedEvent` no longer receive events for articles whose flush failed earlier in the same worker.
4. Under FrankenPHP worker with kernel not reset between requests, clear the Doctrine identity map between requests in the host app if you need stale-entity safety beyond the bundle's closed-manager recovery (see [`FRANKENPHP-WORKER-AUDIT.md`](FRANKENPHP-WORKER-AUDIT.md)).

## From 1.1.7 to 1.2.0

### Breaking / behaviour changes

- **`admin_blog_settings` (GET)** redirects to `admin_blog_settings_listing`. There is no longer a single-page POST on `/admin/blog/settings`.
- New routes: `admin_blog_settings_listing`, `_cards`, `_index_aside`, `_article`, `_comments`.
- **`BlogSettingsType`:** `listingMode`, `masonryStrategy`, and `heroImageMode` are selects (not radio groups). Pass `section` (`listing`|`cards`|`index-aside`|`article`|`comments`) to build one tab; omit for the full form (BC for tests/API).

### Upgrade steps

```bash
composer update nowo-tech/blog-kit-bundle
```

1. Remove host overrides that duplicated sectioned settings (`HostBlogSettingsController`, custom section form) if present.
2. Clear router/Twig cache and smoke-test `/admin/blog/settings`.
3. If you styled radio lists for listing/masonry choices, switch CSS to select widgets.

## From 1.1.6 to 1.1.7

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/blog-kit-bundle
```

## 1.1.6

Patch release restoring CI coverage gate. **No integrator upgrade steps.**

```bash
composer update nowo-tech/blog-kit-bundle
```

## 1.1.5

Patch release reducing duplicate Doctrine queries on public blog pages. **No integrator upgrade steps.**

```bash
composer update nowo-tech/blog-kit-bundle
```

## 1.1.3

Patch release with no schema, configuration, or API changes. Upgrade with:

```bash
composer update nowo-tech/blog-kit-bundle
```

## 1.1.2

Patch release with no schema, configuration, or API changes. Upgrade with:

```bash
composer update nowo-tech/blog-kit-bundle
```

## 1.1.1

Patch release with no schema, configuration, or API changes. Upgrade with:

```bash
composer update nowo-tech/blog-kit-bundle
```

## 1.1.0

Schema: `content_blog_settings` gains `comment_rate_limit_strategy`, `comment_rate_limit_limit`, `comment_rate_limit_interval_seconds`, `comment_captcha_strategy`, `html_sanitize_strategy`, and `masonry_strategy` (defaults `inherit` / `0`). Column defaults for `masonry_columns_*` become `0` (YAML inherit). `listing_mode` default becomes `inherit` (YAML `listing.mode`). Run Doctrine schema update.

New YAML keys:

```yaml
nowo_blog_kit:
    security:
        object_access:
            strategy: none           # none | owner | service
            service: null
    listing:
        mode: paginated          # paginated | infinite
        masonry:
            strategy: masonry    # masonry | grid | list
            columns_mobile: 1    # 1–2
            columns_tablet: 2    # 1–2
            columns_desktop: 2   # 1–3
    comments:
        rate_limit:
            strategy: fixed_window   # none | fixed_window | per_ip_article | sliding_window | service
            limit: 5                 # 0 disables
            interval_seconds: 60
            service: null
        captcha:
            strategy: honeypot       # none | honeypot | recaptcha_v2 | recaptcha_v3 | hcaptcha | turnstile | service
            site_key: ''
            secret_key: ''
            min_score: 0.5
            honeypot_field: website
            service: null
    html:
        sanitize:
            strategy: none           # none | strip | allowlist | service
            service: null
```

Defaults enable a honeypot and a per-IP fixed window (5 / 60s). Set `comments.rate_limit.strategy: none` and `comments.captcha.strategy: none` to restore the previous open form. CAPTCHA provider keys must stay in YAML, not in the settings admin.

New settings rows inherit `listing.mode` and `listing.masonry` from YAML (`paginated` + `masonry` by default). Existing `listing_mode=paginated` rows keep numbered pages until changed in admin. Existing masonry column values (`1`/`2`/`2`) keep overriding YAML until set to `0`.

Staff replies on the public article now require `canModerate()`.

New YAML `security.object_access` (`none` default). Set `strategy: owner` so editors only manage publications they created (`createdBy`). `canConfigure()` still sees every row. For custom rules implement `BlogKitResourceAccessCheckerInterface` and set `strategy: service`. This is the bundle access layer (`BlogKitAccessDenied`), not a Symfony Security voter. Roles are unchanged.

The extension prepends FormKit `type_map.entity` → `Symfony\Bridge\Doctrine\Form\Type\EntityType` so article tag fields resolve. You can still override `nowo_form_kit.type_map` in the host. Bootstrap 5 hosts should register `twig.form_themes` (`@NowoFormKitBundle/form/static_blocks.html.twig` then `bootstrap_5_layout.html.twig`) and load framework CSS / Bootstrap Icons in `web_ui.layout_template`. See the FrankenPHP demo under `demo/symfony8/`.

## 1.0.0

This is the first public release of `nowo-tech/blog-kit-bundle`. There is no earlier tagged upgrade path.

Install it with:

```bash
composer require nowo-tech/blog-kit-bundle
composer require twig/extra-bundle twig/string-extra
```

Then:

1. Register the bundle and its dependencies if Flex does not do it for you. Require `nowo-tech/ui-kit-bundle` **^1.5**.
2. Import `config/routes/nowo_blog_kit.yaml`.
3. Set `user_class` to your host user entity that implements `BlogUserInterface`.
4. Apply the Doctrine schema changes.
5. Configure Security for `/admin/blog` (`access_control` plus bundle `security.*` roles).
6. Run `php bin/console assets:install` and load files with `asset('…', 'nowo_blog_kit')`.
7. Point `web_ui.layout_template` / `public_layout_template` at the project layouts. Keep `stylesheets` / `javascripts` blocks so `{{ parent() }}` stacking works. Prefer nested `nowo_ui_styles` / `nowo_ui_scripts` for extra assets.
8. Set `web_ui.css_framework` to match the host (`bootstrap5` default), plus `icon_set` and `row_actions_display` if needed.
9. Do not restore raw `<form>` / `<input>` or `window.confirm` in template overrides. Deletes use native `<dialog>` confirms with POST + CSRF.

If you ran an untagged snapshot that defaulted `css_framework` to `tailwind`, set `web_ui.css_framework` explicitly or accept the `bootstrap5` default.

See [INSTALLATION.md](INSTALLATION.md), [CONFIGURATION.md](CONFIGURATION.md), and [USAGE.md](USAGE.md).

## Future releases

Breaking changes and migration notes will be listed here under a new version heading.
