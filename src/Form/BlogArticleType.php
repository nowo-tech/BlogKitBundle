<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Doctrine\ORM\QueryBuilder;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\FormKitBundle\Form\FormTypeMap;
use Override;
use SortDirection;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Url;

/**
 * Symfony form type for blog article admin editing.
 *
 * @extends AbstractBlogFormType<BlogArticle>
 */
final class BlogArticleType extends AbstractBlogFormType
{
    public function __construct(
        FormOptionsMerger $formOptionsMerger,
        FormTypeMap $formTypeMap,
        private readonly ?BlogLocales $blogLocales = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
        parent::__construct($formOptionsMerger, $formTypeMap, $blogLocales);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $tagLabel = $this->tagChoiceLabel();

        $this->withBuilder($builder, function () use ($tagLabel): void {
            // The slug is part of the public URL (/blog/{slug}, [a-z0-9-]+): same rule as the tag slug (SEC-004 I4).
            $this->addTextField('slug', [
                'constraints' => [
                    new NotBlank(),
                    new Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'admin.validation.slug'),
                ],
            ]);
            $this->addTextField('image', [
                'required' => false,
            ]);
            $this->addUrlField('linkedinUrl', [
                'required' => false,
                // http(s) only: the URL is printed in an href on the public article (SEC-004 L1, no javascript:)
                'constraints' => [new Url(protocols: ['http', 'https'], requireTld: true)],
            ]);
            $this->addTypedField('publishedAt', DateType::class, [
                'required' => false,
                'widget'   => 'single_text',
            ]);
            $this->addIntegerField('position');
            $this->addCheckboxField('published', [
                'required' => false,
            ]);
            $this->addTypedField('tags', EntityType::class, [
                'class'         => BlogTag::class,
                'choice_label'  => $tagLabel,
                'query_builder' => static fn (BlogTagRepository $blogTagRepository): QueryBuilder => $blogTagRepository->createQueryBuilder('t')
                    ->leftJoin('t.translations', 'tt')
                    ->addSelect('tt')
                    ->orderBy('t.slug', SortDirection::Ascending),
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'attr'     => [
                    'class' => 'admin-form-multiselect',
                    'size'  => 8,
                ],
            ]);
            $this->addTypedField('resources', CollectionType::class, [
                'entry_type'    => BlogArticleResourceType::class,
                'allow_add'     => true,
                'allow_delete'  => true,
                'by_reference'  => false,
                'required'      => false,
                'prototype'     => true,
                'entry_options' => [
                    'label' => false,
                ],
            ]);
            $this->addTranslationsCollectionField(BlogArticleTranslationType::class);
        });
    }

    /**
     * Tag choice labels follow the current request locale (when it is a configured blog locale) and
     * fall back to nowo_blog_kit.default_locale, then to the tag's first translation.
     *
     * @return callable(BlogTag): string
     */
    private function tagChoiceLabel(): callable
    {
        $default = $this->blogLocales?->getDefault();
        $locale  = $this->requestStack?->getCurrentRequest()?->getLocale();
        if ($locale === null || !$this->blogLocales instanceof BlogLocales || !$this->blogLocales->isSupported($locale)) {
            $locale = $default;
        }

        return static function (BlogTag $blogTag) use ($locale, $default): string {
            $name = $locale === null ? $blogTag->getName() : $blogTag->getTranslationOrFallback($locale, $default)->getName();

            return $name . ' (' . $blogTag->getSlug() . ')';
        };
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => BlogArticle::class,
        ]);
    }
}
