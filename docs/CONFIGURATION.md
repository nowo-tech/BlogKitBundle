# Configuration

All options live under the root key `nowo_blog_kit`.

## Table of contents

- [Full YAML tree](#full-yaml-tree)
- [Top-level options](#top-level-options)
- [security](#security)
- [web_ui](#web_ui)
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
        manage_roles: [ROLE_EDITOR]
        moderate_roles: [ROLE_MODERATOR]
        configure_roles: [ROLE_ADMIN]
        access_checker: null
        allow_unauthenticated: false
    web_ui:
        layout_template: '@NowoBlogKitBundle/admin/layout.html.twig'
        public_layout_template: '@NowoBlogKitBundle/public/layout.html.twig'
        css_framework: tailwind
        icon_set: bootstrap-icons
        page_size: 20
        privacy_url: '#'
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
| `web_ui` | map | see YAML | Admin and public shells, CSS framework, pagination, privacy URL. |
| `doctrine` | map | see YAML | Bundle table prefixing and connection name. |

This bundle uses a single configuration tree. It does not expose `default_profile` / `profiles` of its own. FormKit profiles used by admin filters are documented below.

## security

| Key | Default | Description |
| --- | --- | --- |
| `manage_roles` | `[ROLE_EDITOR]` | Any matching role grants article and tag admin access when no custom checker is configured. |
| `moderate_roles` | `[ROLE_MODERATOR]` | Grants comment moderation (`admin_blog_comments*`). |
| `configure_roles` | `[ROLE_ADMIN]` | Grants the settings screen (`admin_blog_settings`). |
| `access_checker` | `null` | Optional service id implementing `BlogKitAccessCheckerInterface`. |
| `allow_unauthenticated` | `false` | When `true`, the bundle uses an allow-all checker. Intended only for trusted demos. |

The bundle enforces access on route names beginning with `admin_blog` (`BlogKitAdminAccessSubscriber`).

## web_ui

| Key | Default | Description |
| --- | --- | --- |
| `layout_template` | `@NowoBlogKitBundle/admin/layout.html.twig` | Base layout used by admin screens. Point this to your own layout to embed the admin UI in host chrome. |
| `public_layout_template` | `@NowoBlogKitBundle/public/layout.html.twig` | Base layout used by public index and article pages. |
| `css_framework` | `tailwind` | Styling hint exposed to Twig. Allowed values: `bootstrap`, `bootstrap4`, `bootstrap5`, `tabler`, `tailwind`, `foundation`, `custom`, `none`. |
| `icon_set` | `bootstrap-icons` | Hint prepended to UiKit when the host has not set `nowo_ui_kit.icon_set`. |
| `page_size` | `20` | Admin list page size (1–200). |
| `privacy_url` | `#` | URL used in the public comment privacy checkbox label. |

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
| `nowo_blog_kit_layout` | Active admin layout template |
| `nowo_blog_kit_public_layout` | Active public layout template |
| `nowo_blog_kit_css_framework` | Selected CSS framework hint |
| `nowo_blog_kit_default_locale` | Configured default locale |
| `nowo_blog_kit_locales` | Configured locales |
| `nowo_blog_kit_privacy_url` | Privacy policy URL for the comment form |
| `nowo_blog_kit_can_manage` | Whether the current user may manage articles and tags |
| `nowo_blog_kit_can_moderate` | Whether the current user may moderate comments |
| `nowo_blog_kit_can_configure` | Whether the current user may edit blog settings |

## FormKit profiles

The extension prepends FormKit profiles when the host has not already defined them:

- `blog_kit` — defaults for blog forms (`translation_domain: NowoBlogKitBundle`)
- `filter` — GET list filters (`auto_placeholder`, no labels)

Override those keys in `nowo_form_kit.profiles` if you need project-specific form chrome.

## Translation overrides

Catalogues ship as `NowoBlogKitBundle.{locale}.yaml` under `src/Resources/translations/` (en, es, it, fr, pt, de, nl).

To override strings in the host application, place the same domain in `translations/NowoBlogKitBundle.{locale}.yaml` (or XLIFF). Symfony loader order gives the application catalogue precedence for the same domain and locale.

See also [USAGE.md](USAGE.md) and [SECURITY.md](SECURITY.md).

## Examples

**Role-based editor access:**

```yaml
nowo_blog_kit:
    security:
        manage_roles: [ROLE_EDITOR, ROLE_ADMIN]
        moderate_roles: [ROLE_MODERATOR, ROLE_ADMIN]
        configure_roles: [ROLE_ADMIN]
```

**Custom access checker service:**

```yaml
nowo_blog_kit:
    security:
        access_checker: App\Security\BlogEditorAccessChecker
```

**Host layout integration:**

```yaml
nowo_blog_kit:
    web_ui:
        layout_template: 'admin/layout.html.twig'
        public_layout_template: 'base.html.twig'
        css_framework: bootstrap5
        privacy_url: '/privacy'
```

Admin pages extend `@NowoBlogKitBundle/admin/base.html.twig` and public pages extend `@NowoBlogKitBundle/public/base.html.twig`. Those templates call `{{ parent() }}` in `stylesheets` / `javascripts` and then load `nowo_ui_kit` + `nowo_blog_kit` assets (REQ-UI-001). Keep matching `stylesheets` and `javascripts` blocks in the host layout so stacking works. Do not fork every CRUD page to inject CSS/JS.
