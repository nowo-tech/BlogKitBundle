# Baseline specification — Blog Kit Bundle

**Package:** `nowo-tech/blog-kit-bundle`  
**Namespace:** `Nowo\BlogKitBundle`  
**Config alias:** `nowo_blog_kit`  
**Status:** Initial public baseline (v1.0.0)

## Overview

Blog Kit Bundle provides a reusable Symfony blog domain: multilingual articles and tags, moderated comments, singleton professional settings, a secured admin CRUD UI, and public Twig rendering. Persistence is Doctrine ORM. Access is enforced by roles or a custom checker (REQ-UI-002).

## User scenarios (`US-*`)

### US-01 — Browse published articles (Priority: P1)

As a visitor, I browse published articles at `/blog` and open `/blog/{slug}` so I can read content.

**Acceptance:** Unpublished articles are hidden. `blog_show` returns 404 when the body is empty.

### US-02 — Search and filter (Priority: P2)

As a visitor, I search and filter by tag. When settings use infinite listing, scrolling loads `?partial=1` fragments.

**Acceptance:** Query `q` and `tag` filter the catalogue. Partial responses contain card markup only.

### US-03 — Submit a comment (Priority: P1)

As a visitor, I submit a comment that waits for moderation.

**Acceptance:** Invalid forms flash an error. Valid comments persist as pending and do not appear until approved.

### US-04 — Manage articles and tags (Priority: P1)

As an editor, I create, edit, publish, and delete multilingual articles and tags in `/admin/blog`.

**Acceptance:** Unauthorized users are denied. Deletes are CSRF-protected.

### US-05 — Moderate comments (Priority: P1)

As a moderator, I approve, reject, reply to, and delete comments at `/admin/blog/comments`.

**Acceptance:** `canModerate()` is required. Staff replies can also be posted on the public article.

### US-06 — Configure listing (Priority: P2)

As an administrator, I edit singleton settings at `/admin/blog/settings`.

**Acceptance:** `canConfigure()` is required. Listing mode, asides, and card options apply to public pages.

### US-07 — Integrate the host app (Priority: P1)

As an integrator, I set `user_class`, locales, layouts, and security from `nowo_blog_kit`.

**Acceptance:** Flex recipe or manual YAML boots the bundle. Twig Extra is required.

### US-08 — Run demo and CI (Priority: P3)

As a maintainer, I run the Symfony 8 FrankenPHP demo and smoke checks.

**Acceptance:** `make demo-smoke` returns HTTP 200 from the demo home page.

## Functional requirements (`FR-*`)

### Bundle & configuration (`FR-BUNDLE-*`, `FR-CFG-*`, `FR-DI-*`)

| ID | Requirement |
| --- | --- |
| FR-BUNDLE-001 | Register services, routes, Twig namespace, and Doctrine attribute mappings |
| FR-CFG-001 | Expose a strict `nowo_blog_kit` configuration tree with safe defaults |
| FR-CFG-002 | Load `Resources/config/services.yaml` and publish container parameters |
| FR-DI-001 | Wire services with constructor injection |

### Articles (`FR-ART-*`)

| ID | Requirement |
| --- | --- |
| FR-ART-001 | Persist multilingual articles with translations, tags, resources, and publish flag |
| FR-ART-002 | Dispatch `BlogArticlePublishedEvent` when an article becomes published |
| FR-ART-003 | Public catalogue lists only published articles |

### Tags (`FR-TAG-*`)

| ID | Requirement |
| --- | --- |
| FR-TAG-001 | Persist multilingual tags and article links |
| FR-TAG-002 | `nowo:blog:sync-hashtags` formats trailing hashtags and links tags |

### Comments (`FR-CMT-*`)

| ID | Requirement |
| --- | --- |
| FR-CMT-001 | Public comments start pending |
| FR-CMT-002 | Approved comments (and approved replies) render on the article |
| FR-CMT-003 | Moderators can approve, reject, reply, and delete |

### Settings (`FR-SET-*`)

| ID | Requirement |
| --- | --- |
| FR-SET-001 | Singleton `BlogSettings` controls listing, asides, cards, comments, and share |

### Public UI (`FR-PUB-*`)

| ID | Requirement |
| --- | --- |
| FR-PUB-001 | Routes `blog_index` and `blog_show` render Twig under `@NowoBlogKitBundle` |
| FR-PUB-002 | Infinite listing fetches `partial=1` HTML and appends cards |

### Admin UI (`FR-ADM-*`, `FR-UI-*`)

| ID | Requirement |
| --- | --- |
| FR-ADM-001 | Admin CRUD for articles, tags, comments, and settings |
| FR-UI-001 | Configurable CSS framework and layout templates |
| FR-UI-002 | Admin routes require Symfony Security or a custom checker unless `allow_unauthenticated` |
| FR-TWIG-001 | Application templates under `templates/bundles/NowoBlogKitBundle/` take precedence |

### Persistence (`FR-ORM-*`)

| ID | Requirement |
| --- | --- |
| FR-ORM-001 | Doctrine attribute mapping; paginated admin and public queries; optional table prefix |

### Internationalization (`FR-I18N-*`)

| ID | Requirement |
| --- | --- |
| FR-I18N-002 | Ship catalogues for en, es, fr, de, it, nl, pt with key parity |
| FR-I18N-003 | Translation domain is `NowoBlogKitBundle` |

### Demo (`FR-DEMO-*`)

| ID | Requirement |
| --- | --- |
| FR-DEMO-001 | FrankenPHP Symfony 8 demo boots and returns HTTP 200 |

## Non-goals

- Untrusted-author HTML sanitization of article bodies
- Built-in comment rate limiting or CAPTCHA
- Host authentication / user management (UserKit / AuthKit remain host-owned)
- Visual page builder (see PageLayoutKitBundle)

## See also

- [`code-inventory.md`](code-inventory.md)
- [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md)
