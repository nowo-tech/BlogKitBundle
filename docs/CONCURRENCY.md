# Concurrency

How Blog Kit stays correct when the same data is written at the same time (nowo-tech REQ-RUNTIME-003): the write
paths, the conflict answer and its codes, the host options and the residual risks. Since **1.10.0**.

## Table of contents

- [Write paths](#write-paths)
- [The runner](#the-runner)
- [Conflict codes](#conflict-codes)
- [What is not a conflict](#what-is-not-a-conflict)
- [Host options](#host-options)
- [Workers and entity managers](#workers-and-entity-managers)
- [Tests and CI](#tests-and-ci)
- [Singleton settings row](#singleton-settings-row)
- [Double-submitted comments](#double-submitted-comments)
- [Residual risks](#residual-risks)

## Write paths

The public pages (index, article, search, tags, feeds) only read. The only exception is the settings row: the first
read of an empty table inserts it with one DBAL `INSERT`, which the unique `singleton_key` keeps to one row (see
[Singleton settings row](#singleton-settings-row)).

| Path | Transaction | Concurrency control | Conflict answer |
| --- | --- | --- | --- |
| Public comment (`POST /blog/{slug}/comments`) | One unit of `BlogKitTransactionRunner::runRereadingOnDuplicate()` (a savepoint inside your transaction) | Unique `submit_key` (hash of the form's one-time token, the article, the email and the text): a form posted twice stores one comment; rate limit and captcha before the unit | 409 + `CONFLICT_001` + `Retry-After` on a lock conflict; a duplicate submission is answered as the first (the stored comment), no code |
| Staff reply (public page or admin) | One unit; in the admin, the reply **and** the approval of a pending parent (1.9 used two flushes) | Plain insert + update of the parent row | 409 + `CONFLICT_001` |
| Comment approve / reject / delete (admin) | One unit | Row update or delete; last write wins on the status | 409 + `CONFLICT_001` |
| Article create / edit / inline edit / delete (admin) | One transaction of `BlogKitTransactionRunner::flushPending()` | Unique `slug` and unique (`article`, `locale`) translation; a violation is caught: the form is shown again with "slug taken" (or "changed meanwhile, reload") | Lock conflict: 409 + `CONFLICT_001`; duplicate key: the form, no code |
| Tag create / edit / delete (admin) | One transaction of `flushPending()` | Unique `slug` and unique (`tag`, `locale`) translation, caught as above (the `UniqueEntity` check alone was a check-then-insert) | As above |
| Settings sections (admin) | One transaction of `flushPending()` | Only the changed columns are written; the same field is last write wins | 409 + `CONFLICT_001` |
| Settings row, first read of an empty table | One `INSERT` (in a savepoint inside a host transaction) | Unique `singleton_key`; the loser's violation is caught and the winner's row is read | None (a lock wait on the winner's uncommitted insert is the server's lock timeout) |
| `BlogTagRegistry::syncDefinitions()` | One unit for all the definitions, `runRereadingOnDuplicate()` | Unique `slug`: a tag created at the same moment by another writer fails the unit, which runs once more, finds the winner's tag and updates its names | `TransientStorageFailure` to your caller |
| `nowo:blog:sync-hashtags` | One unit **per article** (1.9: one flush for the whole run), `runRereadingOnDuplicate()` | Same unique `slug` and replay; the article and the tags are read again when the manager was replaced | Lock conflict: the command stops with an error, the articles before it stay saved; run it again |
| `BlogArticlePublishedEvent` | After the commit of the unit that published the article | Never dispatched for a unit that rolled back | None |

`grep -rn "flush()" src/` finds only `src/Transaction/BlogKitTransactionRunner.php`.

## The runner

`Nowo\BlogKitBundle\Transaction\BlogKitTransactionRunner` implements `nowo-tech/transaction-contract`'s
`TransactionRunnerInterface` on `nowo-tech/transaction-doctrine` ^1.6:

- `run($operation)`: the operation builds and changes the entities, the runner flushes and commits.
  - Your default manager (no `doctrine.entity_manager`): `DoctrineTransactionRunner::forHostEntityManager()`. An open
    manager is never cleared, closed or reset; a closed one (after a failed flush) is replaced through the registry;
    the manager must have **no pending changes** when the unit starts, or `LogicException` (see
    [UPGRADING](UPGRADING.md#the-host-entity-manager-must-be-clean-before-a-blog-write)).
  - A dedicated `doctrine.entity_manager`: `DoctrineTransactionRunner::forEntityManager()` (reset after a storage
    failure, as REQ-RUNTIME-002 rule 4 allows for a bundle-owned manager).
- `runRereadingOnDuplicate($operation)`: `run()`, once more after a unique violation (the operation reads again).
- `flushPending()`: the admin forms bind the managed entity before the save, so their changes are pending already;
  they are flushed in one transaction on the manager's connection (`DoctrineTransactionRunner::forConnection()`).
  The manager itself is not touched by the runner; Doctrine closes it after a failed flush, and
  `BlogKitWorkerStateSubscriber` replaces a closed manager at the next request.

After any failure the levels the unit opened are rolled back to where it started (savepoint aware; inside your
transaction, to the savepoint, also on PostgreSQL), so the connection stays usable. A deadlock, lock wait timeout or
serialization failure leaves as `TransientStorageFailure`; anything else is rethrown unchanged.

## Conflict codes

`BlogKitConflictSubscriber` answers a `TransientStorageFailure` (or a DBAL `RetryableException`) of a **Blog Kit
controller** (`Nowo\BlogKitBundle\Controller\…`, never one of your routes), at `kernel.exception` priority -64:

| Code | When | Status | `Retry-After` | `X-Conflict-Code` | Client should |
| --- | --- | --- | --- | --- | --- |
| `CONFLICT_001` | Deadlock (MySQL / MariaDB 1213, PostgreSQL 40P01), lock wait timeout (1205, 55P03), serialization failure (MariaDB 1020, PostgreSQL 40001); nothing of the request committed | 409 | `1` (or the policy's) | `CONFLICT_001` | Retry |
| `CONFLICT_002` | The same, after something of the same request was committed: the request's commit mark of transaction-doctrine (`ConflictCode::forRequest()`, when `NowoTransactionDoctrineBundle` runs with `commit_detection`), or a lost savepoint with no deadlock behind it | 409 | none | `CONFLICT_002` | Read again, do not repeat blindly |

- The body is transaction-doctrine's conflict answer: JSON `{"error", "code", "message"}` for JSON and XHR requests,
  else the "try again" page with `data-conflict-code` (UiKit's error page when installed).
- Every Blog Kit write is a single unit, so on the bundle's own routes `CONFLICT_002` only appears when something
  before the bundle (your listener, another bundle) committed in the same request.
- The `X-Conflict-Code` header holds only these two values. The bundle has no domain 409 of its own and no JSON API,
  so no `X-Error-Code` is sent.
- Up to 1.9 a lock conflict was a 500.

## What is not a conflict

- **Unique violations.** The slugs of articles and tags, and the (row, locale) of their translations, are unique. The
  admin forms catch the violation where it is the expected race (an error flash, the form is shown again: a domain
  answer with no `CONFLICT_*` code); the tag writers run the unit once more and use the winner's tag. Anywhere else a
  unique violation is a real error.
- **Connection loss** is a real error, never mapped.
- **A comment or article deleted meanwhile** answers 404 when it is gone before the request loads it; deleted during the
  save, the update matches no row and nothing is written.
- **A tag deleted while an article form is open** (1.12.1): the tag choices are read again when the form is submitted,
  so a deleted tag's id is no longer a choice and the form is shown again with "The selected choice is invalid" (no
  tag entity from an earlier request is used, so nothing stale is written).

## Host options

```yaml
nowo_blog_kit:
    conflict_response:
        status: ~          # null (default): nowo_transaction_doctrine.conflict_response.status when that bundle is
                           # registered, else 409. 409 or 503 forces it for Blog Kit.
        error_page: false  # true: an HttpException with the status, X-Conflict-Code and (CONFLICT_001) Retry-After,
                           # rendered by your error page.
```

- **503:** `status: 503` for a load balancer that retries 503 only (the code and `Retry-After` stay).
- **Your own shape:** tag a `ConflictResponseFormatterInterface` service (nowo-tech/transaction-doctrine), or set
  `error_page: true`.
- **Request-level `CONFLICT_002`:** register `Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle` with
  `commit_detection: true` (its default) so a commit earlier in the request is seen.
- **Your controllers** that call the bundle's services (`BlogCommentManager`, `BlogTagRegistry`) map
  `TransientStorageFailure` themselves, or use transaction-doctrine's responder.
- **Database settings:** `doctrine.dbal.use_savepoints: true` on DBAL 3; `innodb_lock_wait_timeout` / `lock_timeout` of
  5-10 s for the web workers, so a blocked write fails as a conflict instead of holding a worker.

## Workers and entity managers

The services are stateless; the publish buffer of `BlogArticlePublishedDoctrineSubscriber` (and, since 1.10.0, its
held events) is emptied at every request, message and command boundary. FrankenPHP worker mode with the kernel **not**
reset works. Your default manager is never cleared or reset while open; the bundle's own manager follows
REQ-RUNTIME-002 rule 4. Standard and DDD hosts: see [ARCHITECTURE](ARCHITECTURE.md) and the
[worker audit](FRANKENPHP-WORKER-AUDIT.md).

## Tests and CI

- `tests/Database/ConcurrencyTest.php` (group `database`, `BLOG_KIT_DATABASE_URL`), two connections on a real server,
  1-second lock waits:
  - two writers creating the same tag (registry, then the hashtag command): one tag, the replay updates or links the
    winner's tag, no duplicate translation;
  - a slug taken by a concurrent admin: a unique violation, not a conflict;
  - a real lock wait on a comment write: 409 + `CONFLICT_001` + `Retry-After`, nothing half-saved, the manager replaced,
    the connection usable;
  - a conflict after a commit of the same request: `CONFLICT_002`, no `Retry-After`, the first unit stays;
  - the publish event follows the commit and never a rolled-back unit.
- `tests/Database/SingletonAndSubmitRaceTest.php` (group `database`), the same two-connection setup:
  - a first request losing the settings insert reads the winner's row, also inside a host transaction (the savepoint:
    PostgreSQL's transaction is not aborted), and an empty table gets exactly one row;
  - a comment submitted twice is stored once; another visitor with the same token stores another comment; no token, no
    guard;
  - a concurrent insert of the same submission: the unique key refuses the second, the replay reads the winner;
  - a double submit while the first is in flight: 409 `CONFLICT_001`, then the stored comment, one row.
- `tests/Unit/EventSubscriber/BlogKitConflictSubscriberTest.php`, `tests/Integration/Transaction/BlogKitTransactionRunnerTest.php`,
  `tests/Unit/DependencyInjection/Compiler/ConflictResponsePassTest.php`: the mapping, the 409 / 503 option, the error
  page mode, the wiring, the runner on SQLite.
- CI job `Databases`: MySQL 8.4, MariaDB 11.4 and PostgreSQL 16, on DBAL 4 and DBAL 3, `--fail-on-skipped`; required
  on `main`. Locally: `docker compose up -d mysql mariadb postgres`, then
  `BLOG_KIT_DATABASE_URL=… vendor/bin/phpunit --group database` in the `php` service (URLs in `docker-compose.yml`).

## Singleton settings row

`content_blog_settings.singleton_key` is always `main` and unique, so the table holds one row. Two first requests on an
empty table both read nothing and both insert: the second waits for the first's key, fails with a unique violation, and
the bundle answers it as the domain result "the row exists": it reads the winner's row (never an error, never a second
row). Inside a host transaction the `INSERT` runs in a savepoint and the loser rolls back to it, so on PostgreSQL the
violation does not abort your transaction; the host's entity manager is not involved (the row is a plain DBAL insert).
Reads still use the lowest id. Inside a host transaction the re-read after the refused insert is a locking read (`FOR SHARE`): under MySQL / MariaDB REPEATABLE READ the transaction's first (empty) read fixed its snapshot, and a plain read would never see the winner.

## Double-submitted comments

The public comment form carries a one-time `submitToken` (random, per rendered form). The comment stores
`submit_key = sha256(token, article id, email, text)`, unique. A form posted twice (double click, browser retry, a
proxy replay) therefore stores one comment: the second submission finds the first one's key and returns the stored
comment, with the same "pending moderation" message. A concurrent second submission waits for the first's key; if the
first has not committed within the lock wait it answers 409 `CONFLICT_001` (retry is safe), and if it lost the unique
key the unit runs once more and finds the winner (`runRereadingOnDuplicate()`). The key mixes in the email and the
text so that a page served from a shared cache (every visitor gets the same token) never merges two visitors'
comments. The first lookup of a stored submission is a plain read (a locking read of a missing key takes a gap lock, and two concurrent submissions would deadlock on their inserts); the replay after the unique violation is a locking read inside a transaction, for the same snapshot reason as above, and it re-attaches the article by id in the current unit of work. A submission without a valid token (a form rendered before 1.11, host code) is stored without the guard.
Staff replies and admin moderation are not part of this guard (they are not browser forms posted twice by a visitor).

## Residual risks

- **Last write wins** on the same field of the same article, tag, comment or settings section: no optimistic
  `version` column (it would need a migration). Edits of different fields merge.
- **The hashtag command is not atomic** over the whole run: a conflict mid-run leaves the articles before it saved.
  Running it again is safe (it is idempotent).
- **Inside your transaction**, a unit is a savepoint: a lock conflict rolls back to it, but the server may already have
  rolled your transaction back (a MySQL deadlock does). Retry your whole transaction; the publish events of a unit
  inside your transaction are dispatched after the unit, before your commit.
- **Collations:** on a case-insensitive or PAD SPACE collation, `PHP` and `php` collide on the unique slug (the forms
  only accept lowercase slugs).
- **SQLite** has no row locks; a writing transaction locks the whole database (development only).
