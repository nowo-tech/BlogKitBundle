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

List screens are paginated (`web_ui.page_size`) and use CSRF-protected delete actions.

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

## Twig overrides

Application templates under `templates/bundles/NowoBlogKitBundle/` override the bundle copy for the same relative path (REQ-TWIG-001).

Common override targets:

- `templates/bundles/NowoBlogKitBundle/public/index.html.twig`
- `templates/bundles/NowoBlogKitBundle/public/show.html.twig`
- `templates/bundles/NowoBlogKitBundle/public/layout.html.twig`
- `templates/bundles/NowoBlogKitBundle/admin/layout.html.twig`
- `templates/bundles/NowoBlogKitBundle/admin/index.html.twig`

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

Published files (after `assets:install`):

- `asset('blog.css', 'nowo_blog_kit')`
- `asset('blog-infinite-controller.js', 'nowo_blog_kit')`

The default public layout loads both. The JS boots `[data-controller="blog-infinite"]` without requiring a host Stimulus application.

Stimulus source for Symfony UX hosts: `src/Resources/assets/src/blog-infinite-controller.ts`. If you register that controller in the host Stimulus app, override `public/layout.html.twig` and drop the bundled script tag so infinite scroll does not bind twice.

## Rich text notes

`public/show.html.twig` renders `article.body` with `|raw` so editor-authored HTML can display. Treat that HTML as trusted CMS content, or sanitize before persist/render if untrusted authors can reach article forms.

Comment bodies use auto-escaping plus `|nl2br`.
