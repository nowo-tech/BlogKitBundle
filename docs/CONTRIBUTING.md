# Contributing Guide

Thank you for contributing to **Blog Kit Bundle**.

## Table of contents

- [Code of Conduct](#code-of-conduct)
- [Reporting bugs and gaps](#reporting-bugs-and-gaps)
- [Submitting changes](#submitting-changes)
- [Development setup](#development-setup)
- [Quality gates](#quality-gates)
- [Project structure](#project-structure)
- [Demo](#demo)
- [Questions](#questions)

## Code of Conduct

Please follow [CODE_OF_CONDUCT.md](../CODE_OF_CONDUCT.md). Report unacceptable behavior to `hectorfranco@nowo.tech`.

## Reporting bugs and gaps

Open a GitHub issue with:

- A clear description of the problem
- Reproduction steps
- Expected vs actual behavior
- PHP, Symfony, and bundle versions
- Relevant `nowo_blog_kit` configuration
- Whether the issue affects public pages, comments, admin CRUD, settings, or hashtag sync

## Submitting changes

1. Fork the repository and create a branch from `main`.
2. Make the smallest coherent change you can.
3. Update docs when integrator-visible behavior changes.
4. Update `specs/001-baseline/` whenever `src/` changes.
5. Open a pull request against `main`.

## Development setup

```bash
git clone https://github.com/your-username/BlogKitBundle.git
cd BlogKitBundle
make up
make setup-hooks
```

Useful commands:

```bash
make test
make phpstan
make validate-translations
make check-no-cursor-coauthor
make release-check
```

If CI reports Cursor co-author trailers in git history, run:

```bash
make check-no-cursor-coauthor
make strip-cursor-coauthor-from-history
```

See [GITHUB_CI.md](GITHUB_CI.md).

## Quality gates

Before opening a PR, aim to pass:

- `make test`
- `make test-coverage-100`
- `make phpstan`
- `make validate-translations`
- `make release-check`

## Project structure

```text
BlogKitBundle/
├── src/                    # Bundle source code
│   ├── Command/
│   ├── Controller/
│   ├── DependencyInjection/
│   ├── Entity/
│   ├── EventSubscriber/
│   ├── Form/
│   ├── Locale/
│   ├── Repository/
│   ├── Resources/
│   ├── Security/
│   ├── Service/
│   └── Twig/
├── tests/                  # Unit and integration tests
├── demo/                   # FrankenPHP demo
├── docs/                   # Integrator and maintainer docs
└── specs/                  # Spec Kit baseline and future feature specs
```

## Demo

Run the Symfony 8 FrankenPHP demo on `http://localhost:8105`:

```bash
make -C demo/symfony8 up
make demo-smoke
```

The demo is useful for checking:

- Public blog index and article pages
- Admin article / tag / comment / settings screens
- Security wiring
- Bundle boot on Symfony 8

## Questions

- Open an issue on GitHub
- Contact the maintainers at `hectorfranco@nowo.tech`
