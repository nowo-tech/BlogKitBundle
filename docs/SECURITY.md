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
- [Timeouts and resource limits](#timeouts-and-resource-limits)
- [Logging](#logging)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit](#ai-security-audit)
- [Secrets](#secrets)

## Threat model

| Risk | Mitigation |
| --- | --- |
| Unauthorized editors reach article/tag/settings admin | `access_roles` / `roles.manage` / `roles.configure`, custom `access_checker`, object access (`none` / `owner` / host `service`), Symfony Security. **An empty role list grants nothing** (only `access_roles` then); every list empty fails at container compile time |
| IDOR on another editor's publication | `security.object_access.strategy: owner` or a host `BlogKitResourceAccessCheckerInterface`; `BlogKitAccessDenied` on edit/delete/inline |
| Unauthorized comment moderation | `roles.moderate` (or `roles.manage`); the guard picks the capability by **controller class** (`BlogCommentController` → moderate), so the queue `admin_blog_comments_index` and the actions `admin_blog_comment_approve\|reject\|reply\|delete` are covered, whatever routing `name_prefix` the host uses |
| Stored XSS through article HTML | `article.body` is printed `\|raw`: `html.sanitize` (`allowlist` default / `strip` / host `service`) runs on persist, on update (`PreUpdateEventArgs::setNewValue`) and **last** on render, after the hashtag-link and body-enhancer rewrites (hashtag links are rewritten on the DOM). `strip` outputs escaped plain text |
| `javascript:` links | Article LinkedIn URL: http(s) only (form constraint + render-time check). Image fields (`image`, resource images) are free text printed in `<img src>`: browsers do not run `javascript:` there, but treat them as editor-controlled URLs (no tracking pixels from untrusted editors) |
| Stored XSS through comments | Comment bodies are escaped; `\|nl2br` only. Staff replies are signed with `comments.staff_name` or `BlogStaffDisplayNameInterface` (the login identifier is never published) |
| CSRF on admin delete/approve/save and public comment POST | Symfony forms and `CsrfOnlyFormFactory` (one token id per moderation action) |
| Comment spam | Pending moderation; comments closed when `show_comments` is off; configurable rate-limit strategies (IPv6 counted per /64); configurable CAPTCHA (`honeypot` default). A `service` strategy without its service id fails at compile time |
| Open redirect | The inline CMS update redirects to the article route, never to the `Referer` header |
| Resource exhaustion | Admin lists and the moderation queue are paginated (`web_ui.page_size`); page numbers capped; search terms capped at 200 characters |
| Overly broad demo access | `allow_unauthenticated` defaults to `false` |
| Shared-database table collisions | Optional `doctrine.table_prefix`: primary tables, the `content_blog_article_tag` join table and the repository native SQL |

## Admin access guard

`BlogKitAdminAccessSubscriber` checks the capability before every bundle admin controller: by controller class
(articles / tags → `canManage()`, comments → `canModerate()`, settings → `canConfigure()`), so renamed routes or a
routing `name_prefix` cannot skip it; other routes named `admin_blog*` are still guarded by name (`admin_blog_comment*`
→ moderate, `admin_blog_settings*` → configure, rest → manage). Keep the firewall rules below as defence in depth.

Default configuration:

```yaml
nowo_blog_kit:
    security:
        access_roles: [ROLE_ADMIN]
        roles:
            manage: [ROLE_EDITOR]
            moderate: [ROLE_MODERATOR]
            configure: [ROLE_ADMIN]
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
- CAPTCHA defaults to a hidden honeypot. Remote providers (`recaptcha_v2`, `recaptcha_v3`, `hcaptcha`, `turnstile`) need `site_key` / `secret_key` in YAML. When siteverify returns a `hostname`, it must equal the request host (`Request::getHost()`, so configure trusted proxies / hosts behind a reverse proxy); reCAPTCHA v3 tokens must also carry the `blog_comment` action.
- Public staff replies require `canModerate()`.
- When `show_comments` is off in the blog settings, `POST /blog/{slug}/comments` answers 404.
- The rate limiter is a cache read-modify-write (not atomic): a burst of parallel requests can exceed the limit by a
  few posts. Put a reverse-proxy / `symfony/rate-limiter` limit in front of public POSTs on high-traffic sites.

## Rich text rendering

`public/show.html.twig` renders editor-authored article HTML with `|raw`. The **default** strategy is `allowlist`. Set `html.sanitize.strategy: none` only for fully trusted editors.

The `allowlist` sanitizer removes executable / raw-text elements (`script`, `style`, `template`, `svg`, `math`, `object`, `embed`, `meta`, `link`, `base`, …) with their content, unwraps other unknown elements and sanitizes their children, and only keeps `http(s)` / `mailto:` / same-origin relative URLs in `href` and image `src` (protocol-relative `//host`, `/\host` and whitespace-split variants are rejected). An `iframe` is kept only when its `src` is an absolute `https://` URL on the YouTube / Vimeo allowlist (no other scheme, no userinfo or backslash in the authority).

`html.sanitize.strategy: strip` removes every tag and outputs plain text **escaped as HTML** (encoded markup such as
`&lt;img onerror…&gt;`, also double-encoded, can no longer become live HTML through the `\|raw` print).

Backups / imports that write `content_blog_article_translation.body` directly bypass the persist / update sanitizer;
the render-time pass still runs, but re-save imported articles (or run the sanitizer) after a restore.

## Infinite scroll HTML

`blog-kit.js` inserts HTML fragments returned by `GET /blog?partial=1` using `DOMParser`. Those fragments are the bundle's own Twig cards (auto-escaped). Do not point the infinite-scroll URL at an untrusted origin.

## Operational guidance

- Audit which users receive `ROLE_EDITOR`, `ROLE_MODERATOR`, and `ROLE_ADMIN`.
- Leave `allow_unauthenticated: false` in production.
- Pick a comment rate-limit and CAPTCHA strategy for public internet sites. Keep remote CAPTCHA secrets in YAML.
- Leave `html.sanitize.strategy` at `allowlist` unless article authors are fully trusted.
- Set a real `web_ui.privacy_url`.
- Use `doctrine.table_prefix` when multiple applications share one schema (it now also prefixes the
  `content_blog_article_tag` join table — see [UPGRADING](UPGRADING.md) before enabling it on existing data).
- Never leave a role list empty expecting "everyone": an empty list denies.
- Review demo credentials and never copy demo auth settings into production.

## Timeouts and resource limits

The only blocking outbound call the bundle makes is the remote CAPTCHA verification (`recaptcha_v2`, `recaptcha_v3`, `hcaptcha`, `turnstile`). The default `StreamCaptchaHttpClient` bounds it with `comments.captcha.timeout_seconds` (default `5.0`, range 0.1–60 s); on timeout or transport failure verification fails closed (the comment is rejected) and a warning is logged. Keep PHP `max_execution_time` and your proxy write timeout strictly greater than this value (see [CONFIGURATION.md](CONFIGURATION.md#timeout-hierarchy) and [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md#timeout-hierarchy)). Public listings are paginated and comment posting is rate-limited, so no request performs unbounded work.

## Logging

The bundle logs through the injected PSR-3 `LoggerInterface` (the `logger` service; nothing is logged when no logger is registered). Context arrays always carry `bundle: blog_kit` and an `action` key.

| Event | Level | Context |
| --- | --- | --- |
| CAPTCHA siteverify call completed | `info` | `host` of the provider |
| CAPTCHA siteverify failed, timed out or returned non-JSON | `warning` | `host`, `timeout_seconds` |
| Comment approved / rejected / deleted | `info` | `comment_id` |

**Never logged:** CAPTCHA secret keys and tokens, client IP addresses (comments store only a SHA-256 hash), comment author names, e-mail addresses or bodies, article content, and session identifiers.

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
| **AI security audit (REQ-SEC-004)** | Grade **Pass (good)** / risk **Low** (2026-10-27, re-audit of `main` `2f595df` + fixes in PR #44, shipped in 1.7.0). See [AI security audit](#ai-security-audit). |

Record confirmation in the release PR or tag notes.

## AI security audit

| Field | Value |
| --- | --- |
| Date | 2026-10-27 |
| Scope | `main` `2f595df` (UiKit page contract, PR #43) + hardening PR #44 (`ca82037`), released as 1.7.0 |
| Grade | Pass (good) |
| Risk | Low |
| Method | Claude Code static review of `src/`, Twig views (scaffolds, legacy bridges, inline CMS dialog), TS, Flex recipe, docs; sanitizer PoCs (PHP 8.5 / libxml 2.13, HTML5 re-parse) before and after the fixes |
| Findings (all fixed in 1.7.0) | M1 stored XSS past the default allowlist (post-sanitize hashtag rewrite; 1.1.0–1.6.3); M2 `strip` decoded entities into `\|raw` (1.1.0–1.6.3); M3 empty role list granted everyone; L1 `javascript:` LinkedIn URL; L2/L3 route-name guard (comment actions on `canManage()`, `name_prefix` bypass); L4 update sanitize not persisted; L5 no-op `service` strategies; L6 `table_prefix` not applied to native SQL / join table; L7 unbounded moderation queue; Info I1–I11 |
| Open residuals | Keep `allowlist` unless editors are fully trusted; image URLs are editor-controlled; rate limiter is not atomic (front public POSTs with a proxy / `symfony/rate-limiter` on busy sites); bodies written directly to the database are only sanitized at render; keep `access_control` on `^/admin/blog`; a `table_prefix` that is itself a prefix of `content_blog_article_tag` leaves that join table unprefixed |

See [CONFIGURATION.md](CONFIGURATION.md) and [USAGE.md](USAGE.md).

## Secrets

Never commit application secrets, `.env` files with credentials, or private keys. Use `.env.example` templates only.
