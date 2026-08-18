# Code inventory — BlogKitBundle baseline

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/blog-kit-bundle`  
**Last audited**: 2026-08-18  
**Coverage summary**: PHPUnit `src/` target 100% (`make test-coverage-100`)

Maps **100%** of production PHP files under `src/` (70 units). Test and demo trees are out of Packagist scope unless promoted in the spec.

## Bundle entry

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `NowoBlogKitBundle.php` | Bundle entry, Twig path pass, Doctrine mappings | FR-BUNDLE-001 |

## DependencyInjection

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/NowoBlogKitExtension.php` | DI extension, prepends, access checker | FR-CFG-002, FR-DI-001, FR-UI-002 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig override path order | FR-TWIG-001 |
| `DependencyInjection/TablePrefixListener.php` | Optional table prefix | FR-ORM-001 |

## Controllers

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Controller/BlogPublicController.php` | Public index and article | FR-PUB-001, FR-ART-003 |
| `Controller/PublicCommentController.php` | Public comment create and staff reply | FR-CMT-001, FR-CMT-002 |
| `Controller/Admin/BlogArticleController.php` | Article admin CRUD + inline modal | FR-ADM-001 |
| `Controller/Admin/BlogTagController.php` | Tag admin CRUD | FR-ADM-001, FR-TAG-001 |
| `Controller/Admin/BlogCommentController.php` | Comment moderation queue | FR-CMT-003 |
| `Controller/Admin/BlogSettingsController.php` | Settings singleton form | FR-SET-001 |
| `Controller/Admin/AdminListFilterTrait.php` | GET filter helper | FR-ADM-001 |
| `Controller/RequiresValidFormTrait.php` | CSRF form guard | FR-ADM-001 |

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
| `Repository/BlogSettingsRepository.php` | Settings singleton | FR-ORM-001, FR-SET-001 |
| `Repository/Concerns/JoinsTranslationsAndAuditUsers.php` | Shared joins | FR-ORM-001 |
| `Repository/Concerns/RunsDocumentedSql.php` | Documented SQL helper | FR-ORM-001 |

## Domain model & enums

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Enum/CssFramework.php` | Admin/public CSS stack | FR-UI-001 |
| `Enum/BlogListingMode.php` | Paginated vs infinite | FR-SET-001, FR-PUB-002 |
| `Enum/BlogAsidePlacement.php` | Aside left/right/none | FR-SET-001 |
| `Enum/BlogHeroImageMode.php` | Hero image fit | FR-SET-001 |
| `Model/BlogUserInterface.php` | Host user contract | FR-CFG-001 |
| `Model/BlameableUserTrait.php` | Created/updated by | FR-ART-001 |
| `Locale/BlogLocales.php` | Injected locale catalog (default + supported list) | FR-I18N-003 |
| `Meta/BlogBrandNameProviderInterface.php` | Optional title suffix | FR-PUB-001 |
| `Meta/BlogIndexMetaProviderInterface.php` | Optional index meta | FR-PUB-001 |

## Services

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Service/BlogCatalog.php` | Public catalogue facade | FR-ART-003, FR-PUB-001 |
| `Service/BlogSettingsProvider.php` | Settings access | FR-SET-001 |
| `Service/BlogCommentManager.php` | Comment lifecycle | FR-CMT-001, FR-CMT-002, FR-CMT-003 |
| `Service/BlogHashtagProcessor.php` | Hashtag parse/format | FR-TAG-002 |
| `Service/BlogTagRegistry.php` | Tag lookup/create | FR-TAG-001 |
| `Service/BlogArticleBodyEnhancer.php` | Body post-processing | FR-ART-001 |
| `Service/BlogPostBodyFormatter.php` | Body formatting | FR-ART-001 |
| `Service/BlogLocalesLocaleResolver.php` | Request locale vs config | FR-I18N-003 |

## HTTP, events, command

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Event/BlogArticlePublishedEvent.php` | Published domain event | FR-ART-002 |
| `EventSubscriber/BlogArticlePublishedDoctrineSubscriber.php` | onFlush/postFlush dispatch | FR-ART-002 |
| `EventSubscriber/BlogKitAdminAccessSubscriber.php` | Admin route guard | FR-UI-002 |
| `Command/SyncBlogHashtagsCommand.php` | CLI hashtag sync | FR-TAG-002 |

## Forms

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Form/AbstractBlogFormType.php` | Shared FormKit type | FR-ADM-001 |
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
| `Security/ConfigurableBlogKitAccessChecker.php` | Default role-based checker | FR-UI-002 |
| `Security/AllowAllBlogKitAccessChecker.php` | Demo allow-all checker | FR-UI-002 |

## Twig

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Twig/BlogKitExtension.php` | Layout and access globals | FR-UI-001, FR-UI-002 |

## Non-PHP production assets (documented)

| Path | Purpose | Requirement IDs |
| --- | --- | --- |
| `Resources/views/**/*.twig` | Admin and public templates | FR-PUB-001, FR-ADM-001, FR-TWIG-001 |
| `Resources/translations/NowoBlogKitBundle.*.yaml` | Locales en/es/it/fr/pt/de/nl | FR-I18N-002, FR-I18N-003 |
| `Resources/public/blog.css` | Public styles | FR-PUB-001 |
| `Resources/public/blog-infinite-controller.js` | Infinite scroll | FR-PUB-002 |
| `Resources/assets/src/blog-infinite-controller.ts` | Stimulus source | FR-PUB-002 |
| `Resources/config/services.yaml` | Service autowire | FR-CFG-002 |
| `Resources/config/routing.yaml` | Attribute controller import | FR-BUNDLE-001 |
