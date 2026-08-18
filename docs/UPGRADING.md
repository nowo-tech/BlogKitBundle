# Upgrading

This document describes how to upgrade **Blog Kit Bundle** between released versions.

## Table of contents

- [Unreleased](#unreleased)
- [1.0.0](#100)
- [Future releases](#future-releases)

## Unreleased

## 1.0.0

This is the first public release of `nowo-tech/blog-kit-bundle`. There is no earlier tagged upgrade path.

Install it with:

```bash
composer require nowo-tech/blog-kit-bundle
composer require twig/extra-bundle twig/string-extra
```

Then:

1. Register the bundle and its dependencies if Flex does not do it for you. Require `nowo-tech/ui-kit-bundle` **^1.5**.
2. Import `config/routes/nowo_blog_kit.yaml`.
3. Set `user_class` to your host user entity that implements `BlogUserInterface`.
4. Apply the Doctrine schema changes.
5. Configure Security for `/admin/blog` (`access_control` plus bundle `security.*` roles).
6. Run `php bin/console assets:install` and load files with `asset('…', 'nowo_blog_kit')`.
7. Point `web_ui.layout_template` / `public_layout_template` at the project layouts. Keep `stylesheets` / `javascripts` blocks so `{{ parent() }}` stacking works. Prefer nested `nowo_ui_styles` / `nowo_ui_scripts` for extra assets.
8. Set `web_ui.css_framework` to match the host (`bootstrap5` default), plus `icon_set` and `row_actions_display` if needed.
9. Do not restore raw `<form>` / `<input>` or `window.confirm` in template overrides. Deletes use native `<dialog>` confirms with POST + CSRF.

If you ran an untagged snapshot that defaulted `css_framework` to `tailwind`, set `web_ui.css_framework` explicitly or accept the `bootstrap5` default.

See [INSTALLATION.md](INSTALLATION.md), [CONFIGURATION.md](CONFIGURATION.md), and [USAGE.md](USAGE.md).

## Future releases

Breaking changes and migration notes will be listed here under a new version heading.
