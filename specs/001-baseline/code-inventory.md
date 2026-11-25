# Code inventory — BlogKitBundle baseline

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/blog-kit-bundle`  
**Last audited**: 2026-11-27 (2.1.0; 2.0.0 removals, 2.1.0 banner and validators mapped)  
**Coverage summary**: **198/198** files tracked under `src/` mapped (see [Coverage summary](#coverage-summary)); audit command: `git ls-files src | wc -l`.

Maps **100%** of the files under `src/` (PHP classes + Twig views, translations, config, public assets, TypeScript sources and co-located Vitest specs). Paths are relative to `src/`. Test and demo trees outside `src/` are out of Packagist scope unless promoted in the spec.

## Bundle entry

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `NowoBlogKitBundle.php` | Bundle entry, Twig path pass, Doctrine mappings | FR-BUNDLE-001 |

## DependencyInjection

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/NowoBlogKitExtension.php` | DI extension, prepends (FormKit profiles + `type_map.entity`), access checker | FR-CFG-002, FR-DI-001, FR-UI-002, FR-UI-003, FR-FORM-001 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig override path order | FR-TWIG-001 |
| `DependencyInjection/Compiler/CsrfProtectionPass.php` | Fails closed without form CSRF protection | FR-SEC-006 |
| `DependencyInjection/TablePrefixListener.php` | Optional table prefix | FR-ORM-001 |
| `DependencyInjection/Compiler/BlogKitEntityManagerPass.php` | Keeps the blog entities on the opt-in `doctrine.entity_manager` only (1.9) | FR-ORM-002 |
| `DependencyInjection/Compiler/BlogKitRemovedPortsPass.php` | 2.0: fails the build on any trace of the three removed 1.x host ports (a definition whose loading error names one, a service or alias under a removed id, a decorator of one), so a host object-access decorator can never be silently dropped | FR-ARC-002 |
| `DependencyInjection/Compiler/BlogKitPortsPass.php` | Fails the build when a configured port service implements none of the 1.9 port interfaces (2.0: the 1.x ones are removed) | FR-ARC-001 |
| `DependencyInjection/Compiler/ConflictResponsePass.php` | Wires the conflict policy and responder of `BlogKitConflictSubscriber` (`conflict_response`, transaction-doctrine's when registered) (1.10) | FR-CONC-002 |
| `DependencyInjection/Compiler/BlogKitPrivateEntityManagerPass.php` | Since 1.12.0 (`doctrine.private_entity_manager: true`): resolves your manager the private one is created on — your default entity manager (`doctrine.default_ent | FR-ORM-005 |

## Controllers

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Controller/BlogPublicController.php` | Public index and article | FR-PUB-001, FR-ART-003 |
| `Controller/PublicCommentController.php` | Public comment create and staff reply | FR-CMT-001, FR-CMT-002 |
| `Controller/Admin/BlogArticleController.php` | Article admin CRUD + inline modal + object deny | FR-ADM-001, FR-UI-003 |
| `Controller/Admin/BlogTagController.php` | Tag admin CRUD + object deny | FR-ADM-001, FR-TAG-001, FR-UI-003 |
| `Controller/Admin/BlogCommentController.php` | Comment moderation queue + object deny | FR-CMT-003, FR-UI-003 |
| `Controller/Admin/BlogSettingsController.php` | Settings singleton form | FR-SET-001 |
| `Controller/Admin/AdminListFilterTrait.php` | GET filter helper | FR-ADM-001 |
| `Controller/RequiresValidFormTrait.php` | CSRF form guard | FR-ADM-001 |
| `Controller/Admin/DeniesUnrecordableUserTrait.php` | Denies writes for a user whose id cannot be kept exactly (`user_reference: id`, 1.9) | FR-ORM-004, FR-ADM-001 |

## Entity & persistence

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Entity/BlogArticle.php` | Article aggregate | FR-ART-001 |
| `Entity/BlogArticleTranslation.php` | Localized title/body | FR-ART-001, FR-I18N-003 |
| `Entity/BlogArticleResource.php` | Related resources | FR-ART-001 |
| `Entity/BlogTag.php` | Tag aggregate | FR-TAG-001 |
| `Entity/BlogTagTranslation.php` | Localized tag name | FR-TAG-001 |
| `Entity/BlogComment.php` | Comment + replies | FR-CMT-001 |
| `Entity/BlogCommentStatus.php` | Pending/approved/rejected | FR-CMT-001 |
| `Entity/BlogSettings.php` | Singleton settings | FR-SET-001 |
| `Repository/BlogArticleRepository.php` | Public/admin article queries | FR-ORM-001, FR-ART-003 |
| `Repository/BlogArticleTranslationRepository.php` | Translation queries | FR-ORM-001 |
| `Repository/BlogArticleResourceRepository.php` | Resource queries | FR-ORM-001 |
| `Repository/BlogTagRepository.php` | Tag queries | FR-ORM-001, FR-TAG-001 |
| `Repository/BlogTagTranslationRepository.php` | Tag translation queries | FR-ORM-001 |
| `Repository/BlogCommentRepository.php` | Comment queries | FR-ORM-001, FR-CMT-002 |
| `Repository/BlogSettingsRepository.php` | Settings singleton (`HINT_REFRESH` on load) | FR-ORM-001, FR-SET-001, FR-WORKER-001 |
| `Repository/Concerns/JoinsTranslationsAndAuditUsers.php` | Shared joins | FR-ORM-001 |
| `Repository/Concerns/RunsDocumentedSql.php` | Documented SQL helper | FR-ORM-001 |

## Domain model & enums

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Enum/CssFramework.php` | Admin/public CSS stack | FR-UI-001 |
| `Enum/IconSet.php` | Admin icon rendering set | FR-UI-001 |
| `Enum/RowActionsDisplay.php` | Row action icon / text / icon_text | FR-UI-001 |
| `Enum/BlogListingMode.php` | Paginated vs infinite | FR-SET-001, FR-PUB-002 |
| `Enum/BlogMasonryStrategy.php` | Masonry vs grid vs list | FR-SET-001, FR-PUB-002 |
| `Enum/BlogAsidePlacement.php` | Aside left/right/none | FR-SET-001 |
| `Enum/BlogHeroImageMode.php` | Hero image fit | FR-SET-001 |
| `Enum/CommentRateLimitStrategy.php` | Comment rate-limit strategies | FR-CMT-004 |
| `Enum/CommentCaptchaStrategy.php` | Comment CAPTCHA strategies | FR-CMT-004 |
| `Enum/HtmlSanitizeStrategy.php` | Article HTML sanitizer strategies | FR-SEC-005 |
| `Enum/BlogObjectAccessStrategy.php` | none / owner / service object access | FR-UI-003 |
| `Model/BlogUserInterface.php` | Host user contract | FR-CFG-001 |
| `Model/BlogStaffDisplayNameInterface.php` | Optional public name of staff replies | FR-CMT-005 |
| `Model/BlameableUserTrait.php` | Created/updated by | FR-ART-001 |
| `Locale/BlogLocales.php` | Injected locale catalog (default + supported list) | FR-I18N-003 |
| `Meta/BlogBrandNameProviderInterface.php` | Optional title suffix | FR-PUB-001 |
| `Meta/BlogIndexMetaProviderInterface.php` | Optional index meta | FR-PUB-001 |

## Services

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Service/BlogCatalog.php` | Public catalogue facade | FR-ART-003, FR-PUB-001 |
| `Service/BlogSettingsProvider.php` | Settings access (request-scoped memo) | FR-SET-001, FR-WORKER-001 |
| `Service/BlogCommentManager.php` | Comment lifecycle | FR-CMT-001, FR-CMT-002, FR-CMT-003 |
| `Service/BlogHashtagProcessor.php` | Hashtag parse/format | FR-TAG-002 |
| `Service/BlogTagRegistry.php` | Tag lookup/create; one unit, replayed once on a concurrent duplicate slug (1.10) | FR-TAG-001, FR-CONC-003 |
| `Transaction/BlogKitTransactionRunner.php` | The bundle's transaction runner on transaction-doctrine: units, replay on duplicate, bound-form flush, publish events after commit (1.10); 2.0 runs on the private entity manager, the host-flush reporter argument is ignored | FR-CONC-001, FR-ORM-006 |
| `Service/BlogArticleBodyEnhancer.php` | Body post-processing | FR-ART-001 |
| `Service/BlogPostBodyFormatter.php` | Body formatting | FR-ART-001 |
| `Service/BlogLocalesLocaleResolver.php` | Request locale vs config | FR-I18N-003 |

## HTTP, events, command

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Event/BlogArticlePublishedEvent.php` | Published domain event | FR-ART-002 |
| `EventSubscriber/BlogArticlePublishedDoctrineSubscriber.php` | onFlush/postFlush dispatch; buffer cleared per flush / `ResetInterface` | FR-ART-002, FR-WORKER-003 |
| `EventSubscriber/BlogKitWorkerStateSubscriber.php` | Clears bundle memos and recovers a closed EM on each main request | FR-WORKER-001, FR-WORKER-004 |
| `EventSubscriber/BlogKitAdminAccessSubscriber.php` | Admin route guard | FR-UI-002 |
| `EventSubscriber/BlogArticleHtmlSanitizeSubscriber.php` | Sanitize article HTML on persist | FR-SEC-005 |
| `EventSubscriber/BlogKitConflictSubscriber.php` | Lock conflicts of the bundle's controllers answer 409 + `CONFLICT_001` / `CONFLICT_002` + `X-Conflict-Code` (1.10) | FR-CONC-002 |
| `Command/SyncBlogHashtagsCommand.php` | CLI hashtag sync; one unit per article, replayed once on a concurrent duplicate slug (1.10) | FR-TAG-002, FR-CONC-003 |

## Forms

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Form/AbstractBlogFormType.php` | Shared FormKit type (`blog_kit` profile) | FR-ADM-001, FR-FORM-001 |
| `Form/BlogArticleType.php` | Article admin form | FR-ADM-001 |
| `Form/BlogArticleTranslationType.php` | Article translation | FR-ART-001 |
| `Form/BlogArticleInlineTranslationType.php` | Inline modal translation | FR-ADM-001 |
| `Form/BlogArticleResourceType.php` | Resource collection | FR-ART-001 |
| `Form/BlogArticleFilterType.php` | Article GET filters | FR-ADM-001 |
| `Form/BlogInlineModalType.php` | Inline CMS form | FR-ADM-001 |
| `Form/BlogTagType.php` | Tag admin form | FR-TAG-001 |
| `Form/BlogTagTranslationType.php` | Tag translation | FR-TAG-001 |
| `Form/BlogTagFilterType.php` | Tag GET filters | FR-ADM-001 |
| `Form/BlogCommentFilterType.php` | Comment GET filters | FR-CMT-003 |
| `Form/BlogSettingsType.php` | Settings form | FR-SET-001 |
| `Form/BlogPublicSearchType.php` | Public search | FR-PUB-001 |
| `Form/PublicBlogCommentType.php` | Public comment | FR-CMT-001 |
| `Form/StaffBlogCommentReplyType.php` | Staff reply | FR-CMT-003 |

## Security

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Security/BlogKitAccessCheckerInterface.php` | Access checker contract | FR-UI-002 |
| `Security/Internal/ObjectAccessCheckerInterface.php` | 2.0, `@internal`: how controllers and Twig ask the object-level admin access; implemented by the built-in checkers and by the gate over `BlogKitResourcePolicyInterface` (replaces the removed 1.x `BlogKitResourceAccessCheckerInterface`) | FR-UI-003, FR-ARC-001 |
| `Security/ConfigurableBlogKitAccessChecker.php` | Default role-based checker | FR-UI-002 |
| `Security/AllowAllBlogKitAccessChecker.php` | Demo allow-all checker | FR-UI-002 |
| `Security/AllowAllBlogKitResourceAccessChecker.php` | Default: no extra object filter | FR-UI-003 |
| `Security/OwnerBlogKitResourceAccessChecker.php` | `createdBy` publication scoping | FR-UI-003 |
| `Security/BlogKitAccessDenied.php` | Throws AccessDeniedException for object checks | FR-UI-003 |
| `Support/BlogUserIdResolver.php` | Compare blameable / security users | FR-UI-003 |
| `Security/BlogProtectionConfig.php` | YAML protection defaults | FR-CMT-004, FR-SEC-005 |
| `Security/BlogProtection.php` | Strategy resolver (YAML + settings) | FR-CMT-004, FR-SEC-005 |
| `Security/Internal/CommentRateLimiterInterface.php` | 2.0, `@internal`: how the public comment controller consumes one submission against the active limiter; built-in limiters and the policy gate implement it (replaces the removed 1.x `BlogCommentRateLimiterInterface`) | FR-CMT-004, FR-ARC-001 |
| `Security/Internal/CommentCaptchaInterface.php` | 2.0, `@internal`: how the public form configures and verifies the active CAPTCHA strategy; built-in strategies and the verifier gate implement it (replaces the removed 1.x `BlogCommentCaptchaStrategyInterface`) | FR-CMT-004, FR-ARC-001 |
| `Security/RateLimit/NullBlogCommentRateLimiter.php` | `none` / disabled limiter | FR-CMT-004 |
| `Security/RateLimit/CacheBlogCommentRateLimiter.php` | Cache-backed windows | FR-CMT-004 |
| `Security/Captcha/NoneCommentCaptchaStrategy.php` | `none` CAPTCHA | FR-CMT-004 |
| `Security/Captcha/HoneypotCommentCaptchaStrategy.php` | Honeypot field | FR-CMT-004 |
| `Security/Captcha/RemoteCommentCaptchaStrategy.php` | reCAPTCHA / hCaptcha / Turnstile | FR-CMT-004 |
| `Security/Captcha/CaptchaHttpClientInterface.php` | CAPTCHA HTTP contract | FR-CMT-004 |
| `Security/Captcha/StreamCaptchaHttpClient.php` | Default CAPTCHA HTTP client (configurable timeout) | FR-CMT-004, FR-WORKER-005 |
| `Security/Captcha/PublicBlogCommentCaptchaTypeExtension.php` | Public form CAPTCHA wiring | FR-CMT-004 |
| `Security/Html/BlogHtmlSanitizerInterface.php` | HTML sanitizer contract | FR-SEC-005 |
| `Security/Html/NullBlogHtmlSanitizer.php` | `none` sanitizer | FR-SEC-005 |
| `Security/Html/StripBlogHtmlSanitizer.php` | Strip tags | FR-SEC-005 |
| `Security/Html/AllowlistBlogHtmlSanitizer.php` | CMS allowlist | FR-SEC-005 |


## Doctrine — entity manager and user references

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Doctrine/ExcludedEntityNamespaceDriver.php` | Maps nothing for the blog namespace on every other entity manager | FR-ORM-002 |
| `Doctrine/BlogUserReferenceMetadataListener.php` | Maps the user fields as id string columns (`user_reference: id`) | FR-ORM-004 |
| `Doctrine/BlogUserBlameListener.php` | Stamps `createdBy` / `updatedBy` with the current user reference (`user_reference: id`) | FR-ORM-004 |
| `Doctrine/Type/BlogUserRefType.php` | Column type of the user id fields (`BlogUserRef`) | FR-ORM-004 |
| `Doctrine/BlogKitPrivateUserReferenceListener.php` | Since 1.12.0 (`doctrine.private_entity_manager: true` with `doctrine.user_reference: entity`): before a flush, a blog row whose to-one association to one of you | FR-ORM-005 |
| `User/BlogUsers.php` | How blog rows reference host users (entity relation or id) | FR-ORM-004 |
| `User/BlogUserDirectoryInterface.php` | Optional host port: label of a stored user id | FR-ORM-004, FR-ARC-001 |
| `ValueObject/BlogUserRef.php` | Host user referenced by id | FR-ORM-004 |
| `ValueObject/UnreadableBlogUserRef.php` | Stored user id the bundle cannot keep exactly (writes denied) | FR-ORM-004 |

## Host ports — 1.9 framework-free twins

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Port/Ports.php` | 2.0: each port key takes only the 1.9 interface; `Ports::REMOVED` maps the three removed 1.x interfaces to their replacements; anything else fails when built | FR-ARC-001 |
| `Port/BlogKitResourcePolicyGate.php` | Adapts a `BlogKitResourcePolicyInterface` to the internal `ObjectAccessCheckerInterface` | FR-ARC-001, FR-UI-003 |
| `Port/BlogCommentCaptchaVerifierGate.php` | Adapts a `BlogCommentCaptchaVerifierInterface` to the internal `CommentCaptchaInterface` | FR-ARC-001, FR-CMT-004 |
| `Port/BlogCommentRateLimitPolicyGate.php` | Adapts a `BlogCommentRateLimitPolicyInterface` to the internal `CommentRateLimiterInterface` (HTTP 429 on deny) | FR-ARC-001, FR-CMT-004 |
| `Port/BlogCommentRequestContexts.php` | Builds the request context of the request-free ports per call | FR-ARC-001, FR-WORKER-006 |
| `Security/BlogKitResourcePolicyInterface.php` | Object access twin taking snapshots instead of entities | FR-ARC-001, FR-UI-003 |
| `Security/Captcha/BlogCommentCaptchaVerifierInterface.php` | CAPTCHA twin without the Form builder | FR-ARC-001, FR-CMT-004 |
| `Security/RateLimit/BlogCommentRateLimitPolicyInterface.php` | Rate-limit twin without `Request` nor the article entity | FR-ARC-001, FR-CMT-004 |
| `ValueObject/BlogArticleSnapshot.php` | What ports and events see of an article | FR-ARC-001 |
| `ValueObject/BlogTagSnapshot.php` | What ports see of a tag | FR-ARC-001 |
| `ValueObject/BlogCommentSnapshot.php` | What ports see of a comment (no e-mail / IP hash) | FR-ARC-001 |
| `ValueObject/BlogCommentRequestContext.php` | Request parts a comment port may need (no cookies, headers, session) | FR-ARC-001 |
| `ValueObject/BlogCommentCaptchaWidget.php` | How the public form renders a twin CAPTCHA | FR-ARC-001, FR-CMT-004 |
| `ValueObject/BlogCommentRateLimitDecision.php` | Rate-limit twin answer (`Retry-After`) | FR-ARC-001, FR-CMT-004 |

## Worker boundaries

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Worker/BlogKitOwnEntityManagerSubscriber.php` | Keeps the opt-in own entity manager usable in a kept-alive kernel | FR-WORKER-006, FR-ORM-002 |
| `Worker/UnitOfWorkBoundaries.php` | Tracks the outermost request / message / command boundary (REQ-RUNTIME-002) | FR-WORKER-006 |

## UiKit pages

| Source file | Role | Requirement IDs |
| --- | --- | --- |
| `Ui/BlogKitPages.php` | Layout resolution (2.0: UiKit canonical layouts only, the 1.x layout bridge is removed) | FR-UI-004 |
| `Ui/BlogKitPageFactory.php` | Page view models shared by controllers and the page contract test | FR-UI-004 |
| `Ui/BlogKitAreaNav.php` | `blog_kit` / `blog_kit_settings` area navs | FR-UI-004 |

## Twig

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Twig/BlogKitExtension.php` | Layout globals, per-call access functions (legacy access globals deprecated), object-access helpers, CAPTCHA context | FR-UI-001, FR-UI-002, FR-UI-003, FR-CMT-004, FR-WORKER-002 |

## Non-PHP production assets (documented)

| Path | Purpose | Requirement IDs |
| --- | --- | --- |
| `Resources/views/_scripts.html.twig` | Bundle JS include (asset package) | FR-PUB-001, FR-ADM-001 |
| `Resources/views/_stylesheets.html.twig` | Bundle CSS include (asset package) | FR-PUB-001, FR-ADM-001 |
| `Resources/views/admin/_audit_cells.html.twig` | Created/updated-by audit cells of the admin lists | FR-ADM-001, FR-TWIG-005 |
| `Resources/views/admin/_form_body.html.twig` | Article form body (FormKit `form_row` loop) | FR-ADM-001, FR-TWIG-005 |
| `Resources/views/admin/_modal_form.html.twig` | Inline modal form for articles | FR-ADM-001, FR-TWIG-005 |
| `Resources/views/admin/comments/index.html.twig` | Comment moderation queue | FR-ADM-001, FR-TWIG-005, FR-CMT-001, FR-CMT-004 |
| `Resources/views/admin/form.html.twig` | Article create/edit page | FR-ADM-001, FR-TWIG-005 |
| `Resources/views/admin/form_theme.html.twig` | Admin form theme | FR-ADM-001, FR-TWIG-005 |
| `Resources/views/admin/icons/blog.svg.twig` | Blog area icon | FR-ADM-001, FR-TWIG-005 |
| `Resources/views/admin/index.html.twig` | Article admin index | FR-ADM-001, FR-TWIG-005 |
| `Resources/views/admin/settings.html.twig` | Settings singleton page | FR-ADM-001, FR-TWIG-005, FR-SET-001 |
| `Resources/views/admin/tags/form.html.twig` | Tag create/edit page | FR-ADM-001, FR-TWIG-005, FR-TAG-001 |
| `Resources/views/admin/tags/index.html.twig` | Tag admin index | FR-ADM-001, FR-TWIG-005, FR-TAG-001 |
| `Resources/views/public/_aside_index.html.twig` | Public index sidebar | FR-PUB-001, FR-TWIG-005 |
| `Resources/views/public/_aside_show.html.twig` | Article page sidebar | FR-PUB-001, FR-TWIG-005 |
| `Resources/views/public/_comments.html.twig` | Public comments + comment form | FR-PUB-001, FR-TWIG-005, FR-CMT-001, FR-CMT-004 |
| `Resources/views/public/_items.html.twig` | Article list items (infinite scroll page) | FR-PUB-001, FR-TWIG-005 |
| `Resources/views/public/_meta.html.twig` | SEO / Open Graph meta of an article | FR-PUB-001, FR-TWIG-005 |
| `Resources/views/public/_search.html.twig` | Public search / filter form | FR-PUB-001, FR-TWIG-005 |
| `Resources/views/public/index.html.twig` | Public article index | FR-PUB-001, FR-TWIG-005 |
| `Resources/views/public/show.html.twig` | Public article page | FR-PUB-001, FR-TWIG-005 |
| `Resources/translations/validators.de.yaml` | `validators` domain (de): constraint messages of the slug field and the comment privacy checkbox | FR-I18N-004 |
| `Resources/translations/validators.en.yaml` | `validators` domain (en): constraint messages of the slug field and the comment privacy checkbox | FR-I18N-004 |
| `Resources/translations/validators.es.yaml` | `validators` domain (es): constraint messages of the slug field and the comment privacy checkbox | FR-I18N-004 |
| `Resources/translations/validators.fr.yaml` | `validators` domain (fr): constraint messages of the slug field and the comment privacy checkbox | FR-I18N-004 |
| `Resources/translations/validators.it.yaml` | `validators` domain (it): constraint messages of the slug field and the comment privacy checkbox | FR-I18N-004 |
| `Resources/translations/validators.nl.yaml` | `validators` domain (nl): constraint messages of the slug field and the comment privacy checkbox | FR-I18N-004 |
| `Resources/translations/validators.pt.yaml` | `validators` domain (pt): constraint messages of the slug field and the comment privacy checkbox | FR-I18N-004 |
| `Resources/translations/NowoBlogKitBundle.en.yaml` | Reference catalogue | FR-I18N-002, FR-I18N-003 |
| `Resources/translations/NowoBlogKitBundle.es.yaml` | Locale parity | FR-I18N-002, FR-I18N-003 |
| `Resources/translations/NowoBlogKitBundle.it.yaml` | Locale parity | FR-I18N-002, FR-I18N-003 |
| `Resources/translations/NowoBlogKitBundle.fr.yaml` | Locale parity | FR-I18N-002, FR-I18N-003 |
| `Resources/translations/NowoBlogKitBundle.pt.yaml` | Locale parity | FR-I18N-002, FR-I18N-003 |
| `Resources/translations/NowoBlogKitBundle.de.yaml` | Locale parity | FR-I18N-002, FR-I18N-003 |
| `Resources/translations/NowoBlogKitBundle.nl.yaml` | Locale parity | FR-I18N-002, FR-I18N-003 |
| `Resources/public/blog.css` | Public styles | FR-PUB-001 |
| `Resources/public/blog-kit.js` | Infinite scroll + collection IIFE | FR-PUB-002, FR-ADM-001 |
| `Resources/public/blog-infinite-controller.js` | Legacy filename copy of `blog-kit.js` | FR-PUB-002 |
| `Resources/assets/src/blog-kit.ts` | Vite IIFE entry; logs the `script loaded` banner | FR-PUB-002, FR-UI-005 |
| `Resources/assets/src/logger.ts` | Bundle logger: `scriptLoaded()` banner with the build label, quiet switch (`data-nowo-console`, `NOWO_CONSOLE`), debug-gated levels (REQ-ASSETS-005, 2.1.0) | FR-UI-005 |
| `Resources/assets/src/blog-infinite-controller.ts` | Infinite scroll module | FR-PUB-002 |
| `Resources/assets/src/blog-form-collection.ts` | CollectionType add/remove | FR-ADM-001 |
| `Resources/assets/src/blog-captcha.ts` | reCAPTCHA v3 submit hook | FR-CMT-004 |
| `Resources/assets/src/blog.css` | Public stylesheet source (built to `Resources/public/blog.css`) | FR-PUB-001 |
| `Resources/config/services.yaml` | Service autowire | FR-CFG-002 |
| `Resources/config/routing.yaml` | Attribute controller import | FR-BUNDLE-001 |
| `Resources/config/doctrine-xml/BlogArticle.orm.xml` | XML mapping of `BlogArticle` (`doctrine.mapping_type: xml`, 1.9) | FR-ORM-003 |
| `Resources/config/doctrine-xml/BlogArticleTranslation.orm.xml` | XML mapping of `BlogArticleTranslation` (`doctrine.mapping_type: xml`, 1.9) | FR-ORM-003 |
| `Resources/config/doctrine-xml/BlogArticleResource.orm.xml` | XML mapping of `BlogArticleResource` (`doctrine.mapping_type: xml`, 1.9) | FR-ORM-003 |
| `Resources/config/doctrine-xml/BlogTag.orm.xml` | XML mapping of `BlogTag` (`doctrine.mapping_type: xml`, 1.9) | FR-ORM-003 |
| `Resources/config/doctrine-xml/BlogTagTranslation.orm.xml` | XML mapping of `BlogTagTranslation` (`doctrine.mapping_type: xml`, 1.9) | FR-ORM-003 |
| `Resources/config/doctrine-xml/BlogComment.orm.xml` | XML mapping of `BlogComment` (`doctrine.mapping_type: xml`, 1.9) | FR-ORM-003 |
| `Resources/config/doctrine-xml/BlogSettings.orm.xml` | XML mapping of `BlogSettings` (`doctrine.mapping_type: xml`, 1.9) | FR-ORM-003 |

## Co-located Vitest sources (`FR-TEST-*`)

Vitest specs live next to the TypeScript sources under `src/`; they are not loaded at runtime but are tracked under `src/`, so they are inventoried here.

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Resources/assets/src/blog-kit.test.ts` | Vitest: IIFE entry wiring | FR-TEST-001, FR-PUB-002 |
| `Resources/assets/src/blog-infinite-controller.test.ts` | Vitest: infinite scroll module | FR-TEST-001, FR-PUB-002 |
| `Resources/assets/src/blog-form-collection.test.ts` | Vitest: CollectionType add/remove | FR-TEST-001, FR-ADM-001 |
| `Resources/assets/src/blog-captcha.test.ts` | Vitest: reCAPTCHA v3 submit hook | FR-TEST-001, FR-CMT-004 |
| `Resources/assets/src/logger.test.ts` | Vitest: logger banner, quiet switch, debug gate | FR-TEST-001, FR-UI-005 |
| `Resources/assets/src/script-loaded.test.ts` | Vitest: the entry logs `script loaded` once, build time injected | FR-TEST-001, FR-UI-005 |
| `Resources/assets/src/vitest-setup.ts` | Vitest environment setup | FR-TEST-001 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle entry | 1 | 1 |
| DependencyInjection | 10 | 10 |
| Controllers | 9 | 9 |
| Entity & persistence | 17 | 17 |
| Domain model & enums | 17 | 17 |
| Services | 9 | 9 |
| HTTP, events, command | 7 | 7 |
| Forms | 15 | 15 |
| Security | 24 | 24 |
| Doctrine — entity manager and user references | 9 | 9 |
| Host ports — 1.9 framework-free twins | 14 | 14 |
| Worker boundaries | 2 | 2 |
| UiKit pages | 3 | 3 |
| Twig | 1 | 1 |
| Non-PHP production assets (documented) | 53 | 53 |
| Co-located Vitest sources (`FR-TEST-*`) | 7 | 7 |
| **Total production files (`git ls-files src`)** | **198** | **198** |
