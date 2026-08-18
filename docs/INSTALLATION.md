# Installation

Install **Blog Kit Bundle** in a Symfony 7.4 or 8 application with Doctrine ORM, Twig, Security, FormKit, UiKit, RoutingKit, and AuditKit.

## Table of contents

- [Requirements](#requirements)
- [Composer](#composer)
- [Symfony Flex recipe](#symfony-flex-recipe)
- [Manual registration](#manual-registration)
- [Routes](#routes)
- [Database schema](#database-schema)
- [Security](#security)
- [User entity](#user-entity)
- [Twig Extra Bundle](#twig-extra-bundle)
- [Published assets](#published-assets)
- [Verify](#verify)
- [Demo application](#demo-application)

## Requirements

| Component | Version |
| --- | --- |
| PHP | 8.4 - 8.5 |
| Symfony | ^7.4 or ^8.0 |
| Doctrine Bundle | ^2.10 or ^3.0 |
| Doctrine ORM | ^2.15 or ^3.0 |
| Twig Bundle | ^7.4 or ^8.0 |
| Security Bundle | ^7.4 or ^8.0 |
| FormKitBundle | `nowo-tech/form-kit-bundle` ^2.4 |
| UiKitBundle | `nowo-tech/ui-kit-bundle` ^1.5 |
| RoutingKitBundle | `nowo-tech/routing-kit-bundle` ^1.4 |
| AuditKitBundle | `nowo-tech/audit-kit-bundle` ^1.1 |
| Twig Extra | `twig/extra-bundle` and `twig/string-extra` |

## Composer

```bash
composer require nowo-tech/blog-kit-bundle
composer require twig/extra-bundle twig/string-extra
```

`twig/extra-bundle` is required because the bundle ships Twig templates that expect Twig Extra to be enabled in the host application.

## Symfony Flex recipe

When the Flex recipe is available, it copies:

- `config/packages/nowo_blog_kit.yaml`
- `config/packages/nowo_form_kit.yaml` (filter profile + `type_map.entity` for article tags)
- `config/routes/nowo_blog_kit.yaml`

Recipe source in this repository:

`.symfony/recipe/nowo-tech/blog-kit-bundle/1.0/`

## Manual registration

If Flex is unavailable, register the bundles in `config/bundles.php`:

```php
Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true],
Nowo\FormKitBundle\NowoFormKitBundle::class => ['all' => true],
Nowo\UiKitBundle\NowoUiKitBundle::class => ['all' => true],
Nowo\RoutingKitBundle\NowoRoutingKitBundle::class => ['all' => true],
Nowo\AuditKitBundle\NowoAuditKitBundle::class => ['all' => true],
Nowo\BlogKitBundle\NowoBlogKitBundle::class => ['all' => true],
```

Then create `config/packages/nowo_blog_kit.yaml`:

```yaml
nowo_blog_kit:
    user_class: App\Entity\User
    default_locale: es
    locales: [es, en]
    security:
        access_roles: [ROLE_ADMIN]
        manage_roles: [ROLE_EDITOR]
        moderate_roles: [ROLE_MODERATOR]
        configure_roles: [ROLE_ADMIN]
        allow_unauthenticated: false
        object_access:
            strategy: none
    web_ui:
        layout_template: '@NowoBlogKitBundle/admin/layout.html.twig'
        public_layout_template: '@NowoBlogKitBundle/public/layout.html.twig'
        css_framework: bootstrap5
        icon_set: bootstrap-icons
        row_actions_display: icon
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
        captcha:
            strategy: honeypot
    html:
        sanitize:
            strategy: none
    doctrine:
        table_prefix: ''
        connection: default
```

See [CONFIGURATION.md](CONFIGURATION.md) for rate-limit, CAPTCHA, and HTML sanitizer strategies.

## Routes

Import the bundle routes:

```yaml
# config/routes/nowo_blog_kit.yaml
nowo_blog_kit:
    resource: '@NowoBlogKitBundle/Resources/config/routing.yaml'
```

This exposes public blog routes and admin CRUD routes handled by the bundle controllers.

## Database schema

The bundle registers Doctrine attribute mappings for articles, translations, tags, comments, resources, and settings.

Generate and run a migration:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Or update the schema directly in development:

```bash
php bin/console doctrine:schema:update --force
```

If you set `doctrine.table_prefix`, the bundle prefixes its own entity tables automatically through a Doctrine metadata listener.

## Security

When `security.allow_unauthenticated` is `false` (the default), install and configure `symfony/security-bundle`.

Recommended host `access_control`:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/blog, roles: ROLE_EDITOR }
```

Tighten comment moderation and settings if those roles differ:

```yaml
security:
    access_control:
        - { path: ^/admin/blog/comments, roles: ROLE_MODERATOR }
        - { path: ^/admin/blog/settings, roles: ROLE_ADMIN }
        - { path: ^/admin/blog, roles: ROLE_EDITOR }
```

You can replace role-based access with a custom service via `security.access_checker`. Scope publications with `security.object_access` (`owner` or a host `BlogKitResourceAccessCheckerInterface`).

## User entity

Set `user_class` to the host user FQCN that implements `Nowo\BlogKitBundle\Model\BlogUserInterface`. The extension maps that class as the Doctrine `resolve_target_entities` implementation for audit/blameable relations.

## Twig Extra Bundle

If your application does not already provide Twig Extra, install it explicitly:

```bash
composer require twig/extra-bundle twig/string-extra
```

Flex usually registers `Twig\Extra\TwigExtraBundle\TwigExtraBundle` automatically. If not, add it manually to `config/bundles.php`.

## Published assets

Run `php bin/console assets:install` so `blog.css` and `blog-kit.js` are available under `/bundles/nowoblogkit/` via the `nowo_blog_kit` asset package:

```twig
<link rel="stylesheet" href="{{ asset('blog.css', 'nowo_blog_kit') }}">
<script src="{{ asset('blog-kit.js', 'nowo_blog_kit') }}" defer></script>
```

Rebuild frontend with `make assets` (Vite + pnpm).

The bundle does **not** ship Bootstrap, Tailwind, or Foundation CSS. Set `web_ui.css_framework` to match the host (`bootstrap5`, `tailwind`, `foundation`, `custom`) and load that stack in both `layout_template` (admin) and `public_layout_template`. Public markup stays on semantic `blog-*` classes plus UiKit buttons. For Bootstrap hosts, register `twig.form_themes` with `@NowoFormKitBundle/form/static_blocks.html.twig` then `bootstrap_5_layout.html.twig`, and load Bootstrap Icons if `icon_set: bootstrap-icons`.

## Verify

1. Open `/blog` and confirm the public index loads.
2. Open `/admin/blog` as an authorized editor and create a published article.
3. Open `/blog/{slug}` and confirm the article body renders.
4. Submit a public comment and approve it at `/admin/blog/comments`.

## Demo application

Clone this repository and run the FrankenPHP demo:

```bash
make -C demo/symfony8 up
```

Default URL: `http://localhost:8105`

See [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).
