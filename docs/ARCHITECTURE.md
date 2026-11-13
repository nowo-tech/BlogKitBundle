# Architecture: standard Symfony and DDD hosts

Blog Kit works out of the box in a **standard Symfony application** (attribute-mapped entities, the bundle's
controllers and services on your entity manager, Twig overrides). Since 1.9.0 it can also be used from a **strict DDD /
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
| Entity manager | the manager that maps the blog entities (your default one) | `doctrine.entity_manager: <name>`: a manager dedicated to the blog |
| Host ports | 1.x interfaces (deprecated, removed in 2.0) or the twins | twins only: snapshots and request VOs |
| Events | `getArticle()` (deprecated) or ids / snapshots | ids / snapshots |
| Base classes / traits on host entities | none required | none |

## Host ports

A host extends Blog Kit through ports it implements and names in the configuration. Since 1.9.0 every port that took a
bundle entity, the HTTP `Request` or a Symfony Form builder has a **twin** that takes and returns only bundle value
objects and scalars:

| Configuration key | Deprecated interface (removed in 2.0) | 1.9.0 twin |
|---|---|---|
| `security.object_access.service` | `Security\BlogKitResourceAccessCheckerInterface` (entities) | `Security\BlogKitResourcePolicyInterface` (`BlogArticleSnapshot`, `BlogTagSnapshot`, `BlogCommentSnapshot`) |
| `comments.rate_limit.service` | `Security\RateLimit\BlogCommentRateLimiterInterface` (`Request`, entity) | `Security\RateLimit\BlogCommentRateLimitPolicyInterface` (`BlogCommentRequestContext`, `BlogArticleSnapshot` → `BlogCommentRateLimitDecision`) |
| `comments.captcha.service` | `Security\Captcha\BlogCommentCaptchaStrategyInterface` (`FormBuilderInterface`) | `Security\Captcha\BlogCommentCaptchaVerifierInterface` (`widget(): BlogCommentCaptchaWidget`, `verify(BlogCommentRequestContext): bool`) |

Value objects (`src/ValueObject`): `BlogArticleSnapshot`, `BlogTagSnapshot` and `BlogCommentSnapshot` are copied when
the port is called (ids, slugs, state, dates, titles and the author / editor / moderator ids; never an entity, a
collection or a user object; a comment snapshot leaves out the author's e-mail and IP hash).
`BlogCommentRequestContext` carries the client IP, host, method, path and the top-level string fields of the submitted
body, never a cookie, a header bag or the session.

**Selection rule.** The same configuration key takes either interface. A service that implements the deprecated one is
asked exactly as in 1.8, also when it implements the twin too; one that implements only the twin is asked through an
internal gate (`Port\*Gate`, built by `Port\Ports`). `DependencyInjection\Compiler\BlogKitPortsPass` stops the container
build when a configured service implements neither; a factory service, whose class the build cannot know, fails closed
when it is built. Equivalence tests run the 1.8.3 classes against the same policies written for the twins
(`tests/Unit/Port/PortTwinsEquivalenceTest.php`).

The other ports already take values only: `BlogKitAccessCheckerInterface`, `BlogHtmlSanitizerInterface`,
`CaptchaHttpClientInterface`, `BlogIndexMetaProviderInterface`, `BlogBrandNameProviderInterface`, and the new
`User\BlogUserDirectoryInterface`.

## Events

`Event\BlogArticlePublishedEvent` carries the article id (`getArticleId()`) and a `BlogArticleSnapshot`
(`getArticleSnapshot()`) copied when the event is created. `getArticle()` is deprecated and removed in 2.0. The event is
dispatched from `postFlush`, after the flush committed its own transaction, and never for a flush that rolled back
(REQ-ARCH-001 rule 7, `tests/Integration/BlogArticlePublishedAfterCommitTest.php`). Inside a transaction **you** opened
around a bundle write, `postFlush` still runs before your commit: the transaction is yours.

## Persistence

**Standard host (default).** Blog Kit reads and writes through the manager that maps its entities (your default one),
as in 1.8: an admin save flushes that manager, including what your application left pending in it. This default changes
in 2.0 (REQ-ARCH-001 rule 6 stays ⚠️ until then: the opt-in below exists and works).

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
   id cannot be kept exactly gets HTTP 403 when creating an article, and any article flush made as that user is
   refused (`AccessDeniedException`), so no article is saved without its owner.
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

- **Standard host**: the whole unit and integration suite (attribute mapping, `MappedTestUser` Doctrine entity, the 1.x
  ports), and `tests/Integration/Worker/WorkerKeptAliveKernelTest.php` in `WorkerTestKernel::STANDARD` (built-in owner
  access, 1,000 alternating requests of two users).
- **DDD host**: `WorkerTestKernel::DDD` (plain PHP users, `doctrine.entity_manager: blog`, XML mapping, user references
  by id, twin-only ports): 1,000 alternating requests with fresh reads and an empty own manager after each one, the
  host's manager never flushed nor cleared by a bundle write, a closed own manager recreated;
  `tests/Integration/IdMode/IdModeUserReferenceTest.php` (id columns, blame, exact matching) and
  `XmlMappingParityTest`.
