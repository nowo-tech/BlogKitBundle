# Blog Kit Bundle

[![CI](https://github.com/nowo-tech/BlogKitBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/BlogKitBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/blog-kit-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/blog-kit-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/blog-kit-bundle.svg)](https://packagist.org/packages/nowo-tech/blog-kit-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/BlogKitBundle.svg?style=social&label=Star)](https://github.com/nowo-tech/BlogKitBundle) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Give it a star on GitHub! It helps us maintain and improve the project.

**Reusable Symfony blog kit** for multilingual articles, tags, moderated comments, professional listing settings, LinkedIn hashtag sync, and a secured admin CRUD UI composed with UiKit and FormKit.

> Compatible with **Symfony 7.4+ and 8.x** on **PHP 8.4+**

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is **FrankenPHP worker mode friendly**.

## What is this?

Blog Kit Bundle gives Symfony applications a reusable blog domain backed by Doctrine. Editors manage articles, tags, and settings in a secured admin UI. Visitors browse a public index and article pages, search and filter by tag, and submit comments that wait for moderation. Hosts can hook `BlogArticlePublishedEvent` (for example Web Push) and sync trailing LinkedIn hashtags into tags.

## Features

- Multilingual articles and tags with locale fallback (`es`, `en` by default)
- Public index and article routes at `/blog` and `/blog/{slug}`
- Moderated comments with staff replies
- Admin CRUD for articles, tags, comments, and singleton blog settings
- Paginated or infinite-scroll listing, configurable asides, and card options
- LinkedIn hashtag formatting command `nowo:blog:sync-hashtags`
- `BlogArticlePublishedEvent` after an article becomes published
- Configurable access: manage / moderate / configure roles, custom checker, or demo-only unauthenticated mode
- Twig namespace `NowoBlogKitBundle` with host override precedence
- Symfony Flex recipe and FrankenPHP demo in `demo/symfony8`

## Quick start

```bash
composer require nowo-tech/blog-kit-bundle
composer require twig/extra-bundle twig/string-extra
```

```yaml
# config/packages/nowo_blog_kit.yaml
nowo_blog_kit:
    user_class: App\Entity\User
    default_locale: es
    locales: [es, en]
    security:
        manage_roles: [ROLE_EDITOR]
        moderate_roles: [ROLE_MODERATOR]
        configure_roles: [ROLE_ADMIN]
```

```yaml
# config/routes/nowo_blog_kit.yaml
nowo_blog_kit:
    resource: '@NowoBlogKitBundle/Resources/config/routing.yaml'
```

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Public blog: `/blog`. Admin: `/admin/blog`, `/admin/blog/tags`, `/admin/blog/comments`, `/admin/blog/settings`.

## Development

```bash
make up
make test
make phpstan
make -C demo/symfony8 up
make demo-smoke
```

Demo default URL: `http://localhost:8105`.

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release process](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [GitHub Actions CI requirements](docs/GITHUB_CI.md)
- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)

## Tests and coverage

| Area | Status | Command |
| --- | --- | --- |
| PHP `src/` coverage target | 100% | `make test-coverage-100` |
| TS/JS `src/Resources/assets` | ≥90% | `make test-ts` |
| Unit and bundle QA | Enabled | `make test` |
| Full release checks | Enabled | `make release-check` |

- PHP: 100%
- TS/JS: 100%
- Python: N/A

```bash
make test
make test-coverage
make test-coverage-100
make test-ts
make assets
make release-check
```

## License

MIT — see [LICENSE](LICENSE).
