# Architecture: standard Symfony and DDD hosts

Blog Kit works out of the box in a **standard Symfony application** (attribute-mapped entities on your default
connection and tables, the bundle's controllers and services on its own private entity manager, Twig overrides). Since 1.9.0 it can also be used from a **strict DDD /
hexagonal host** (plain-PHP users, framework-free ports, the blog as a separate bounded context on its own entity
manager). The standard path is the zero-config default; the DDD path is opt-in and never forced (REQ-ARCH-001). Both
work in FrankenPHP worker mode with the kernel kept alive and not reset (REQ-RUNTIME-002, see
[FRANKENPHP-WORKER-AUDIT](FRANKENPHP-WORKER-AUDIT.md)).

## Table of contents

- [The two host kinds at a glance](#the-two-host-kinds-at-a-glance)
- [Host ports](#host-ports)
- [Events](#events)
- [Persistence](#persistence)
- [Mapping and user references](#mapping-and-user-references)
  - [`BlameableUserTrait` fields for your own XML](#blameableusertrait-fields-for-your-own-xml)
- [Identifiers (REQ-SEC-006)](#identifiers-req-sec-006)
- [Configuration](#configuration)
- [Both host kinds, tested](#both-host-kinds-tested)

## The two host kinds at a glance

| | Standard host (default) | DDD host (opt-in) |
|---|---|---|
| Entity mapping | ORM attributes | `doctrine.mapping_type: xml` (`src/Resources/config/doctrine-xml`, same metadata) |
| Host users on blog rows | `ManyToOne` to `user_class`, which implements `Model\BlogUserInterface` | `doctrine.user_reference: id`: the user's id in a string column (`ValueObject\BlogUserRef`); the user class implements nothing of the bundle |
| Entity manager | since 2.0 the bundle's private manager on your default manager's connection, mapping and tables (your manager is never flushed by the bundle) | `doctrine.entity_manager: <name>`: a manager dedicated to the blog |
| Host ports | snapshots and request VOs (since 2.0 the only interfaces) | the same |
| Events | ids / snapshots | ids / snapshots |
| Base classes / traits on host entities | none required | none |

## Host ports

A host extends Blog Kit through ports it implements and names in the configuration. Every port takes and returns only
bundle value objects and scalars (1.9.0 added these twins; 2.0 removed the 1.x interfaces that took a bundle entity,
the HTTP `Request` or a Symfony Form builder):

| Configuration key | Interface (since 1.9.0; the only one since 2.0) | Removed in 2.0 (deprecated since 1.9) |
|---|---|---|
| `security.object_access.service` | `Security\BlogKitResourcePolicyInterface` (`BlogArticleSnapshot`, `BlogTagSnapshot`, `BlogCommentSnapshot`) | `Security\BlogKitResourceAccessCheckerInterface` (entities) |
| `comments.rate_limit.service` | `Security\RateLimit\BlogCommentRateLimitPolicyInterface` (`BlogCommentRequestContext`, `BlogArticleSnapshot` → `BlogCommentRateLimitDecision`) | `Security\RateLimit\BlogCommentRateLimiterInterface` (`Request`, entity) |
| `comments.captcha.service` | `Security\Captcha\BlogCommentCaptchaVerifierInterface` (`widget(): BlogCommentCaptchaWidget`, `verify(BlogCommentRequestContext): bool`) | `Security\Captcha\BlogCommentCaptchaStrategyInterface` (`FormBuilderInterface`) |

Value objects (`src/ValueObject`): `BlogArticleSnapshot`, `BlogTagSnapshot` and `BlogCommentSnapshot` are copied when
the port is called (ids, slugs, state, dates, titles and the author / editor / moderator ids; never an entity, a
collection or a user object; a comment snapshot leaves out the author's e-mail and IP hash).
`BlogCommentRequestContext` carries the client IP, host, method, path and the top-level string fields of the submitted
body, never a cookie, a header bag or the session.

**Selection rule.** Each key takes only its interface; the bundle asks it through an internal gate (`Port\*Gate`, built by
`Port\Ports`, implementing the `@internal` `Security\Internal\*` interfaces the built-in strategies implement).
`DependencyInjection\Compiler\BlogKitPortsPass` stops the container build when a configured service does not implement
it (also when its class still implements a removed 1.x interface and cannot be loaded); a factory service, whose class
the build cannot know, fails closed when it is built. `DependencyInjection\Compiler\BlogKitRemovedPortsPass` fails the
build on every other trace of a removed port, so Symfony never drops a host service silently: an autodiscovered class
that still implements one, an alias or `#[AsAlias]` of a removed id, and an `#[AsDecorator]` of one (also with
`onInvalid: IGNORE`; in 1.x the object access checker was aliased as `BlogKitResourceAccessCheckerInterface`). The
hook a decorator of the 1.x object access checker used is your own `BlogKitResourcePolicyInterface` service
(`strategy: service`). Equivalence tests run the 1.8.3 classes against the same policies written for the twins
(`tests/Unit/Port/PortTwinsEquivalenceTest.php`); the build errors run through the real `Bundle::build()`
(`tests/Integration/RemovedPorts/RemovedPortsKernelTest.php`).

The other ports already take values only: `BlogKitAccessCheckerInterface`, `BlogHtmlSanitizerInterface`,
`CaptchaHttpClientInterface`, `BlogIndexMetaProviderInterface`, `BlogBrandNameProviderInterface`, and the new
`User\BlogUserDirectoryInterface`.

## Events

`Event\BlogArticlePublishedEvent` carries the article id (`getArticleId()`) and a `BlogArticleSnapshot`
(`getArticleSnapshot()`) copied when the event is created; it holds no entity (`getArticle()` was removed in 2.0). The event is
dispatched from `postFlush`, after the flush committed its own transaction, and never for a flush that rolled back
(REQ-ARCH-001 rule 7, `tests/Integration/BlogArticlePublishedAfterCommitTest.php`). Inside a transaction **you** opened
around a bundle write, `postFlush` still runs before your commit: the transaction is yours.

## Persistence

**Standard host (default): the private entity manager.** Since 2.0 (REQ-ARCH-001 rule 6 ✅; opt-in since 1.12.0) the
bundle's services work through transaction-doctrine's `PrivateManager\PrivateEntityManager`, created on the connection,
configuration and event manager of your default manager, with no configuration. The 1.x mode in which a blog write
flushed your default manager, including what your application left pending in it, was removed with its notice and the
1.12 host-flush probe; `doctrine.private_entity_manager` is a deprecated no-op (`false` fails the build):

- same connection and tables, nothing to declare; inside a transaction you opened, the bundle's flushes use savepoints;
- every bundle service gets it (`nowo_blog_kit.entity_manager`); the service repositories, the transaction runner and
  the worker-state subscriber get `PrivateManager\PrivateManagerRegistry` (blog entities → the private manager, anything
  else → your registry), and your manager's `getRepository()` returns the same service repositories, so the entity
  value resolvers of the admin routes load into the private manager (the article form's tag choices are tag ids read
  through the tag repository, so the selected tags come from it too);
- `PrivateManager\PrivateEntityManagerMiddleware` on your manager's connection tells it when a rollback undid rows it
  wrote or loaded (it is replaced then, no ghost row); the bundle's own savepoints (`blog_kit_`) are ignored;
- dropped at every OUTERMOST unit-of-work boundary (`Worker\BlogKitOwnEntityManagerSubscriber`, plus the
  `nowo_transaction_doctrine.private_entity_manager` tag when NowoTransactionDoctrineBundle is registered, and
  `kernel.reset`), replaced when closed; a failed unit closes and drops only it;
- `Doctrine\BlogKitPrivateUserReferenceListener` (`user_reference: entity`): your user instance set on a blog row
  (also by AuditKit's blame) becomes a reference of the private manager before its flush, so your user is never
  inserted or written by the bundle; a user with no identifier yet fails the write with a clear message (2.0);
- `Service\BlogCommentManager` re-loads by id a comment your own manager loaded before it moderates, deletes or answers
  it (2.0), so it is never inserted again nor changed where nobody saves it.

Your managers are never flushed, cleared, closed or reset by the bundle
(`tests/Integration/Worker/HostPendingChangesTestCase.php`, on SQLite and the real servers).

**Own entity manager (opt-in, `doctrine.entity_manager: <name>`).** Declare a manager for the blog under
`doctrine.orm.entity_managers` (no mappings, `auto_mapping: false`, any connection). The bundle then:

- maps its entities on that manager only (attribute or XML), and makes them transient on every other manager (an
  excluded-namespace driver first in their chains, so DoctrineBundle 3.4 `auto_discover_entities` cannot claim them);
  it turns `auto_discover_entities` off on its own manager unless you set it;
- fails the build when that manager is your default one, does not exist, or also maps other classes;
- reads and writes only through it (every bundle service gets `nowo_blog_kit.entity_manager`), so a blog write never
  flushes, commits or clears your managers;
- clears it at every OUTERMOST unit-of-work boundary (main request start and `kernel.terminate`, Messenger message
  received / handled / failed, outermost console command start / terminate) and recreates it when a failed flush closed
  it (`Worker\BlogKitOwnEntityManagerSubscriber`, judged by `Worker\UnitOfWorkBoundaries`, the tracker of
  PageBuilderKit 1.15.2 / DashboardMenu 2.21.1).

It needs `doctrine.user_reference: id`: a relation to your user entity cannot cross managers.

```yaml
doctrine:
    orm:
        default_entity_manager: default
        entity_managers:
            default:
                mappings: { App: { type: xml, dir: '%kernel.project_dir%/config/doctrine', prefix: App\Domain } }
            blog:
                connection: default

nowo_blog_kit:
    doctrine:
        entity_manager: blog
        mapping_type: xml
        user_reference: id
```

## Mapping and user references

`doctrine.mapping_type: xml` maps the 8 entities from `src/Resources/config/doctrine-xml/*.orm.xml`, generated from the
attributes by `.scripts/generate-doctrine-xml.php`; `tests/Integration/XmlMappingParityTest.php` proves both describe the
same metadata (under the default and the underscore naming strategies).

`doctrine.user_reference: id` replaces the four user relations (`BlogArticle::createdBy` / `updatedBy`,
`BlogComment::staffAuthor` / `moderatedBy`) by string columns of the same names (`created_by_id`, `updated_by_id`,
`staff_author_id`, `moderated_by_id`), each with an index and no foreign key. A row read back holds a
`ValueObject\BlogUserRef` there (it implements `BlogUserInterface`, so the entity accessors keep their types). The bundle
takes the id of your security user from its `getId()` (an int, a string or a `Stringable` such as a `Uuid`), stamps
`createdBy` / `updatedBy` of the articles written by a signed-in user (`Doctrine\BlogUserBlameListener`), and shows a
label for an id through the optional `user_directory` (`User\BlogUserDirectoryInterface`; without it, the id). The
Twig function `nowo_blog_kit_user_label(user)` renders either kind.

The bundle's entities are bundle-owned: a host never extends them and references them by id (snapshot ids and event
ids).

### `BlameableUserTrait` fields for your own XML

`Model\BlameableUserTrait` (used by `BlogArticle`) is a convenience for standard hosts. A trait cannot carry its own
XML mapping; if you use it on an entity of yours with XML mapping, map these fields:

| Field | Column | Type | Nullable | Notes |
|---|---|---|---|---|
| `createdAt` | `created_at` | `datetime_immutable` | no | from AuditKit `TimestampableTrait` |
| `updatedAt` | `updated_at` | `datetime_immutable` | no | from AuditKit `TimestampableTrait` |
| `createdBy` | `created_by_id` | relation to your user (`ManyToOne`, `on-delete="SET NULL"`) or `nowo_blog_kit_user_ref` (length 128) | yes | index it |
| `updatedBy` | `updated_by_id` | same as `createdBy` | yes | index it |

## Identifiers (REQ-SEC-006)

The id columns of `user_reference: id` hold identities a host supplies, so they compare byte for byte:

1. `BlogUserRef` and the DBAL type `nowo_blog_kit_user_ref` refuse ids with edge whitespace (NBSP included), control or
   format characters (ZWSP, BOM, bidi marks), invalid UTF-8 or more than 128 characters; nothing is trimmed. A stored
   value that is not valid (legacy or hand-written) reads back as a `ValueObject\UnreadableBlogUserRef`: an owner
   nobody matches, never "no owner". The built-in owner access, the owner listing and the snapshot flag
   `BlogArticleSnapshot::$createdByUnreadable` deny it; only a really NULL column is unowned. A signed-in user whose
   id cannot be kept exactly gets HTTP 403 on every admin route that writes articles, tags or settings, before the
   form touches an entity; as a backstop any article flush made as that user is refused (`AccessDeniedException`):
   nothing of the refused blog write stays on the manager, so the next flush (another user, a later request of a
   kept-alive worker) cannot save it. The bundle's own manager (private or dedicated) is closed, as after any failed
   flush; one of your managers is never closed (since 1.12.0): the managed blog entities of the flush get their
   original values back and stay managed, the new ones are forgotten and unlinked from your entities, and your own
   pending changes stay.
2. The owner listing re-checks every row with `===` in PHP after the query; the owner policy compares ids with `===`
   and never falls back to a login identifier for a reference. The re-check runs after `LIMIT`: with the id mode the
   SQL is already exact (item 3), so it drops nothing; in the entity mode under a folding collation of a string user
   primary key it can leave a short page and an over-counted total (known limit, never another owner's row).
3. On MySQL / MariaDB the columns get a binary NO PAD collation from the bundle's metadata listener:
   `utf8mb4_0900_bin` (MySQL ≥ 8.0.17), `utf8mb4_nopad_bin` (MariaDB), `utf8mb4_bin` only on an older MySQL or when the
   server cannot be asked its version, with a logged warning. The version is read from the live server when the
   metadata is built (the configured `server_version` only picks the DBAL platform); metadata warmed with no database
   reachable (e.g. `cache:warmup` in an image build) is cached with `utf8mb4_bin`: warm it with the database up, or
   clear the metadata cache before generating a migration. PostgreSQL and SQLite compare exactly.
4. `user_directory` labels are looked up by the exact id.
5. Id checks use anchored regular expressions with `/D`.
6. The owner filter is exact in SQL (binary collation) before `LIMIT`.
7. In-memory comparisons are strict; NFC and NFD forms are different ids.

## Configuration

Configuration comes from the bundle's config tree and the host ports (rule 8); the blog settings row is the bundle's
own table. No dependency on InstanceSettings.

## Both host kinds, tested

- **Standard host**: the whole unit and integration suite (attribute mapping, `MappedTestUser` Doctrine entity, the
  private manager with zero configuration), `tests/Integration/Worker/WorkerKeptAliveKernelTest.php` and
  `PrivateEntityManagerKernelTest.php` in `WorkerTestKernel::STANDARD` (built-in owner access, alternating requests of
  two users, the private manager dropped at every boundary), `HostPendingChangesTestCase` (your pending changes never
  flushed by a blog write) and `AuditKitPrivateManagerKernelTest` (AuditKit 2.x blame through the private manager).
- **DDD host**: `WorkerTestKernel::DDD` (plain PHP users, `doctrine.entity_manager: blog`, XML mapping, user references
  by id, twin-only ports): 1,000 alternating requests with fresh reads and an empty own manager after each one, the
  host's manager never flushed nor cleared by a bundle write, a closed own manager recreated;
  `tests/Integration/IdMode/IdModeUserReferenceTest.php` (id columns, blame, exact matching) and
  `XmlMappingParityTest`.
