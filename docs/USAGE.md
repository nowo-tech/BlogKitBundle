# Usage

How to publish articles, render the public blog, moderate comments, and override templates.

## Table of contents

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
| `admin_blog_index` | `/admin/blog` | `canManage()` |
| `admin_blog_new` / `admin_blog_edit` | `/admin/blog/new`, `/admin/blog/{id}/edit` | `canManage()` |
| `admin_blog_edit_modal` / `admin_blog_inline_update` | inline CMS modal | `canManage()` |
| `admin_blog_delete` | `POST /admin/blog/{id}/delete` | `canManage()` |
| `admin_blog_tags_*` | `/admin/blog/tags` | `canManage()` |
| `admin_blog_comments_*` | `/admin/blog/comments` | `canModerate()` |
| `admin_blog_settings` | `/admin/blog/settings` | `canConfigure()` |

List screens are paginated (`web_ui.page_size`). Deletes open a native `<dialog>` confirm with POST + CSRF in the footer.

## Comments

Public visitors submit `PublicBlogCommentType`. New comments start **pending** and appear on the article only after a moderator approves them at `/admin/blog/comments`.

Staff replies use `StaffBlogCommentReplyType` on the public article (when `canModerate()` is true) or from the admin queue.

## Blog settings

`BlogSettings` is a singleton edited at `/admin/blog/settings`. It controls listing mode (`paginated` / `infinite`), per-page size, masonry columns, card fields, aside placement, related-article limits, comments, share links, and hero image mode.

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

Do **not** reintroduce raw `<form>` / `<input>` tags in host overrides. Delete confirms use a native `<dialog>` (`@NowoUiKitBundle/partials/_confirm.html.twig`) with the CSRF form in the footer. The inline CMS editor is a native `<dialog>` with header / content / actions (`admin/_modal_form.html.twig`).

## Twig overrides

Application templates under `templates/bundles/NowoBlogKitBundle/` override the bundle copy for the same relative path (REQ-TWIG-001).

Common override targets:

- `templates/bundles/NowoBlogKitBundle/public/index.html.twig`
- `templates/bundles/NowoBlogKitBundle/public/show.html.twig`
- `templates/bundles/NowoBlogKitBundle/public/base.html.twig`
- `templates/bundles/NowoBlogKitBundle/public/layout.html.twig`
- `templates/bundles/NowoBlogKitBundle/admin/base.html.twig`
- `templates/bundles/NowoBlogKitBundle/admin/layout.html.twig`
- `templates/bundles/NowoBlogKitBundle/admin/index.html.twig`
- `templates/bundles/NowoBlogKitBundle/admin/_delete_confirm.html.twig`
- `templates/bundles/NowoBlogKitBundle/admin/_modal_form.html.twig`

This precedence is installed by `TwigPathsPass` before the bundle view path is added.

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

Admin and public pages extend `@NowoBlogKitBundle/admin/base.html.twig` and `@NowoBlogKitBundle/public/base.html.twig`. Those bases call `{{ parent() }}` then load bundle CSS/JS in nested `nowo_ui_styles` / `nowo_ui_scripts` (REQ-UI-001). Point `layout_template` / `public_layout_template` at the host layout and set `css_framework` to `bootstrap5`, `tailwind`, `foundation`, or `custom`. Set `icon_set` and `row_actions_display` without copying list templates. Do not copy every page template.

`nowo_blog_kit_container_class()` adds the host container class (`container` / `grid-container`) next to the semantic `blog-container` wrapper.

`blog-kit.js` boots:

- `[data-controller="blog-infinite"]` (public masonry infinite scroll)
- `[data-controller="form-collection"]` (article resource CollectionType)

No host Stimulus application is required. TypeScript sources live in `src/Resources/assets/src/` and are built with Vite (`pnpm run build`).

Admin locale tabs use UiKit `nowo-ui-tabs` (`asset('js/nowo-ui-tabs.js', 'nowo_ui_kit')`).

## Rich text notes

`public/show.html.twig` renders `article.body` with `|raw` so editor-authored HTML can display. Treat that HTML as trusted CMS content, or sanitize before persist/render if untrusted authors can reach article forms.

Comment bodies use auto-escaping plus `|nl2br`.
