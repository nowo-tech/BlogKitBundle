<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Form;

use Doctrine\ORM\QueryBuilder;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogArticleResource;
use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Entity\BlogTagTranslation;
use Nowo\BlogKitBundle\Form\BlogArticleFilterType;
use Nowo\BlogKitBundle\Form\BlogArticleInlineTranslationType;
use Nowo\BlogKitBundle\Form\BlogArticleResourceType;
use Nowo\BlogKitBundle\Form\BlogArticleTranslationType;
use Nowo\BlogKitBundle\Form\BlogArticleType;
use Nowo\BlogKitBundle\Form\BlogCommentFilterType;
use Nowo\BlogKitBundle\Form\BlogInlineModalType;
use Nowo\BlogKitBundle\Form\BlogPublicSearchType;
use Nowo\BlogKitBundle\Form\BlogSettingsType;
use Nowo\BlogKitBundle\Form\BlogTagFilterType;
use Nowo\BlogKitBundle\Form\BlogTagTranslationType;
use Nowo\BlogKitBundle\Form\BlogTagType;
use Nowo\BlogKitBundle\Form\PublicBlogCommentType;
use Nowo\BlogKitBundle\Form\StaffBlogCommentReplyType;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\BlogKitBundle\Tests\Support\FormKitTestSupport;
use Nowo\BlogKitBundle\Tests\Support\LocaleTestSupport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Validation;

final class BlogFormTypesTest extends TestCase
{
    protected function setUp(): void
    {
        LocaleTestSupport::bindDefaults();
    }

    #[Test]
    public function itBuildsAndSubmitsBlogSettingsType(): void
    {
        $type     = FormKitTestSupport::createType(BlogSettingsType::class);
        $settings = new BlogSettings();
        $form     = $this->createForm($type, $settings);

        $form->submit([
            'listingMode'                     => 'infinite',
            'perPage'                         => '12',
            'masonryStrategy'                 => 'grid',
            'masonryColumnsMobile'            => '1',
            'masonryColumnsTablet'            => '2',
            'masonryColumnsDesktop'           => '3',
            'showCardImage'                   => true,
            'showCardExcerpt'                 => false,
            'showCardTags'                    => true,
            'indexTagsLimit'                  => '15',
            'indexAsideSearch'                => 'right',
            'indexAsideLatest'                => 'left',
            'indexAsideTags'                  => 'both',
            'indexLatestLimit'                => '8',
            'indexAsideTagsLimit'             => '10',
            'showAsideSearch'                 => 'left',
            'showAsideRelated'                => 'right',
            'showAsideArticleTags'            => 'off',
            'showAsideResources'              => 'both',
            'relatedLimit'                    => '4',
            'resourcesIncludeLinkedin'        => false,
            'showShare'                       => true,
            'showComments'                    => false,
            'showSourceLink'                  => true,
            'heroImageMode'                   => 'cover',
            'commentRateLimitStrategy'        => 'fixed_window',
            'commentRateLimitLimit'           => '8',
            'commentRateLimitIntervalSeconds' => '120',
            'commentCaptchaStrategy'          => 'honeypot',
            'htmlSanitizeStrategy'            => 'allowlist',
        ]);

        $options = $this->resolvedOptions($type);

        self::assertSame(BlogSettings::class, $options['data_class']);
        self::assertSame('NowoBlogKitBundle', $options['translation_domain']);
        self::assertTrue($form->has('listingMode'));
        self::assertTrue($form->has('masonryStrategy'));
        self::assertTrue($form->has('heroImageMode'));
        self::assertFalse($form->get('listingMode')->getConfig()->getOption('expanded'));
        self::assertFalse($form->get('masonryStrategy')->getConfig()->getOption('expanded'));
        self::assertFalse($form->get('heroImageMode')->getConfig()->getOption('expanded'));
        self::assertNull($options[BlogSettingsType::OPTION_SECTION] ?? null);
        self::assertSame('infinite', $settings->getListingMode());
        self::assertSame(12, $settings->getPerPage());
        self::assertSame('grid', $settings->getMasonryStrategy());
        self::assertSame(3, $settings->getMasonryColumnsDesktop());
        self::assertFalse($settings->isShowCardExcerpt());
        self::assertFalse($settings->isShowComments());
        self::assertSame('cover', $settings->getHeroImageMode());
        self::assertSame('fixed_window', $settings->getCommentRateLimitStrategy());
        self::assertSame(8, $settings->getCommentRateLimitLimit());
        self::assertSame('honeypot', $settings->getCommentCaptchaStrategy());
        self::assertSame('allowlist', $settings->getHtmlSanitizeStrategy());

        $perPageConstraints = $form->get('perPage')->getConfig()->getOption('constraints');
        self::assertCount(1, $perPageConstraints);
        self::assertInstanceOf(Range::class, $perPageConstraints[0]);
    }

