# Templates

Admin templates live in `src/Resources/views/admin/` (Twig namespace `@NowoBlogKitBundle`). Override one by copying it
to `templates/bundles/NowoBlogKitBundle/admin/<same path>`.

## Table of contents

- [Admin forms](#admin-forms)
- [Translation tabs](#translation-tabs)

## Admin forms

| Template | Used by | Notes |
| --- | --- | --- |
| `admin/form.html.twig` | article new / edit | includes `admin/_form.html.twig` |
| `admin/tags/form.html.twig` | tag new / edit | includes `admin/_form.html.twig` (`cancel_path`) |
| `admin/_form.html.twig` | both forms | translation tabs, then the remaining fields; variables `id_prefix` (tabs id, default `locale`), `active_locale`, `cancel_path` |
| `admin/_modal_form.html.twig` | inline CMS modal (`/admin/blog/{id}/edit-modal`, posts to `/admin/blog/{id}/inline`) | translation tabs with tabs id `cms-locale` (`id_prefix`), opened on the request locale (`locale`) |

## Translation tabs

Since 1.6 the translations collection (`translations`, a `CollectionType` of `*TranslationType`) carries the FormKit
`locale_tabs` option (mode `entries`, `default_locale` and `required_locales` = `nowo_blog_kit.default_locale`, entry
`error_bubbling: false`). The admin templates render it with:

```twig
{{ form_errors(form.translations) }}
{{ include('@NowoFormKitBundle/form/_locale_tabs.html.twig', form.translations.vars.locale_tabs|default({})|merge({
  field: form.translations,
  tabs_id: id_prefix|default('locale'),
  active_locale: active_locale|default(null),
}), with_context: false) }}
```

FormKit renders the panels through UiKit's `locale_tabs` partial (`nowo_ui_partial('locale_tabs')`): DOM ids
`#<tabs id>` (root), `#tab-<tabs id>-<locale>` (button), `#tab-<tabs id>-<locale>-panel` (panel); theme slots `tabs`,
`tab`, `tab_active`, `tab_panel`. `{{ form_row(form.translations) }}` works too (FormKit's form theme), with the
field's id as tabs id. The removed `admin/_locale_tabs.html.twig` has no replacement to override: change the look with
the theme slots or `nowo_ui_kit.theme.partials.locale_tabs`.
