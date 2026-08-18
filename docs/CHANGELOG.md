# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [Unreleased](#unreleased)
- [1.0.0 - 2026-08-18](#100---2026-08-18)

## [Unreleased]

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

[Unreleased]: https://github.com/nowo-tech/BlogKitBundle/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/nowo-tech/BlogKitBundle/releases/tag/v1.0.0
