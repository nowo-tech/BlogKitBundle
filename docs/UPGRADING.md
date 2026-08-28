# Upgrading

This document describes how to upgrade **Blog Kit Bundle** between released versions.

## Table of contents


- [From 1.1.7 to 1.2.0](#from-117-to-120)
- [From 1.1.6 to 1.1.7](#from-116-to-117)
- [Unreleased](#unreleased)
- [1.1.6](#116)
- [1.1.5](#115)
- [1.1.3](#113)
- [1.1.2](#112)
- [1.1.1](#111)
- [1.1.0](#110)
- [1.0.0](#100)
- [Future releases](#future-releases)

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

## Unreleased

No upgrade notes yet.

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
