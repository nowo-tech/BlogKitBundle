# Templates

Pages of BlogKitBundle on the UiKit page contract (REQ-UI-003, UiKit 2.15). `Nowo\BlogKitBundle\Ui\BlogKitPageFactory`
builds the UiKit view models (`Nowo\UiKitBundle\View\*`) for the controllers; `Nowo\BlogKitBundle\Ui\BlogKitPages`
resolves the layouts (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`; `web_ui.public_layout_template`,
null = `nowo_ui_kit.layouts.guest`) and the per-page overrides (`web_ui.templates.<page>`). Admin templates extend a
UiKit scaffold and fill data blocks only; the public pages are unique pages on the guest layout. Contract test:
`tests/Integration/Contract/PageContractTest.php` (UiKit `PageContractTestCase`). Twig namespace:
`@NowoBlogKitBundle` (views in `src/Resources/views/`).

## Table of contents

- [Pages](#pages)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Bundle partials](#bundle-partials)
- [Translation tabs](#translation-tabs)
- [Navigation](#navigation)
- [Layout bridges (1.x layouts)](#layout-bridges-1x-layouts)
- [Allowed exceptions](#allowed-exceptions)

## Pages

Every view model passes `bundle: 'NowoBlogKitBundle'` (theme exception `nowo_ui_kit.theme.bundles.NowoBlogKitBundle`)
and `layout: BlogKitPages::layout()`. Labels are `NowoBlogKitBundle` translation keys (`BlogKitPages::label()`). The
list and settings headers carry the blog area nav (Articles | Tags | Comments | Settings) as `PageHeader::tabs`.

| Page (`web_ui.templates` id) | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Articles (`article_index`) | `admin/index.html.twig` | `page/list` | `ListPage` (header `admin.articles.title`, action New article; columns position, slug, title, status, updated at / by (priority 2 / 3); row actions Edit and Delete — POST + CSRF-only `FormView` behind a `Confirm`; filters `BlogArticleFilterType`; `Pagination` (`web_ui.page_size`); `EmptyState` `admin.articles.empty` with New article; `data-testid="blog-kit-articles"`) | `nowo_ui_list_row` (cells; status on the `badge_success` / `badge_neutral` slots, slug on the `code` slot) |
| Article new / edit (`article_form`) | `admin/form.html.twig` | `page/form` | `FormPage` (header `admin.articles.new` / `admin.articles.edit`, back link to the articles; `BlogArticleType`; submit `admin.action.save`, cancel = articles; `data-testid="blog-kit-article-form"`) | `nowo_ui_form_body` (`admin/_form_body.html.twig`: locale tabs, then the REQ-TWIG-003 child loop), `nowo_ui_scripts` (`blog-kit.js` for the resources collection) |
| Tags (`tag_index`) | `admin/tags/index.html.twig` | `page/list` | `ListPage` (header `admin.tags.title` / `admin.tags.lead`, action New tag; columns slug, name, articles; row actions Edit / Delete; filters `BlogTagFilterType`; `EmptyState` `admin.tags.empty` with New tag; `data-testid="blog-kit-tags"`); `article_counts` | `nowo_ui_list_row` |
| Tag new / edit (`tag_form`) | `admin/tags/form.html.twig` | `page/form` | `FormPage` (header `admin.tags.new` / `admin.tags.edit`, back link to the tags; `BlogTagType`; `data-testid="blog-kit-tag-form"`) | `nowo_ui_form_body` (`admin/_form_body.html.twig`) |
| Comments (`comment_index`) | `admin/comments/index.html.twig` | `page/list` | `ListPage` (header `admin.comments.title`, intro = pending count, status filter actions pending / approved / rejected / all (current one primary, `aria-current`); columns date, article, author, comment, status, reply; row actions Approve / Reject (pending only, POST + CSRF `FormView`) and Delete (behind a `Confirm`); filters `BlogCommentFilterType`; `EmptyState` `admin.comments.empty`; `data-testid="blog-kit-comments"`); `reply_forms` | `nowo_ui_list_row` (the reply cell holds the `StaffBlogCommentReplyType` form) |
| Settings (`settings`) | `admin/settings.html.twig` | `page/settings` | `SettingsPage` (header `admin.settings.title` / `admin.settings.lead`; one `SettingsSection` per section — listing, cards, index-aside, article, comments — with its href (section tabs) and, for the current one, the `BlogSettingsType` form of that section, its lead as intro and submit `admin.action.save`; `data-testid="blog-kit-settings"`) | `nowo_ui_settings_section` (the article section groups its fields under two sub-headings; child loop + submit row) |

1.x variables are still passed for host templates set through `web_ui.templates.<page>`: `page_title`, `items`,
`pagination`, `filters`, `filter_form`, `delete_forms` (articles, tags), `article_counts` (tags), `filter`,
`pending_count`, `reply_forms`, `action_forms` (comments), `form`, `article` / `tag` (forms), `edit_section`
(settings).

## Unique pages

The public blog pages are the host's front pages, not admin screens: they extend `web_ui.public_layout_template`
(`nowo_blog_kit_page_layout`), else the UiKit guest layout (`nowo_ui_layout.guest`), set `nowo_ui_bundle` and fill
`nowo_ui_document_title` (`page_title`), `nowo_ui_head` (meta description), `nowo_ui_styles` / `nowo_ui_scripts`
(bundle assets after `parent()`), `nowo_ui_page_header` (`nowo_ui_page_header(header)`, breadcrumbs mode `own`) and
`nowo_ui_content`.

- `public/index.html.twig` — blog index (`/blog`, id `public_index`): search, tag filter, masonry / grid / list cards,
  optional asides, UiKit `pagination` partial (`pager`, a `View\Pagination`) or infinite scroll, UiKit `empty`
  partial; `data-testid="blog-kit-index"`.
- `public/show.html.twig` — article (`/blog/{slug}`, id `public_show`): meta, tags, hero image, body, share links,
  source link, comments (`public/_comments.html.twig`), asides; `data-testid="blog-kit-article"`.

## Per-page overrides

- `nowo_blog_kit.web_ui.templates.<page>` (ids in the [Pages](#pages) table and `public_index` / `public_show`): a host
  template that extends `@!NowoBlogKitBundle/<template>` and overrides a data block (`nowo_ui_list_row`,
  `nowo_ui_form_body`, `nowo_ui_settings_section`, `nowo_ui_content`, …). Prefer theming the UiKit slots
  (`nowo_ui_kit.theme`) and scaffolds once for every bundle.
- Record such overrides in the application's override inventory.

## Overriding templates (REQ-TWIG-001)

Any bundle template can be replaced by a file at `templates/bundles/NowoBlogKitBundle/<subpath>` (same relative path
as under `src/Resources/views/`). `TwigPathsPass` registers the application directory first (`prependPath`) and the
bundle views after it (`addPath`), so **an application file at the override path always wins**.

**Freeze rule.** An application file at the override path always wins and will not pick up vendor changes for that
path until you remove or merge it: after `composer update` the application keeps rendering its own copy.
Upgrade-safe customisation, in this order:

| Depth | How | On a BlogKit upgrade |
| --- | --- | --- |
| Preferred — no Twig fork | `nowo_ui_kit.layouts.{admin,guest}` (or `web_ui.layout_template` / `public_layout_template`), `nowo_ui_kit.theme` (class / attribute maps, tokens, `theme.bundles.NowoBlogKitBundle`), translations, CSS on `nowo-ui-*` / `blog-*` / `--nowo-ui-*` | Vendor templates and fixes apply automatically |
| Surgical | `web_ui.templates.<page>` with a thin `{% extends '@!NowoBlogKitBundle/...' %}` overriding one data block (with `{{ parent() }}` where possible), or a single partial (`public/_items.html.twig`, `admin/_form_body.html.twig`, …) | Only that block / partial is frozen |
| Full-file override | Copy a page into `templates/bundles/NowoBlogKitBundle/<subpath>` | That `<subpath>` is frozen until removed or merged |

**Procedure.**

1. Find the `<subpath>` in the table below (path relative to `src/Resources/views/`).
2. Create `templates/bundles/NowoBlogKitBundle/<subpath>` in the application (same file name).
3. Clear the cache in dev if needed: `php bin/console cache:clear`.
4. Treat the file as a fork: it does not change when the bundle ships a new version of that `<subpath>`.

**Recovering upstream after a freeze.** Delete (or rename aside) the application file, clear the Twig cache
(`php bin/console cache:clear`), then re-apply only surgical customisations if still needed. Diff your copy against
the new vendor file under `vendor/nowo-tech/blog-kit-bundle/src/Resources/views/<subpath>` before re-copying anything.

**Overridable subpaths.**

| `<subpath>` | Purpose |
| --- | --- |
| `admin/index.html.twig` | Articles list (list scaffold) |
| `admin/form.html.twig` | Article create / edit (form scaffold) |
| `admin/tags/index.html.twig` | Tags list |
| `admin/tags/form.html.twig` | Tag create / edit |
| `admin/comments/index.html.twig` | Comment moderation queue |
| `admin/settings.html.twig` | Blog settings (settings scaffold) |
| `admin/_form_body.html.twig` | Locale tabs + child loop of the article / tag forms and the inline CMS dialog |
| `admin/_audit_cells.html.twig` | Updated at / by cells of the articles list |
| `admin/_modal_form.html.twig` | Inline CMS editor fragment (UiKit `dialog` partial) |
| `admin/form_theme.html.twig` | Form theme block of the article resources collection (register it in `twig.form_themes`) |
| `admin/icons/blog.svg.twig` | Blog icon (host menus) |
| `admin/_legacy_layout.html.twig`, `public/_legacy_layout.html.twig` | Deprecated 1.x layout bridges |
| `public/index.html.twig` | Public blog index |
| `public/show.html.twig` | Public article |
| `public/_items.html.twig` | Article cards (also the infinite-scroll fragment) |
| `public/_search.html.twig` | Search form |
| `public/_aside_index.html.twig`, `public/_aside_show.html.twig` | Index / article asides |
| `public/_comments.html.twig` | Approved comments, staff replies and the comment form |
| `public/_meta.html.twig` | Meta description |
| `_stylesheets.html.twig`, `_scripts.html.twig` | Bundle CSS / JS (asset package `nowo_blog_kit`) |

Renaming or removing a `<subpath>` or a data block listed here is a breaking change (see [UPGRADING](UPGRADING.md)).

## Bundle partials

| Partial | Role |
| --- | --- |
| `_stylesheets.html.twig` | `blog.css` (no CSS framework; the host layout loads it) |
| `_scripts.html.twig` | `blog-kit.js` (infinite scroll, resources collection) |
| `admin/_form_body.html.twig` | Form body (locale tabs + REQ-TWIG-003 child loop); variables `form`, `tabs_id` (default `locale`), `active_locale`, `testid` |
| `admin/_audit_cells.html.twig` | Audit cells (`item`, `bundle`) |
| `admin/_modal_form.html.twig` | Inline CMS dialog: UiKit `dialog` partial (`#blog-kit-cms-modal`, open), tabs id `cms-locale`, opened on `locale` |
| `public/_meta.html.twig` | Meta description (skipped under the 1.x bridge) |

The bundle ships no local pagination, flash, confirm, modal shell, page header, filter or empty-state partial: the
scaffolds and the UiKit partials (`action`, `confirm`, `dialog`, `empty`, `flashes`, `pagination`, `tabs`) render
them.

## Translation tabs

The translations collection (`translations`, a `CollectionType` of `*TranslationType`) carries the FormKit
`locale_tabs` option (mode `entries`, `default_locale` and `required_locales` = `nowo_blog_kit.default_locale`, entry
`error_bubbling: false`). `admin/_form_body.html.twig` renders it with:

```twig
{{ form_errors(form.translations) }}
{{ include('@NowoFormKitBundle/form/_locale_tabs.html.twig', form.translations.vars.locale_tabs|default({})|merge({
  field: form.translations,
  tabs_id: tabs_id|default('locale'),
  active_locale: active_locale|default(null),
}), with_context: false) }}
```

FormKit renders the panels through UiKit's `locale_tabs` partial (`nowo_ui_partial('locale_tabs')`): DOM ids
`#<tabs id>` (root), `#tab-<tabs id>-<locale>` (button), `#tab-<tabs id>-<locale>-panel` (panel); theme slots `tabs`,
`tab`, `tab_active`, `tab_panel`. Change the look with the theme slots or `nowo_ui_kit.theme.partials.locale_tabs`.

## Navigation

`Nowo\BlogKitBundle\Ui\BlogKitAreaNav` (an `AreaNavProviderInterface`) publishes the blog navigation to the UiKit
area-nav registry: `nowo_ui_area_nav('blog_kit')` (Articles | Tags | Comments | Settings — Comments and Settings only
when the user may moderate / configure) and `nowo_ui_area_nav('blog_kit_settings')` (the settings sections). The admin
pages render the same navigation as page-header tabs (fallback for hosts without DashboardMenu).

## Layout bridges (1.x layouts)

`web_ui.layout_template` / `public_layout_template` may still point at a 1.x layout (no `nowo_ui_page_header`
block): `BlogKitPages` then wraps it in `admin/_legacy_layout.html.twig` (maps the canonical blocks into `admin`,
`title`, `stylesheets`, `javascripts`) or `public/_legacy_layout.html.twig` (`body`, `title`, `meta_description`,
`stylesheets`, `javascripts`) and triggers a deprecation. Canonical blocks the 1.x layout renders itself
(`nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`, …) are not printed twice. The bridges are removed in 2.0.

## Allowed exceptions

- (none) — the contract test declares no `allowedClasses()`.
