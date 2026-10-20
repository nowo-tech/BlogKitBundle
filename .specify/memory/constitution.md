# BlogKitBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behavior lives in `specs/001-baseline/spec.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md`, and integrator docs (`USAGE.md`, `CONFIGURATION.md`). Demos are illustrative unless promoted in the spec.

### II. Spec-first, test-proven
PHPUnit, PHPStan and Vitest are the mechanical proof. Behavioral changes require tests; `src/` line coverage stays at 100%.

### III. 100% code inventory traceability
Every file tracked under `src/` must appear in `specs/001-baseline/code-inventory.md`. New files require spec and inventory updates in the same PR.

### IV. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`). Skills live in `.cursor/skills/speckit-*`.

### V. Symfony compatibility and worker safety
Follow the PHP/Symfony ranges declared in `composer.json` and the README badges. Bundle-owned state must stay safe under FrankenPHP worker mode (see `docs/FRANKENPHP-WORKER-AUDIT.md`).

### VI. Host-owned UI and access
Admin and public UIs render through Twig/FormKit (no raw HTML forms) and leave authentication, layout and access policy to the host application.

### VII. English documentation
All files under `docs/` and `specs/` are written in English.

### VIII. Git commit hygiene (REQ-GIT-001)
No Cursor or Claude `Co-authored-by` trailers in published history. Hooks, scripts, `release-check`, and the CI `git-hygiene` job enforce this. See [`docs/GITHUB_CI.md`](../../docs/GITHUB_CI.md).

## Governance
Amendments update this file, the baseline spec when principles affect behavior, and `docs/CHANGELOG.md` when consumer-visible.

**Version**: 1.0.0 | **Ratified**: 2026-10-20 | **Last Amended**: 2026-10-20
