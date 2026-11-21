<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\FormKitBundle\Form\FormTypeMap;
use Override;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Url;

use function array_filter;
use function array_map;
use function array_values;
use function is_array;
use function is_iterable;
use function is_numeric;

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
        private readonly ?BlogTagRepository $blogTagRepository = null,
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
            // Since 1.12.1: the tag choices are tag ids read when the form is built, and the selected ids become
            // tags of the current request's manager on submit (worker mode with the kernel kept alive: no tag entity
            // is held by the shared choice-list cache, so none is detached when the manager is cleared or dropped).
            $this->addChoiceField('tags', [
                'choices'                   => $this->tagChoices($tagLabel),
                'choice_translation_domain' => false,
                'multiple'                  => true,
                'expanded'                  => false,
                'required'                  => false,
                'by_reference'              => false,
                'attr'                      => [
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

        $builder->get('tags')->addModelTransformer(new CallbackTransformer(
            $this->tagIds(...),
            $this->tagsOf(...),
        ));
    }

    /**
     * Tag choices as label => id, read through the blog's tag repository each time the form is built.
     *
     * @param callable(BlogTag): string $tagLabel
     *
     * @return array<string, int>
     */
    private function tagChoices(callable $tagLabel): array
    {
        $choices = [];
        foreach ($this->blogTagRepository?->findAllOrdered() ?? [] as $blogTag) {
            $choices[$tagLabel($blogTag)] = (int) $blogTag->getId();
        }

        return $choices;
    }

    /**
     * Model to choice data: the article's tags as ids.
     *
     * @return list<int>
     */
    private function tagIds(mixed $tags): array
    {
        $ids = [];
        foreach (is_iterable($tags) ? $tags : [] as $blogTag) {
            if ($blogTag instanceof BlogTag && $blogTag->getId() !== null) {
                $ids[] = $blogTag->getId();
            }
        }

        return $ids;
    }

    /**
     * Choice data to model: the selected ids as tags of the current request's manager.
     *
     * @return list<BlogTag>
     */
    private function tagsOf(mixed $ids): array
    {
        $ids = array_values(array_filter(is_array($ids) ? $ids : [], is_numeric(...)));
        if ($ids === [] || !$this->blogTagRepository instanceof BlogTagRepository) {
            return [];
        }

        return $this->blogTagRepository->findBy(['id' => array_map(static fn (int|float|string $id): int => (int) $id, $ids)]);
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