    #[Test]
    public function itBuildsBlogSettingsTypeForSingleSection(): void
    {
        foreach (BlogSettingsType::SECTIONS as $section) {
            $type = FormKitTestSupport::createType(BlogSettingsType::class);
            $form = $this->createForm($type, new BlogSettings(), [], [
                BlogSettingsType::OPTION_SECTION => $section,
            ]);

            self::assertSame($section, $form->getConfig()->getOption(BlogSettingsType::OPTION_SECTION));

            match ($section) {
                BlogSettingsType::SECTION_LISTING     => self::assertTrue($form->has('listingMode') && !$form->has('masonryStrategy')),
                BlogSettingsType::SECTION_CARDS       => self::assertTrue($form->has('masonryStrategy') && !$form->has('listingMode')),
                BlogSettingsType::SECTION_INDEX_ASIDE => self::assertTrue($form->has('indexAsideSearch') && !$form->has('listingMode')),
                BlogSettingsType::SECTION_ARTICLE     => self::assertTrue($form->has('heroImageMode') && !$form->has('listingMode')),
                BlogSettingsType::SECTION_COMMENTS    => self::assertTrue($form->has('htmlSanitizeStrategy') && !$form->has('listingMode')),
            };
        }

        $type = FormKitTestSupport::createType(BlogSettingsType::class);
        $form = $this->createForm($type, new BlogSettings(), [], [
            BlogSettingsType::OPTION_SECTION => BlogSettingsType::SECTION_CARDS,
        ]);
        self::assertFalse($form->get('masonryStrategy')->getConfig()->getOption('expanded'));
    }

