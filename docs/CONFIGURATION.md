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
        access_roles: [ROLE_ADMIN]
        manage_roles: [ROLE_EDITOR]
        moderate_roles: [ROLE_MODERATOR]
        configure_roles: [ROLE_ADMIN]
        access_checker: null
        allow_unauthenticated: false
    web_ui:
        layout_template: '@NowoBlogKitBundle/admin/layout.html.twig'
        public_layout_template: '@NowoBlogKitBundle/public/layout.html.twig'
        css_framework: bootstrap5
        icon_set: bootstrap-icons
        row_actions_display: icon
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
| `access_roles` | `[ROLE_ADMIN]` | Canonical REQ-UI-002 key. Any matching role grants **all** admin capabilities (manage, moderate, configure). Empty list adds no extra grant. |
| `manage_roles` | `[ROLE_EDITOR]` | Article and tag admin access (`admin_blog` except comments/settings). |
| `moderate_roles` | `[ROLE_MODERATOR]` | Comment moderation (`admin_blog_comments*`). Editors with `manage_roles` also moderate. |
| `configure_roles` | `[ROLE_ADMIN]` | Settings screen (`admin_blog_settings`). |
| `access_checker` | `null` | Optional service id implementing `BlogKitAccessCheckerInterface`. |
| `allow_unauthenticated` | `false` | When `true`, the bundle uses an allow-all checker. Intended only for trusted demos. |

The bundle enforces access on route names beginning with `admin_blog` (`BlogKitAdminAccessSubscriber`).

## web_ui

| Key | Default | Description |
| --- | --- | --- |
| `layout_template` | `@NowoBlogKitBundle/admin/layout.html.twig` | Base layout used by admin screens. Point this to your own layout to embed the admin UI in host chrome. |
| `public_layout_template` | `@NowoBlogKitBundle/public/layout.html.twig` | Base layout used by public index and article pages. |
| `css_framework` | `bootstrap5` | Host CSS stack. The bundle keeps **semantic** `blog-*` markup and remaps buttons via UiKit. Allowed: `bootstrap`, `bootstrap4`, `bootstrap5`, `tabler`, `tailwind`, `foundation`, `custom`, `none`. Load the matching framework CSS in the **host** layout; switching this key MUST NOT require forking page Twig files. Default matches the FrankenPHP demo. |
| `icon_set` | `bootstrap-icons` | How action glyphs are drawn. Allowed: `bootstrap-icons`, `tabler-icons`, `ux_icon`, `svg_inline`, `none`. Prepended to UiKit when the host has not set `nowo_ui_kit.icon_set`. |
| `row_actions_display` | `icon` | Admin table row actions: `icon`, `text`, or `icon_text`. Switching this MUST NOT require forking list Twig. Prepended to UiKit when the host has not set `nowo_ui_kit.row_actions_display`. |
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
| `nowo_blog_kit_icon_set` | Selected icon set |
| `nowo_blog_kit_row_actions_display` | Selected row-action display mode |
| `nowo_blog_kit_can_manage` | Whether the current user may manage articles and tags |
| `nowo_blog_kit_can_moderate` | Whether the current user may moderate comments |
| `nowo_blog_kit_can_configure` | Whether the current user may edit blog settings |

Twig function:

| Function | Meaning |
| --- | --- |
| `nowo_blog_kit_container_class()` | Width wrapper (`blog-container`) plus `container` (Bootstrap/Tabler) or `grid-container` (Foundation). Tailwind / `custom` / `none` stay on `blog-container` only. |

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
        access_roles: [ROLE_ADMIN]
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

**Host layout + CSS framework (do not fork page templates):**

```yaml
nowo_blog_kit:
    web_ui:
        layout_template: 'admin/layout.html.twig'   # project chrome; default bundle layout is for demos
        public_layout_template: 'base.html.twig'
        css_framework: bootstrap5   # or: tailwind | foundation | custom
        icon_set: bootstrap-icons
        row_actions_display: icon   # or: text | icon_text
        privacy_url: '/privacy'
```

Keep `stylesheets` / `javascripts` blocks in the host layout. Public pages stack `nowo-ui.css` + `blog.css`. Remap look-and-feel with `--nowo-blog-*` / `--color-*` (or `--nowo-ui-*`) instead of copying `public/index.html.twig`.

If the project content block is not `admin` / `nowo_ui_content` / `body`, use a **one-file bridge**:

```twig
{# templates/admin/nowo_blog_kit_bridge.html.twig #}
{% extends 'admin/layout.html.twig' %}
{% block body %}
    {% block nowo_ui_content %}{% endblock %}
    {% block admin %}{% endblock %}
{% endblock %}
```

```yaml
nowo_blog_kit:
    web_ui:
        layout_template: 'admin/nowo_blog_kit_bridge.html.twig'
```

```yaml
# Tailwind host (load Tailwind in base.html.twig)
nowo_blog_kit:
    web_ui:
        public_layout_template: 'base.html.twig'
        css_framework: tailwind
        icon_set: none
        row_actions_display: text

# Foundation host
nowo_blog_kit:
    web_ui:
        public_layout_template: 'base.html.twig'
        css_framework: foundation
        icon_set: svg_inline
        row_actions_display: icon_text

# Own CSS: semantic blog-* + nowo-ui-* only
nowo_blog_kit:
    web_ui:
        public_layout_template: 'base.html.twig'
        css_framework: custom
        icon_set: svg_inline
        row_actions_display: text
```

Admin pages extend `@NowoBlogKitBundle/admin/base.html.twig` and public pages extend `@NowoBlogKitBundle/public/base.html.twig`. Those templates call `{{ parent() }}` in `stylesheets` / `javascripts` and then load `nowo_ui_kit` + `nowo_blog_kit` assets inside nested `nowo_ui_styles` / `nowo_ui_scripts` (REQ-UI-001). Keep matching `stylesheets` and `javascripts` blocks in the host layout so stacking works. Override only those nested blocks if you need extra CSS/JS. Do not fork every CRUD page to inject CSS/JS.

Row actions compose UiKit `_row_actions`. Switch `web_ui.row_actions_display` (`icon` / `text` / `icon_text`) without copying list templates. Deletes open a native `<dialog>` confirm (`_delete_confirm.html.twig` + UiKit `_confirm`) with POST + CSRF in the footer. The inline CMS editor is the same native-dialog contract (`_modal_form.html.twig`). See [UiKit ADOPTION](https://github.com/nowo-tech/UiKitBundle/blob/main/docs/ADOPTION.md) and [STIMULUS](https://github.com/nowo-tech/UiKitBundle/blob/main/docs/STIMULUS.md).
