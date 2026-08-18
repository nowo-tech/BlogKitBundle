# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[1.0.0] - 2026-08-18](#100---2026-08-18)

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
- Configurable security: manage / moderate / configure roles, custom checker, `allow_unauthenticated`
- Configurable admin and public shells: `layout_template`, `public_layout_template`, `css_framework`
- Optional Doctrine `table_prefix`
- Symfony Flex recipe and FrankenPHP demo (`demo/symfony8`, default port `8105`)
- Integrator documentation and Spec Kit baseline

### Security

- Default role guards: `ROLE_EDITOR` / `ROLE_MODERATOR` / `ROLE_ADMIN`
- Symfony Security required by default when `allow_unauthenticated` is `false`
- CSRF-protected admin mutations and public comment forms
- Comment bodies escaped in Twig; article HTML documented as trusted-editor `|raw`