    #[Test]
    public function itBuildsBlogArticleTypeWithExpectedFieldsAndOptions(): void
    {
        $type     = FormKitTestSupport::createType(BlogArticleType::class);
        $captured = [];
        $builder  = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(static function (string $name, string $fieldType, array $options) use (&$captured, $builder): FormBuilderInterface {
            $captured[$name] = [$fieldType, $options];

            return $builder;
        });

        $type->buildForm($builder, []);
        $options = $this->resolvedOptions($type);

        self::assertSame(BlogArticle::class, $options['data_class']);
        self::assertSame(
            ['slug', 'image', 'linkedinUrl', 'publishedAt', 'position', 'published', 'tags', 'resources', 'translations'],
            array_keys($captured),
        );
        self::assertSame(DateType::class, $captured['publishedAt'][0]);
        self::assertSame(EntityType::class, $captured['tags'][0]);
        self::assertSame(CollectionType::class, $captured['resources'][0]);
        self::assertSame(CollectionType::class, $captured['translations'][0]);
        self::assertSame(BlogArticleResourceType::class, $captured['resources'][1]['entry_type']);
        self::assertSame(BlogArticleTranslationType::class, $captured['translations'][1]['entry_type']);
        self::assertFalse($captured['published'][1]['required']);
        self::assertSame('single_text', $captured['publishedAt'][1]['widget']);
        self::assertSame(8, $captured['tags'][1]['attr']['size']);

        $tag = (new BlogTag())->setSlug('php');
        $tag->ensureTranslations();
        $tag->getTranslationOrFallback('es')->setName('PHP');

        $choiceLabel = $captured['tags'][1]['choice_label'];
        self::assertIsCallable($choiceLabel);
        self::assertSame('PHP (php)', $choiceLabel($tag));

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('leftJoin')->willReturnSelf();
        $queryBuilder->method('addSelect')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();

        $tagRepository = $this->getMockBuilder(BlogTagRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder'])
            ->getMock();
        $tagRepository->expects(self::once())
            ->method('createQueryBuilder')
            ->with('t')
            ->willReturn($queryBuilder);

        $queryBuilderFactory = $captured['tags'][1]['query_builder'];
        self::assertSame($queryBuilder, $queryBuilderFactory($tagRepository));
    }

    #[Test]
    public function itBuildsAndSubmitsBlogArticleTranslationType(): void
    {
        $type        = FormKitTestSupport::createType(BlogArticleTranslationType::class);
        $translation = new BlogArticleTranslation();
        $form        = $this->createForm($type, $translation);

        $form->submit([
            'locale'          => 'en',
            'title'           => 'Article title',
            'metaTitle'       => 'SEO title',
            'metaDescription' => 'SEO description',
            'excerpt'         => 'Short excerpt',
            'body'            => 'Article body',
        ]);

        $options = $this->resolvedOptions($type);

        self::assertSame(BlogArticleTranslation::class, $options['data_class']);
        self::assertSame('en', $translation->getLocale());
        self::assertSame('Article title', $translation->getTitle());
        self::assertSame('Article body', $translation->getBody());
        self::assertSame(3, $form->get('metaDescription')->createView()->vars['attr']['rows']);
        self::assertSame(12, $form->get('body')->createView()->vars['attr']['rows']);
    }

    #[Test]
    public function itBuildsInlineTranslationTypeAndUsesSharedBlockPrefix(): void
    {
        $type        = FormKitTestSupport::createType(BlogArticleInlineTranslationType::class);
        $translation = new BlogArticleTranslation();
        $form        = $this->createForm($type, $translation);

        $form->submit([
            'locale'          => 'es',
            'title'           => 'Inline title',
            'metaTitle'       => 'Inline meta title',
            'metaDescription' => 'Inline meta description',
            'excerpt'         => 'Inline excerpt',
            'body'            => 'Inline body',
        ]);

        self::assertSame('blog_article_translation', $type->getBlockPrefix());
        self::assertSame('Inline title', $translation->getTitle());
        self::assertSame(BlogArticleTranslation::class, $this->resolvedOptions($type)['data_class']);
    }

    #[Test]
    public function itBuildsAndSubmitsBlogArticleResourceType(): void
    {
        $type     = FormKitTestSupport::createType(BlogArticleResourceType::class);
        $resource = new BlogArticleResource();
        $form     = $this->createForm($type, $resource);

        $form->submit([
            'title'    => ' Resource title ',
            'image'    => ' /resource.png ',
            'position' => '5',
        ]);

        $constraints = $form->get('image')->getConfig()->getOption('constraints');

        self::assertSame(BlogArticleResource::class, $this->resolvedOptions($type)['data_class']);
        self::assertSame('Resource title', $resource->getTitle());
        self::assertSame('/resource.png', $resource->getImage());
        self::assertSame(5, $resource->getPosition());
        self::assertInstanceOf(NotBlank::class, $constraints[0]);
        self::assertInstanceOf(Length::class, $constraints[1]);
    }

    #[Test]
    public function itBuildsInlineModalTypeWithTranslationCollection(): void
    {
        $type       = FormKitTestSupport::createType(BlogInlineModalType::class);
        $nestedType = FormKitTestSupport::createType(BlogArticleInlineTranslationType::class);
        $form       = $this->createForm($type, new BlogArticle(), [$nestedType]);

        $translationsConfig = $form->get('translations')->getConfig();

        self::assertSame(BlogArticle::class, $this->resolvedOptions($type)['data_class']);
        self::assertSame(BlogArticleInlineTranslationType::class, $translationsConfig->getOption('entry_type'));
        self::assertFalse($translationsConfig->getOption('allow_add'));
        self::assertFalse($translationsConfig->getOption('allow_delete'));
    }

    #[Test]
    public function itBuildsBlogTagTypeWithSlugConstraintsAndTranslations(): void
    {
        $type       = FormKitTestSupport::createType(BlogTagType::class);
        $nestedType = FormKitTestSupport::createType(BlogTagTranslationType::class);
        $form       = $this->createForm($type, new BlogTag(), [$nestedType]);

        $slugConstraints    = $form->get('slug')->getConfig()->getOption('constraints');
        $translationsConfig = $form->get('translations')->getConfig();

        self::assertSame(BlogTag::class, $this->resolvedOptions($type)['data_class']);
        self::assertInstanceOf(NotBlank::class, $slugConstraints[0]);
        self::assertInstanceOf(Regex::class, $slugConstraints[1]);
        self::assertSame(BlogTagTranslationType::class, $translationsConfig->getOption('entry_type'));
    }

    #[Test]
    public function itBuildsAndSubmitsBlogTagTranslationType(): void
    {
        $type        = FormKitTestSupport::createType(BlogTagTranslationType::class);
        $translation = new BlogTagTranslation();
        $form        = $this->createForm($type, $translation);

        $form->submit([
            'locale' => 'en',
            'name'   => 'PHP',
        ]);

        self::assertSame(BlogTagTranslation::class, $this->resolvedOptions($type)['data_class']);
        self::assertSame('en', $translation->getLocale());
        self::assertSame('PHP', $translation->getName());
    }

    #[Test]
    public function itBuildsPublicSearchTypeWithEmptyBlockPrefix(): void
    {
        $type = FormKitTestSupport::createType(BlogPublicSearchType::class);
        $form = $this->createForm($type);

        $form->submit([
            'q'   => 'symfony',
            'tag' => 'php',
        ]);

        $options = $this->resolvedOptions($type);

        self::assertSame('', $type->getBlockPrefix());
        self::assertSame('GET', $options['method']);
        self::assertFalse($options['csrf_protection']);
        self::assertSame('page.blog.search_placeholder', $form->get('q')->createView()->vars['attr']['placeholder']);
        self::assertSame('php', $form->get('tag')->getData());
    }

    #[Test]
    public function itBuildsPublicCommentTypeWithPrivacyCheckbox(): void
    {
        $type = FormKitTestSupport::createType(PublicBlogCommentType::class);
        $form = $this->createForm($type);

        $form->submit([
            'authorName'      => 'Ana',
            'authorEmail'     => 'ana@example.test',
            'body'            => 'Public comment body',
            'privacyAccepted' => true,
        ]);

        $privacyConfig = $form->get('privacyAccepted')->getConfig();
        $bodyConfig    = $form->get('body')->getConfig();

        self::assertFalse($privacyConfig->getOption('mapped'));
        self::assertTrue($privacyConfig->getOption('required'));
        self::assertInstanceOf(IsTrue::class, $privacyConfig->getOption('constraints')[0]);
        self::assertInstanceOf(Email::class, $form->get('authorEmail')->getConfig()->getOption('constraints')[1]);
        self::assertInstanceOf(Length::class, $bodyConfig->getOption('constraints')[1]);
    }

    #[Test]
    public function itBuildsStaffReplyTypeAndAllowsExtraFields(): void
    {
        $type = FormKitTestSupport::createType(StaffBlogCommentReplyType::class);
        $form = $this->createForm($type, null, [], ['allow_extra_fields' => true]);

        $form->submit([
            'body'  => 'Thanks for your comment.',
            'token' => 'extra-field',
        ]);

        $options = $this->resolvedOptions($type);

        self::assertTrue($options['allow_extra_fields']);
        self::assertSame(4, $form->get('body')->createView()->vars['attr']['rows']);
        self::assertInstanceOf(NotBlank::class, $form->get('body')->getConfig()->getOption('constraints')[0]);
    }

    #[Test]
    public function itBuildsBlogArticleFilterTypeWithFilterProfileDefaults(): void
    {
        $type    = FormKitTestSupport::createType(BlogArticleFilterType::class);
        $form    = $this->createForm($type);
        $options = $this->resolvedOptions($type);

        self::assertSame('admin_blog_article_filter', $type->getBlockPrefix());
        self::assertSame('GET', $options['method']);
        self::assertFalse($options['csrf_protection']);
        self::assertSame('admin_blog_article_filter.title.placeholder', $form->get('title')->createView()->vars['attr']['placeholder']);
        self::assertSame('admin_blog_article_filter.slug.placeholder', $form->get('slug')->createView()->vars['attr']['placeholder']);
        self::assertSame('admin_blog_article_filter.published.placeholder', $form->get('published')->getConfig()->getOption('placeholder'));
        self::assertArrayNotHasKey('placeholder', $form->get('published')->createView()->vars['attr']);
    }

    #[Test]
    public function itBuildsBlogCommentFilterTypeWithHiddenStatusField(): void
    {
        $type = FormKitTestSupport::createType(BlogCommentFilterType::class);
        $form = $this->createForm($type);

        self::assertSame('admin_blog_comment_filter', $type->getBlockPrefix());
        self::assertSame('admin_blog_comment_filter.author.placeholder', $form->get('author')->createView()->vars['attr']['placeholder']);
        self::assertSame('admin_blog_comment_filter.article.placeholder', $form->get('article')->createView()->vars['attr']['placeholder']);
        self::assertSame('admin_blog_comment_filter.body.placeholder', $form->get('body')->createView()->vars['attr']['placeholder']);
        self::assertArrayNotHasKey('placeholder', $form->get('status')->createView()->vars['attr']);
    }

    #[Test]
    public function itBuildsBlogTagFilterTypeWithFilterProfileDefaults(): void
    {
        $type    = FormKitTestSupport::createType(BlogTagFilterType::class);
        $form    = $this->createForm($type);
        $options = $this->resolvedOptions($type);

        self::assertSame('admin_blog_tag_filter', $type->getBlockPrefix());
        self::assertSame('GET', $options['method']);
        self::assertFalse($options['csrf_protection']);
        self::assertSame('admin_blog_tag_filter.slug.placeholder', $form->get('slug')->createView()->vars['attr']['placeholder']);
        self::assertSame('admin_blog_tag_filter.name.placeholder', $form->get('name')->createView()->vars['attr']['placeholder']);
    }

    /** @param list<object> $extraTypes */
    private function createForm(object $type, mixed $data = null, array $extraTypes = [], array $options = []): FormInterface
    {
        $factory = $this->createFactory([$type, ...$extraTypes]);

        return $factory->create($type::class, $data, ['csrf_protection' => false, ...$options]);
    }

    /** @param list<object> $types */
    private function createFactory(array $types): FormFactoryInterface
    {
        $csrfManager = new CsrfTokenManager();

        return Forms::createFormFactoryBuilder()
            ->addExtension(new CsrfExtension($csrfManager))
            ->addExtension(new ValidatorExtension(
                Validation::createValidator(),
            ))
            ->addExtension(new PreloadedExtension($types, []))
            ->getFormFactory();
    }

    /** @return array<string, mixed> */
    private function resolvedOptions(object $type): array
    {
        $resolver = new OptionsResolver();
        $type->configureOptions($resolver);

        return $resolver->resolve();
    }
}
