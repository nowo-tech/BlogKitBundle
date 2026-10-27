# Usage

How to publish articles, render the public blog, moderate comments, and override templates.

## Screenshots

Demo gallery (masthead + blog UI) in the [README](../README.md). Regenerate with:

```bash
make -C demo/symfony8 demo-screenshots
```

(REQ-DEMO-013 — Playwright under `demo/symfony8/e2e/`.)

## Table of contents

- [Screenshots](#screenshots)
- [Public pages](#public-pages)
- [Admin screens](#admin-screens)
- [Comments](#comments)
- [Blog settings](#blog-settings)
- [Hashtag sync command](#hashtag-sync-command)
- [Published event](#published-event)
- [Custom access logic](#custom-access-logic)
- [Host meta providers](#host-meta-providers)
- [Forms (FormKit + child loop)](#forms-formkit--child-loop)
- [Twig overrides](#twig-overrides)
- [Translation overrides](#translation-overrides)
- [Infinite scroll assets](#infinite-scroll-assets)
- [Rich text notes](#rich-text-notes)

## Public pages

| Route name | Path | Purpose |
| --- | --- | --- |
| `blog_index` | `/blog` | Paginated or infinite list, search, tag filter |
| `blog_show` | `/blog/{slug}` | Published article detail |
| `blog_comment_create` | `POST /blog/{slug}/comments` | Public comment submit |
| `blog_comment_staff_reply` | `POST /blog/comments/{id}/reply` | Staff reply (moderators) |

Only **published** articles with a non-empty body are visible on `blog_show`.

`GET /blog?partial=1` returns the card partial used by infinite scroll.

## Admin screens

| Route name | Path | Checker |
| --- | --- | --- |
| `admin_blog_index` | `/admin/blog` | `canManage()` + object listing filter |
| `admin_blog_new` / `admin_blog_edit` | `/admin/blog/new`, `/admin/blog/{id}/edit` | `canManage()` + `BlogKitAccessDenied` on the article |
| `admin_blog_edit_modal` / `admin_blog_inline_update` | inline CMS modal | `canManage()` + `BlogKitAccessDenied` on the article |
| `admin_blog_delete` | `POST /admin/blog/{id}/delete` | `canManage()` + `BlogKitAccessDenied` on the article |
| `admin_blog_tags_*` | `/admin/blog/tags` | `canManage()` + `BlogKitAccessDenied` on mutations |
| `admin_blog_comments_*` | `/admin/blog/comments` | `canModerate()` + `BlogKitAccessDenied` on mutations |
| `admin_blog_settings` | `/admin/blog/settings` | `canConfigure()` |

The admin screens are UiKit scaffolds (list, form, settings) with the blog area nav (Articles | Tags | Comments |
Settings, also published as `nowo_ui_area_nav('blog_kit')` for DashboardMenu). The articles list is paginated
(`web_ui.page_size`). Deletes open the UiKit confirm `<dialog>` with POST + CSRF.

## Comments

Public visitors submit `PublicBlogCommentType`. New comments start **pending** and appear on the article only after a moderator approves them at `/admin/blog/comments`.

Comment spam controls are first-class:

- **Rate limit** — YAML `comments.rate_limit` (`fixed_window` per IP by default, 5 / 60s). Other strategies: `per_ip_article`, `sliding_window`, `none`, or a host `service`.
- **CAPTCHA** — YAML `comments.captcha` (`honeypot` by default). Also `recaptcha_v2`, `recaptcha_v3`, `hcaptcha`, `turnstile`, `none`, or a host `service`.
- Operators can switch strategies (not secrets) at `/admin/blog/settings`.

Staff replies use `StaffBlogCommentReplyType` on the public article (when `canModerate()` is true) or from the admin queue. The public reply POST also requires `canModerate()`.

## Blog settings

`BlogSettings` is a singleton edited at `/admin/blog/settings`. It controls listing mode (`inherit` / `paginated` / `infinite`), per-page size, card layout (`inherit` / `masonry` / `grid` / `list`), masonry columns (`0` = YAML), card fields, aside placement, related-article limits, comments, share links, hero image mode, comment rate-limit / CAPTCHA strategies, and HTML sanitizer strategy.

YAML `listing.mode` (`paginated` or `infinite`) is the default when admin listing mode is `inherit`. YAML `listing.masonry` is the default when admin layout is `inherit` and column fields are `0`. `per_page` is the page size and the infinite-scroll batch.

## Hashtag sync command

Trailing LinkedIn-style hashtags in article bodies can be formatted, turned into tags, and linked:

```bash
php bin/console nowo:blog:sync-hashtags
php bin/console nowo:blog:sync-hashtags --dry-run
```

## Published event

When an article transitions to published, `BlogArticlePublishedDoctrineSubscriber` dispatches `BlogArticlePublishedEvent`. Listen to it in the host application (for example to send Web Push via PwaBundle).

## Custom access logic

Role-based access is the default. For project-specific rules, implement `BlogKitAccessCheckerInterface`:

```php
namespace App\Security;

use Nowo\BlogKitBundle\Security\BlogKitAccessCheckerInterface;

final class BlogEditorAccessChecker implements BlogKitAccessCheckerInterface
{
    public function canManage(): bool
    {
        return true;
    }

    public function canModerate(): bool
    {
        return true;
    }

    public function canConfigure(): bool
    {
        return true;
    }
}
```

Register it:

```yaml
nowo_blog_kit:
    security:
        access_checker: App\Security\BlogEditorAccessChecker
```

Roles decide who can open the admin UI. To scope **publications** (and optionally tags/comments) further, set `security.object_access`:

```yaml
nowo_blog_kit:
    security:
        object_access:
            strategy: owner   # none | owner | service
```

`owner` lets editors change articles they created (`createdBy` / AuditKit). Users with `canConfigure()` still see every row. Unowned legacy articles stay editable by any manager. Implement `BlogKitResourceAccessCheckerInterface` and set `strategy: service` for teams or workflow rules. Controllers throw via `BlogKitAccessDenied`. This is **not** a Symfony Security voter or ACL.

The extension prepends FormKit `type_map.entity` so `BlogArticleType` tags resolve. Hosts can still override `nowo_form_kit.type_map`.

## Host meta providers

Optional services:

- `BlogIndexMetaProviderInterface` — title/description for the public index
- `BlogBrandNameProviderInterface` — brand suffix for article `<title>`

## Forms (FormKit + child loop)

Admin CRUD, public search, comment POST, and CSRF-only actions (delete / approve / reject) are **Symfony Form Types** (FormKit `FormKitAbstractType` / `CsrfOnlyFormFactory`). Twig renders them with `form_start`, the canonical child loop, and `form_end` (REQ-TWIG-003 / REQ-TWIG-005):

```twig
{{ form_start(form) }}
    {% for child in form %}
        {% if not child.rendered %}
            {{ form_row(child) }}
        {% endif %}
    {% endfor %}
{{ form_end(form) }}
```

Do **not** reintroduce raw `<form>` / `<input>` tags in host overrides. Delete confirms are the UiKit confirm contract (`View\Action` + `View\Confirm`: native `<dialog>`, CSRF form in the footer). The inline CMS editor is the UiKit `dialog` partial (`admin/_modal_form.html.twig`).

## Twig overrides

The bundle pages are on the UiKit page contract (REQ-UI-003): theme them once in `nowo_ui_kit` (layouts, `theme`
slots and tokens) and, for a bundle-specific need, use `web_ui.templates.<page>` with a thin
`{% extends '@!NowoBlogKitBundle/...' %}` overriding one data block. [TEMPLATES](TEMPLATES.md) lists every page,
scaffold, data block and overridable `<subpath>`.

Full-file overrides (REQ-TWIG-001) remain available: an application file at
`templates/bundles/NowoBlogKitBundle/<subpath>` (same relative path as under `src/Resources/views/`) **always wins**,
because `TwigPathsPass` registers the application directory before the bundle views.

**Freeze rule:** an application file at the override path always wins and will not pick up vendor changes for that
path until you remove or merge it. Prefer, in this order: `nowo_ui_kit.layouts` / `theme` and translations (no Twig
fork, upgrades apply automatically); `web_ui.templates.<page>` or a single partial (only that block / partial is
frozen); a full-file copy (that `<subpath>` is frozen) only for deep redesigns.

**Procedure:** pick the `<subpath>` in [TEMPLATES — Overriding templates](TEMPLATES.md#overriding-templates-req-twig-001),
create `templates/bundles/NowoBlogKitBundle/<subpath>`, run `php bin/console cache:clear` in dev, and treat the file
as a fork.

**Recovering upstream after a freeze:** delete (or rename aside) the application file, clear the Twig cache, then
re-apply only surgical customisations. Diff against `vendor/nowo-tech/blog-kit-bundle/src/Resources/views/<subpath>`
before re-copying.

| `<subpath>` | Purpose |
| --- | --- |
| `admin/index.html.twig`, `admin/tags/index.html.twig`, `admin/comments/index.html.twig` | Articles / tags / comments lists (list scaffold) |
| `admin/form.html.twig`, `admin/tags/form.html.twig` | Article / tag forms (form scaffold) |
| `admin/settings.html.twig` | Blog settings (settings scaffold) |
| `admin/_form_body.html.twig` | Locale tabs + child loop of the forms and the inline CMS dialog |
| `admin/_modal_form.html.twig` | Inline CMS editor fragment |
| `public/index.html.twig`, `public/show.html.twig` | Public index / article (unique pages, guest layout) |
| `public/_items.html.twig`, `public/_search.html.twig`, `public/_comments.html.twig`, `public/_aside_*.html.twig` | Public partials |

Twig namespace: `@NowoBlogKitBundle/...` (REQ-TWIG-002).

## Translation overrides

UI strings use domain `NowoBlogKitBundle`. Override them in the host app:

```text
translations/NowoBlogKitBundle.en.yaml
translations/NowoBlogKitBundle.es.yaml
```

See [CONFIGURATION.md](CONFIGURATION.md).

## Infinite scroll assets

Published files (after `assets:install` / `make assets`):

- `asset('blog.css', 'nowo_blog_kit')`
- `asset('blog-kit.js', 'nowo_blog_kit')`

Pages stack them in `nowo_ui_styles` / `nowo_ui_scripts` after `{{ parent() }}` (`_stylesheets.html.twig`,
`_scripts.html.twig`); the CSS framework, icon set and row-action display come from `nowo_ui_kit`. The layouts are
`nowo_ui_kit.layouts.admin` / `guest` (or `web_ui.layout_template` / `public_layout_template`). Do not copy page
templates to add CSS / JS.

`nowo_blog_kit_container_class()` is deprecated (1.7): the bundle pages use the semantic `blog-container` only.

`blog-kit.js` boots:

- `[data-controller="blog-infinite"]` (public masonry infinite scroll)
- `[data-controller="form-collection"]` (article resource CollectionType)

No host Stimulus application is required. TypeScript sources live in `src/Resources/assets/src/` and are built with Vite (`pnpm run build`).

Admin translation tabs (articles, tags, the inline CMS modal) are FormKit `locale_tabs` (mode `entries`) rendered
through UiKit's `locale_tabs` partial: one tab per `nowo_blog_kit.locales` entry, the default locale first and marked
required, invalid tabs marked and opened, keyboard navigation and the `nowo-ui:tabs:change` event from
`nowo-ui-tabs.js` (UiKit `scripts` partial, printed by the layout in `nowo_ui_scripts`). See [TEMPLATES](TEMPLATES.md).

## Rich text notes

`public/show.html.twig` renders `article.body` with `|raw`. Set `html.sanitize.strategy` to `allowlist` or `strip` (or a host sanitizer) so untrusted HTML is cleaned on save and on public render. `none` keeps trusted-editor HTML unchanged.

Comment bodies use auto-escaping plus `|nl2br`.
