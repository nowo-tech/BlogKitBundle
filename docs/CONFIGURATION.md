# Configuration

All options live under the root key `nowo_blog_kit`.

## Table of contents

- [Full YAML tree](#full-yaml-tree)
- [Top-level options](#top-level-options)
- [security](#security)
- [web_ui](#web_ui)
- [listing](#listing)
- [comments](#comments)
  - [comments.rate_limit](#commentsrate_limit)
  - [comments.captcha](#commentscaptcha)
  - [Timeout hierarchy](#timeout-hierarchy)
- [html](#html)
- [doctrine](#doctrine)
- [Twig globals](#twig-globals)
- [FormKit profiles](#formkit-profiles)
- [Translation overrides](#translation-overrides)
- [Examples](#examples)

## Full YAML tree

```yaml
nowo_blog_kit:
    user_class: null
    default_locale: es
    locales: [es, en]
    security:
        access_roles: [ROLE_ADMIN]
        roles:
            manage: [ROLE_EDITOR]
            moderate: [ROLE_MODERATOR]
            configure: [ROLE_ADMIN]
        access_checker: null
        allow_unauthenticated: false
        object_access:
            strategy: none
            service: null
    web_ui:
        layout_template: null          # null = nowo_ui_kit.layouts.admin
        public_layout_template: null   # null = nowo_ui_kit.layouts.guest
        templates: {}                  # page id => template (per-page override)
        page_size: 20
        privacy_url: '#'
    listing:
        mode: paginated
        masonry:
            strategy: masonry
            columns_mobile: 1
            columns_tablet: 2
            columns_desktop: 2
    comments:
        rate_limit:
            strategy: fixed_window
            limit: 5
            interval_seconds: 60
            service: null
        captcha:
            strategy: honeypot
            site_key: ''
            secret_key: ''
            min_score: 0.5
            honeypot_field: website
            timeout_seconds: 5.0
            service: null
    html:
        sanitize:
            strategy: allowlist
            service: null
    doctrine:
        table_prefix: ''
        connection: default
```

## Top-level options

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `user_class` | string\|null | `null` | FQCN of the host user entity implementing `BlogUserInterface`. Required for Doctrine `resolve_target_entities`. |
| `default_locale` | string | `es` | Default locale used for translation fallback and `BlogLocales`. |
| `locales` | list<string> | `[es, en]` | Locales exposed to admin translation forms and public fallback. |
| `security` | map | see YAML | Access control for manage / moderate / configure routes. |
| `web_ui` | map | see YAML | Layouts (UiKit page contract), per-page templates, admin page size, privacy URL. |
| `doctrine` | map | see YAML | Bundle table prefixing and connection name. |

This bundle uses a single configuration tree. It does not expose `default_profile` / `profiles` of its own. FormKit profiles used by admin filters are documented below.

## security

| Key | Default | Description |
| --- | --- | --- |
| `access_roles` | `[ROLE_ADMIN]` | Canonical REQ-UI-002 key. Any matching role grants **all** admin capabilities (manage, moderate, configure). Empty list adds no extra grant. |
| `roles.manage` | `[ROLE_EDITOR]` | Article and tag admin access (`admin_blog` except comments/settings). |
| `roles.moderate` | `[ROLE_MODERATOR]` | Comment moderation (`admin_blog_comments*`). Editors with `roles.manage` also moderate. |
| `roles.configure` | `[ROLE_ADMIN]` | Settings screen (`admin_blog_settings`). |
| `manage_roles`, `moderate_roles`, `configure_roles` | `null` | **Deprecated since 1.7** (REQ-UI-003 vocabulary): mapped to `roles.manage` / `roles.moderate` / `roles.configure` when those are not set. |
| `access_checker` | `null` | Optional service id implementing `BlogKitAccessCheckerInterface`. |
| `allow_unauthenticated` | `false` | When `true`, the bundle uses an allow-all checker. Intended only for trusted demos. |
| `object_access.strategy` | `none` | After roles: `none` (every granted editor sees all rows), `owner` (publications scoped to `createdBy`; configure roles see all), or `service`. |
| `object_access.service` | `null` | Service id implementing `BlogKitResourceAccessCheckerInterface` when `strategy: service`. |

The bundle enforces **roles** on route names beginning with `admin_blog` (`BlogKitAdminAccessSubscriber`). Object checks run in admin controllers via `BlogKitAccessDenied` (edit/delete/inline, and listing filter for `owner` / custom services). Twig helpers `nowo_blog_kit_can_manage_article()`, `nowo_blog_kit_can_manage_tag()`, and `nowo_blog_kit_can_moderate_comment()` hide row actions; they are not a substitute for the service.

## web_ui

| Key | Default | Description |
| --- | --- | --- |
| `layout_template` | `null` | Layout of the admin pages (UiKit scaffolds). `null` = `nowo_ui_kit.layouts.admin` (recommended: set your layout once in UiKit for every bundle). A layout that renders the UiKit canonical blocks is used as it is; a 1.x layout (no `nowo_ui_page_header` block, content in `admin`) is wrapped by the deprecated bridge `admin/_legacy_layout.html.twig`. The former default `@NowoBlogKitBundle/admin/layout.html.twig` maps to `null`. |
| `public_layout_template` | `null` | Layout of the public index and article. `null` = `nowo_ui_kit.layouts.guest`. A 1.x layout (content in `body`, e.g. `base.html.twig`) is wrapped by the deprecated bridge `public/_legacy_layout.html.twig`. The former default `@NowoBlogKitBundle/public/layout.html.twig` maps to `null`. |
| `templates` | `{}` | Per-page template overrides: `article_index`, `article_form`, `tag_index`, `tag_form`, `comment_index`, `settings`, `public_index`, `public_show` => a host template extending `@!NowoBlogKitBundle/...` (see [TEMPLATES](TEMPLATES.md#per-page-overrides)). |
| `css_framework`, `icon_set`, `row_actions_display` | `null` | **Deprecated since 1.7**: the CSS stack is inherited from `nowo_ui_kit.css_framework` / `icon_set` / `row_actions_display`. When set (and the UiKit key is not), the value is copied to UiKit. |
| `page_size` | `20` | Admin list page size (1–200). |
| `privacy_url` | `#` | URL used in the public comment privacy checkbox label. |

## listing

Public `/blog` index. Admin settings can override with `inherit` (use YAML). Column fields use `0` to keep YAML.

| Key | Default | Description |
| --- | --- | --- |
| `mode` | `paginated` | `paginated` (numbered pages) or `infinite` (IntersectionObserver loads `?partial=1` card fragments). |
| `masonry.strategy` | `masonry` | `masonry` (packed columns), `grid` (uniform rows), or `list` (single column). |
| `masonry.columns_mobile` | `1` | Columns below 640px (`1`–`2`). Ignored when strategy is `list`. |
| `masonry.columns_tablet` | `2` | Columns from 640px (`1`–`2`). Ignored when strategy is `list`. |
| `masonry.columns_desktop` | `2` | Columns from 960px (`1`–`3`). Ignored when strategy is `list`. |

`BlogSettings.per_page` (admin) is the page size and the infinite-scroll batch size.

## comments

Public `POST /blog/{slug}/comments` protection. Admin settings (`/admin/blog/settings`) can override the strategies with `inherit` meaning “use YAML”. CAPTCHA `site_key` / `secret_key` are YAML-only.

### comments.rate_limit

| Key | Default | Description |
| --- | --- | --- |
| `strategy` | `fixed_window` | `none`, `fixed_window` (per IP), `per_ip_article`, `sliding_window`, or `service`. |
| `limit` | `5` | Max posts per interval. `0` disables the limiter. |
| `interval_seconds` | `60` | Window length. |
| `service` | `null` | Service id implementing `BlogCommentRateLimiterInterface` when `strategy: service`. |

Requires Symfony `cache.app`. Without a cache pool, limiting is skipped.

### comments.captcha

| Key | Default | Description |
| --- | --- | --- |
| `strategy` | `honeypot` | `none`, `honeypot`, `recaptcha_v2`, `recaptcha_v3`, `hcaptcha`, `turnstile`, or `service`. |
| `site_key` | `''` | Public widget key for remote providers. |
| `secret_key` | `''` | Server-side verification key. |
| `min_score` | `0.5` | Minimum reCAPTCHA v3 score. |
| `honeypot_field` | `website` | Hidden field name for the honeypot strategy. |
| `timeout_seconds` | `5.0` | Timeout (0.1–60 s) of the remote verification call made by the default `StreamCaptchaHttpClient`. DNS resolution is not covered; alias `CaptchaHttpClientInterface` to a Symfony HttpClient implementation for full control. |
| `service` | `null` | Service id implementing `BlogCommentCaptchaStrategyInterface` when `strategy: service`. |

Remote providers need `site_key` and `secret_key`. Missing keys fail closed (form invalid).

### Timeout hierarchy

`timeout_seconds` is the innermost deadline (REQ-RUNTIME-001): a timed-out or failed verification fails closed and logs a warning. Keep PHP `max_execution_time` strictly greater than it, your reverse proxy / Caddy `servers.timeouts.write` greater than PHP, and (under FrankenPHP) a `max_wait_time` so requests do not queue forever for a busy thread. Raise them together. The demo values are listed in [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md#timeout-hierarchy).

## html

| Key | Default | Description |
| --- | --- | --- |
| `sanitize.strategy` | `allowlist` | `allowlist` (default), `strip`, `service`, or `none` (trusted-editor `\|raw`). |
| `sanitize.service` | `null` | Service id implementing `BlogHtmlSanitizerInterface` when `strategy: service`. |

Sanitizing runs on article translation persist and again on public render (before hashtag links / body enhancer).

## doctrine

| Key | Default | Description |
| --- | --- | --- |
| `table_prefix` | `''` | Prefix applied to all bundle entity tables through `TablePrefixListener`. |
| `connection` | `default` | Connection name recorded in configuration for host alignment. |

Example:

```yaml
nowo_blog_kit:
    doctrine:
        table_prefix: 'tenant_a_'
```

## Twig globals

The bundle Twig extension publishes:

| Global | Meaning |
| --- | --- |
| `nowo_blog_kit_layout` | **Deprecated** (1.7): configured `web_ui.layout_template`; the pages resolve the layout through `BlogKitPages` |
| `nowo_blog_kit_public_layout` | **Deprecated** (1.7): configured `web_ui.public_layout_template` |
| `nowo_blog_kit_css_framework` | **Deprecated** (1.7): deprecated `web_ui.css_framework` (null unless set); read `nowo_ui_kit_css_framework` |
| `nowo_blog_kit_default_locale` | Configured default locale |
| `nowo_blog_kit_locales` | Configured locales |
| `nowo_blog_kit_privacy_url` | Privacy policy URL for the comment form |
| `nowo_blog_kit_icon_set` | **Deprecated** (1.7): deprecated `web_ui.icon_set` (null unless set) |
| `nowo_blog_kit_row_actions_display` | **Deprecated** (1.7): deprecated `web_ui.row_actions_display` (null unless set) |
| `nowo_blog_kit_can_manage` | **Deprecated**, use `nowo_blog_kit_can_manage()`. Whether the current user may manage articles and tags |
| `nowo_blog_kit_can_moderate` | **Deprecated**, use `nowo_blog_kit_can_moderate()`. Whether the current user may moderate comments |
| `nowo_blog_kit_can_configure` | **Deprecated**, use `nowo_blog_kit_can_configure()`. Whether the current user may edit blog settings |

Twig resolves globals once per environment. In long-running workers (FrankenPHP / RoadRunner) without `services_resetter`, the `nowo_blog_kit_can_*` globals keep the flags of the first user rendered by the worker; the functions below are evaluated on every call.

Twig function:

| Function | Meaning |
| --- | --- |
| `nowo_blog_kit_container_class()` | Width wrapper (`blog-container`) plus `container` (Bootstrap/Tabler) or `grid-container` (Foundation). Tailwind / `custom` / `none` stay on `blog-container` only. |
| `nowo_blog_kit_captcha()` | Active comment CAPTCHA context (`strategy`, `site_key`, `script_url`, `widget_class`). |
| `nowo_blog_kit_can_manage_article(article)` | Object-level publication access (after roles). |
| `nowo_blog_kit_can_manage_tag(tag)` | Object-level tag access. |
| `nowo_blog_kit_can_moderate_comment(comment)` | Object-level comment moderation access. |
| `nowo_blog_kit_can_manage()` | Whether the current user may manage articles and tags (evaluated per call). |
| `nowo_blog_kit_can_moderate()` | Whether the current user may moderate comments (evaluated per call). |
| `nowo_blog_kit_can_configure()` | Whether the current user may edit blog settings (evaluated per call). |

## FormKit profiles

The extension prepends FormKit profiles when the host has not already defined them:

- `blog_kit` — defaults for blog forms (`translation_domain: NowoBlogKitBundle`)
- `filter` — GET list filters (`auto_placeholder`, no labels)

The extension also prepends FormKit `type_map.entity` → `Symfony\Bridge\Doctrine\Form\Type\EntityType` so article tag fields resolve. Override `nowo_form_kit.type_map` in the host if needed.

Override those keys in `nowo_form_kit.profiles` if you need project-specific form chrome. For Bootstrap 5 hosts, set `nowo_form_kit.css_framework: bootstrap` (FormKit has no `bootstrap5` value), add `field_types` for `checkbox` / `choice`, and register `twig.form_themes` with `@NowoFormKitBundle/form/static_blocks.html.twig` then `bootstrap_5_layout.html.twig`. The FrankenPHP demo does this in `demo/symfony8/config/packages/`.

## Translation overrides

Catalogues ship as `NowoBlogKitBundle.{locale}.yaml` under `src/Resources/translations/` (en, es, it, fr, pt, de, nl).

To override strings in the host application, place the same domain in `translations/NowoBlogKitBundle.{locale}.yaml` (or XLIFF). Symfony loader order gives the application catalogue precedence for the same domain and locale.

See also [USAGE.md](USAGE.md) and [SECURITY.md](SECURITY.md).

## Examples

**Role-based editor access:**

```yaml
nowo_blog_kit:
    security:
        access_roles: [ROLE_ADMIN]
        roles:
            manage: [ROLE_EDITOR, ROLE_ADMIN]
            moderate: [ROLE_MODERATOR, ROLE_ADMIN]
            configure: [ROLE_ADMIN]
```

**Custom access checker service:**

```yaml
nowo_blog_kit:
    security:
        access_checker: App\Security\BlogEditorAccessChecker
```

**Editors only manage their own publications:**

```yaml
nowo_blog_kit:
    security:
        roles:
            manage: [ROLE_EDITOR]
            configure: [ROLE_ADMIN]
        object_access:
            strategy: owner
```

**Custom object-level rules (teams, workflow, …):**

```yaml
nowo_blog_kit:
    security:
        object_access:
            strategy: service
            service: App\Security\BlogPublicationAccessChecker
```

**Host layouts and CSS stack (UiKit page contract, REQ-UI-003 — do not fork page templates):**

```yaml
# Once for every nowo-tech bundle
nowo_ui_kit:
    css_framework: bootstrap5   # or: tailwind | foundation | tabler | custom | none
    icon_set: bootstrap-icons
    row_actions_display: icon   # or: text | icon_text
    layouts:
        admin: 'layouts/admin.html.twig'   # renders the UiKit canonical blocks
        guest: 'layouts/guest.html.twig'   # public blog pages

nowo_blog_kit:
    web_ui:
        privacy_url: '/privacy'
```

A host layout renders the canonical blocks (`nowo_ui_document_title`, `nowo_ui_head`, `nowo_ui_styles`,
`nowo_ui_navigation`, `nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`,
`nowo_ui_scripts`). The one-file bridge on top of a project base layout uses UiKit's `_bridge` (the FrankenPHP demo
does this in `demo/symfony8/templates/layouts/`):

```twig
{# templates/layouts/admin.html.twig #}
{% extends 'base.html.twig' %}
{% use '@NowoUiKit/layout/_bridge.html.twig' %}
{% block title %}{{ block('nowo_ui_bridge_title') }}{% endblock %}
{% block stylesheets %}
    {{ parent() }}
    <link rel="stylesheet" href="{{ asset('css/nowo-ui.css', 'nowo_ui_kit') }}">
    {{ nowo_ui_tokens() }}
    {{ block('nowo_ui_bridge_assets') }}
{% endblock %}
{% block body %}{{ block('nowo_ui_bridge_body') }}{% endblock %}
{% block javascripts %}
    {{ parent() }}
    {{ include(nowo_ui_partial('scripts', nowo_ui_bundle|default(null))) }}
    {{ block('nowo_ui_bridge_end') }}
{% endblock %}
```

Theme the look once in `nowo_ui_kit.theme` (class / attribute maps, `--nowo-ui-*` tokens, exceptions under
`theme.bundles.NowoBlogKitBundle`) and with `--nowo-blog-*` / `blog-*` CSS for the public cards, instead of copying
`public/index.html.twig`. `web_ui.layout_template` / `public_layout_template` remain per-bundle overrides; 1.x layouts
still work through the deprecated bridges (see [UPGRADING](UPGRADING.md)).

**Public listing (paginator or infinite scroll):**

```yaml
nowo_blog_kit:
    listing:
        mode: infinite   # paginated | infinite
        masonry:
            strategy: masonry   # masonry | grid | list
            columns_mobile: 1
            columns_tablet: 2
            columns_desktop: 2
```

**Comment rate limit + CAPTCHA + HTML sanitizer:**

```yaml
nowo_blog_kit:
    comments:
        rate_limit:
            strategy: per_ip_article
            limit: 3
            interval_seconds: 120
        captcha:
            strategy: recaptcha_v3
            site_key: '%env(BLOG_CAPTCHA_SITE_KEY)%'
            secret_key: '%env(BLOG_CAPTCHA_SECRET_KEY)%'
            min_score: 0.5
    html:
        sanitize:
            strategy: allowlist
```

Admin pages extend the UiKit scaffolds (`@NowoUiKit/page/list|form|settings.html.twig`) and stack `blog.css` /
`blog-kit.js` in `nowo_ui_styles` / `nowo_ui_scripts` after `{{ parent() }}`; the public pages are unique pages on
the guest layout (see [TEMPLATES](TEMPLATES.md)). Row actions, filters, pagination, empty states and delete confirms
are the UiKit ones (`View\Action` with a CSRF-only `FormView` behind a `View\Confirm` → native `<dialog>`, no
`window.confirm`). The inline CMS editor is the UiKit `dialog` partial (`admin/_modal_form.html.twig`).
