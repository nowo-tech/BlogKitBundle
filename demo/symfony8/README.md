# Symfony 8 demo — Blog Kit Bundle

Minimal Symfony 8 application running under **FrankenPHP** with SQLite and the path-mounted bundle.

## Quick start

From the **bundle root**:

```bash
make -C demo/symfony8 up
```

Default URL: **http://localhost:8105** (override with `PORT` in `.env`).

## What to try

1. Open `/` — landing page with links to the public blog and admin.
2. Open `/blog` — 32 published lorem ipsum articles with 12 distinct tags (paginated), wrapped in the demo layout (`css_framework: bootstrap5`).
3. Open `/admin/blog` — admin UI (HTTP Basic: `admin` / `admin`).

Demo content is seeded on `make up` (`app:load-demo-blog`). Recreate with:

```bash
make -C demo/symfony8 shell
php bin/console app:load-demo-blog --force
```

## Commands

```bash
make -C demo/symfony8 test
make -C demo/symfony8 down
make -C demo/symfony8 shell
```

## Configuration

- Bundle assets: `php bin/console assets:install --symlink --relative` (runs on `make up`) so `/bundles/nowoblogkit/blog.css` is served.
- Bundle config: `config/packages/nowo_blog_kit.yaml` (locales `es` / `en`).
- Security: in-memory `ROLE_ADMIN` user; `access_control` protects `/admin`.
- Database: SQLite at `var/data/demo.db` (no external DB container).

See [DEMO-FRANKENPHP.md](../../docs/DEMO-FRANKENPHP.md) for FrankenPHP classic vs worker mode.
