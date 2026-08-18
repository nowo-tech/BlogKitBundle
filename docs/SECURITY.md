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
| Unauthorized editors reach article/tag/settings admin | `access_roles` / `manage_roles` / `configure_roles`, custom `access_checker`, object access (`none` / `owner` / host `service`), Symfony Security |
| IDOR on another editor's publication | `security.object_access.strategy: owner` or a host `BlogKitResourceAccessCheckerInterface`; `BlogKitAccessDenied` on edit/delete/inline |
| Unauthorized comment moderation | `moderate_roles` and route prefix `admin_blog_comments` |
| Stored XSS through article HTML | Default Twig auto-escaping; `article.body` uses `\|raw`. Optional `html.sanitize` (`allowlist` / `strip` / host `service`) on persist and render |
| Stored XSS through comments | Comment bodies are escaped; `\|nl2br` only |
| CSRF on admin delete/approve/save and public comment POST | Symfony forms and `CsrfOnlyFormFactory` |
| Comment spam | Pending moderation; configurable rate-limit strategies; configurable CAPTCHA (`honeypot` default) |
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

Object-level publication access (after roles):

```yaml
nowo_blog_kit:
    security:
        object_access:
            strategy: none      # none | owner | service
            service: null       # BlogKitResourceAccessCheckerInterface when strategy=service
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

If your project needs more context-aware **role** rules, implement `BlogKitAccessCheckerInterface` and configure `security.access_checker`. For per-publication rules, use `security.object_access` (`owner` or a host `BlogKitResourceAccessCheckerInterface`). Controllers deny through `BlogKitAccessDenied`.

Setting `allow_unauthenticated: true` is supported for local demos only.

## CSRF

Admin create/edit/delete, comment approve/reject/reply/delete, settings save, and public comment POST all go through Symfony forms. Keep CSRF tokens intact if you override those templates (REQ-SEC-005, REQ-TWIG-005).

## Public comments

- New comments are stored as **pending** until a moderator approves them.
- The privacy checkbox label can point at `web_ui.privacy_url`.
- Rate limiting defaults to `fixed_window` (5 posts / 60s per IP) via `cache.app`. Switch strategy in YAML or admin settings.
- CAPTCHA defaults to a hidden honeypot. Remote providers (`recaptcha_v2`, `recaptcha_v3`, `hcaptcha`, `turnstile`) need `site_key` / `secret_key` in YAML.
- Public staff replies require `canModerate()`.

## Rich text rendering

`public/show.html.twig` renders editor-authored article HTML with `|raw`. Enable `html.sanitize.strategy: allowlist` (or `strip` / a host `BlogHtmlSanitizerInterface`) if untrusted authors can edit bodies. `none` is for trusted editors only.

## Infinite scroll HTML

`blog-kit.js` inserts HTML fragments returned by `GET /blog?partial=1` using `DOMParser`. Those fragments are the bundle's own Twig cards (auto-escaped). Do not point the infinite-scroll URL at an untrusted origin.

## Operational guidance

- Audit which users receive `ROLE_EDITOR`, `ROLE_MODERATOR`, and `ROLE_ADMIN`.
- Leave `allow_unauthenticated: false` in production.
- Pick a comment rate-limit and CAPTCHA strategy for public internet sites. Keep remote CAPTCHA secrets in YAML.
- Set `html.sanitize.strategy` to `allowlist` if article authors are not fully trusted.
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
| **Permissions / exposure** | Manage / moderate / configure roles, optional `owner` object access, or custom checkers (`BlogKitAccessCheckerInterface` / `BlogKitResourceAccessCheckerInterface`). |
| **AI security audit (REQ-SEC-004)** | Grade **Pass (conditional)** / risk **Medium** (2026-08-18). See [AI security audit](#ai-security-audit). |

Record confirmation in the release PR or tag notes.

## AI security audit

| Field | Value |
| --- | --- |
| Date | 2026-08-18 |
| Grade | Pass (conditional) |
| Risk | Medium |
| Method | Static review of admin access subscriber, object access (`BlogKitAccessDenied`), CSRF forms, Twig escaping, article `\|raw`, public comments, infinite-scroll HTML insert, recipe defaults (`allow_unauthenticated: false`) |
| Open residuals | Public comments still need a host CAPTCHA provider for serious bot farms (honeypot is the default); `allowlist` is optional because trusted-editor `\|raw` remains |

See [CONFIGURATION.md](CONFIGURATION.md) and [USAGE.md](USAGE.md).

## Secrets

Never commit application secrets, `.env` files with credentials, or private keys. Use `.env.example` templates only.
