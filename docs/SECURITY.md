# Security

Security considerations for public blog pages, comments, admin CRUD, and CMS HTML.

## Table of contents

- [Threat model](#threat-model)
- [Admin access guard](#admin-access-guard)
- [CSRF](#csrf)
- [Public comments](#public-comments)
- [Rich text rendering](#rich-text-rendering)
- [Infinite scroll HTML](#infinite-scroll-html)
- [Operational guidance](#operational-guidance)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit](#ai-security-audit)
- [Secrets](#secrets)

## Threat model

| Risk | Mitigation |
| --- | --- |
| Unauthorized editors reach article/tag/settings admin | `access_roles` / `manage_roles` / `configure_roles`, custom `access_checker`, Symfony Security |
| Unauthorized comment moderation | `moderate_roles` and route prefix `admin_blog_comments` |
| Stored XSS through article HTML | Default Twig auto-escaping; `article.body` uses `\|raw` for trusted editors only |
| Stored XSS through comments | Comment bodies are escaped; `\|nl2br` only |
| CSRF on admin delete/approve/save and public comment POST | Symfony forms and `CsrfOnlyFormFactory` |
| Comment spam | Comments start pending; host should add rate limiting |
| Overly broad demo access | `allow_unauthenticated` defaults to `false` |
| Shared-database table collisions | Optional `doctrine.table_prefix` |

## Admin access guard

The bundle protects route names beginning with `admin_blog` (`BlogKitAdminAccessSubscriber`).

Default configuration:

```yaml
nowo_blog_kit:
    security:
        access_roles: [ROLE_ADMIN]
        manage_roles: [ROLE_EDITOR]
        moderate_roles: [ROLE_MODERATOR]
        configure_roles: [ROLE_ADMIN]
        allow_unauthenticated: false
```

Firewall the admin prefix in the host app (`path_prefix` `/admin/blog`):

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/blog/comments, roles: ROLE_MODERATOR }
        - { path: ^/admin/blog/settings, roles: ROLE_ADMIN }
        - { path: ^/admin/blog, roles: ROLE_EDITOR }
```

If your project needs more context-aware rules, implement `BlogKitAccessCheckerInterface` and configure `security.access_checker`.

Setting `allow_unauthenticated: true` is supported for local demos only.

## CSRF

Admin create/edit/delete, comment approve/reject/reply/delete, settings save, and public comment POST all go through Symfony forms. Keep CSRF tokens intact if you override those templates (REQ-SEC-005, REQ-TWIG-005).

## Public comments

- New comments are stored as **pending** until a moderator approves them.
- The privacy checkbox label can point at `web_ui.privacy_url`.
- The bundle does not ship rate limiting. Add a host limiter or CAPTCHA on `blog_comment_create` for public internet sites.

## Rich text rendering

`public/show.html.twig` renders editor-authored article HTML with `|raw`. That is intentional for CMS-managed rich text, but it means:

- Only trusted editors should update article bodies.
- Hosts should sanitize content before storage or before rendering if untrusted HTML is possible.
- Template overrides should keep escaping behavior explicit and reviewed.

## Infinite scroll HTML

`blog-kit.js` inserts HTML fragments returned by `GET /blog?partial=1` using `DOMParser`. Those fragments are the bundle's own Twig cards (auto-escaped). Do not point the infinite-scroll URL at an untrusted origin.

## Operational guidance

- Audit which users receive `ROLE_EDITOR`, `ROLE_MODERATOR`, and `ROLE_ADMIN`.
- Leave `allow_unauthenticated: false` in production.
- Set a real `web_ui.privacy_url`.
- Use `doctrine.table_prefix` when multiple applications share one schema.
- Review demo credentials and never copy demo auth settings into production.

## Release security checklist (12.4.1)

Before tagging a release, confirm:

| Item | Notes |
| --- | --- |
| **SECURITY.md** | This document is current and linked from the README. |
| **`.gitignore` and `.env`** | `.env`, `.env.dev`, and local env files are ignored; no committed secrets. |
| **No secrets in repo** | No API keys, passwords, or tokens in tracked files. |
| **Admin UI exposure** | `security.allow_unauthenticated` is `false` in recipe defaults; document `access_control` for `^/admin/blog`. |
| **Input / output** | Comment bodies escaped; article `\|raw` documented as trusted-editor HTML. |
| **CSRF** | Mutations use Symfony forms. |
| **Dependencies** | `composer audit` run; issues triaged. |
| **Permissions / exposure** | Manage / moderate / configure roles or custom `BlogKitAccessCheckerInterface`. |
| **AI security audit (REQ-SEC-004)** | Grade **Pass (conditional)** / risk **Medium** (2026-08-18). See [AI security audit](#ai-security-audit). |

Record confirmation in the release PR or tag notes.

## AI security audit

| Field | Value |
| --- | --- |
| Date | 2026-08-18 |
| Grade | Pass (conditional) |
| Risk | Medium |
| Method | Static review of admin access subscriber, CSRF forms, Twig escaping, article `\|raw`, public comments, infinite-scroll HTML insert, recipe defaults (`allow_unauthenticated: false`) |
| Open residuals | Article HTML is trusted-editor `\|raw`; public comments need host rate limiting; keep host `access_control` on `/admin/blog` |

See [CONFIGURATION.md](CONFIGURATION.md) and [USAGE.md](USAGE.md).

## Secrets

Never commit application secrets, `.env` files with credentials, or private keys. Use `.env.example` templates only.
