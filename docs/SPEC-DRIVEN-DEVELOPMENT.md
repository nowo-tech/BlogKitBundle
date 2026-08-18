# Spec-driven development

## Table of contents

- [Three layers](#three-layers)
- [User stories](#user-stories)
- [Functional scope](#functional-scope)
- [Validating the spec](#validating-the-spec)
- [Requirement identifiers (`REQ-*`)](#requirement-identifiers-req-)
- [Suggested workflow for contributors](#suggested-workflow-for-contributors)
- [GitHub Spec Kit (summary)](#github-spec-kit-summary)
- [See also](#see-also)

## Three layers

In this repository, spec-driven development has three layers that stay in sync:

1. **GitHub Spec Kit baseline**: `specs/001-baseline/` documents the bundle behavior and maps 100% of production files under `src/`.
2. **Product behavior**: articles, tags, comments, settings, public rendering, and admin CRUD are documented in the integrator docs.
3. **Traceability anchors**: Stable `REQ-*` identifiers link docs, demo expectations, and QA workflows.

## User stories

| ID | Story |
| --- | --- |
| US-01 | As a visitor, I browse published articles at `/blog` and open `/blog/{slug}` |
| US-02 | As a visitor, I search and filter by tag, including infinite-scroll listing when enabled |
| US-03 | As a visitor, I submit a comment that waits for moderation |
| US-04 | As an editor, I create, edit, publish, and delete multilingual articles and tags (optional `owner` object access) |
| US-05 | As a moderator, I approve, reject, reply to, and delete comments |
| US-06 | As an administrator, I configure listing mode, masonry layout, asides, comment protection, HTML sanitizer, and public display options |
| US-07 | As an integrator, I adapt security, layouts, locales, and `user_class` from configuration |
| US-08 | As a maintainer, I run the Symfony 8 FrankenPHP demo and QA checks |

## Functional scope

**In scope:** multilingual articles and tags, moderated comments (rate-limit and CAPTCHA strategies), optional article HTML sanitizer, singleton settings, public Twig rendering, admin CRUD, object-level publication access (`none` / `owner` / host `service`), hashtag sync command, published event, custom access checking, Twig namespace and overrides, Doctrine persistence, Symfony Flex recipe, FrankenPHP demo.

**Non-goals:** full CMS page builder, or owning the host authentication system.

## Validating the spec

```bash
make test
make phpstan
make validate-translations
make demo-smoke
make release-check
```

## Requirement identifiers (`REQ-*`)

| ID | Where | What it marks |
| --- | --- | --- |
| REQ-DOCS-002 | `README.md` | Canonical documentation link order |
| REQ-UI-002 | `docs/SECURITY.md`, security config | Admin routes require access unless explicitly relaxed |
| REQ-TWIG-005 | `.scripts/check-no-raw-html-form.sh` | Kit Twig has no raw `<form>` / `<input>` |
| REQ-TWIG-001 | `TwigPathsPass` | Host Twig overrides win over bundle templates |
| REQ-TWIG-004 | `docs/INSTALLATION.md`, demo bundles | Twig Extra requirement is documented |
| REQ-I18N-001 | `docs/USAGE.md`, `docs/CONFIGURATION.md` | Translation override path |
| REQ-TEST-011 | `Makefile` `demo-smoke` | Demo boots and returns HTTP 200 |
| REQ-MAKE-002 | `Makefile` `release-check` | Pre-release QA chain |
| REQ-MAKE-004 | `Makefile` `validate-translations` | Translation parity validation hook |
| REQ-GIT-001 | `docs/GITHUB_CI.md` | No Cursor co-author trailers in git history |
| REQ-RECIPE-001 | `docs/INSTALLATION.md` | Flex recipe path `.symfony/recipe` |

## Suggested workflow for contributors

1. Clarify the behavior change or bug.
2. Update or create the relevant spec artifact.
3. Implement the change with tests if production behavior changes.
4. Update integrator docs when host applications must act.
5. Keep `specs/001-baseline/spec.md` and `code-inventory.md` aligned with `src/`.

## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with Cursor Agent integration.

| Artifact | Path |
| --- | --- |
| Baseline spec | `specs/001-baseline/spec.md` |
| Code inventory | `specs/001-baseline/code-inventory.md` |
| Tooling manual | `docs/SPEC-KIT.md` |
| Constitution | `.specify/memory/constitution.md` |

**How to install, initialize, and use Spec Kit:** [SPEC-KIT.md](SPEC-KIT.md).

## See also

- [USAGE.md](USAGE.md)
- [CONFIGURATION.md](CONFIGURATION.md)
- [INSTALLATION.md](INSTALLATION.md)
- [CONTRIBUTING.md](CONTRIBUTING.md)
