# Upgrading

This document describes how to upgrade **Blog Kit Bundle** between released versions.

## Table of contents

- [1.0.0](#100)
- [Future releases](#future-releases)

## 1.0.0

This is the first public release of `nowo-tech/blog-kit-bundle`, so there is no earlier upgrade path.

Install it with:

```bash
composer require nowo-tech/blog-kit-bundle
composer require twig/extra-bundle twig/string-extra
```

Then:

1. Register the bundle and its dependencies if Flex does not do it for you.
2. Import `config/routes/nowo_blog_kit.yaml`.
3. Set `user_class` to your host user entity that implements `BlogUserInterface`.
4. Apply the Doctrine schema changes.
5. Configure Security for `/admin/blog`.
6. Override Twig templates as needed for your layout and design system.

See [INSTALLATION.md](INSTALLATION.md), [CONFIGURATION.md](CONFIGURATION.md), and [USAGE.md](USAGE.md).

## Future releases

Breaking changes and migration notes will be listed here under a new version heading.
