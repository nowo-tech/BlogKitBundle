<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Nowo\BlogKitBundle\Entity\BlogSettings;
use Nowo\BlogKitBundle\Enum\BlogAsidePlacement;
use Nowo\BlogKitBundle\Enum\BlogHeroImageMode;
use Nowo\BlogKitBundle\Enum\BlogListingMode;
use Nowo\BlogKitBundle\Enum\BlogMasonryStrategy;
use Nowo\BlogKitBundle\Enum\CommentCaptchaStrategy;
use Nowo\BlogKitBundle\Enum\CommentRateLimitStrategy;
use Nowo\BlogKitBundle\Enum\HtmlSanitizeStrategy;
use Override;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

use function assert;
use function in_array;

/**
 * Blog settings form. Pass {@see self::OPTION_SECTION} for one admin tab, or omit for the full form.
 *
 * @extends AbstractBlogFormType<BlogSettings>
 */
final class BlogSettingsType extends AbstractBlogFormType
{
    public const string OPTION_SECTION = 'section';

    public const string SECTION_LISTING = 'listing';

    public const string SECTION_CARDS = 'cards';

    public const string SECTION_INDEX_ASIDE = 'index-aside';

    public const string SECTION_ARTICLE = 'article';

    public const string SECTION_COMMENTS = 'comments';

    /** @var list<string> */
    public const array SECTIONS = [
        self::SECTION_LISTING,
        self::SECTION_CARDS,
        self::SECTION_INDEX_ASIDE,
        self::SECTION_ARTICLE,
        self::SECTION_COMMENTS,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $section = $options[self::OPTION_SECTION] ?? null;

        $this->withBuilder($builder, function () use ($section): void {
            if ($section === null) {
                $this->addListingFields();
                $this->addCardsFields();
                $this->addIndexAsideFields();
                $this->addArticleFields();
                $this->addCommentsFields();

                return;
            }

            assert(in_array($section, self::SECTIONS, true));

            match ($section) {
                self::SECTION_LISTING     => $this->addListingFields(),
                self::SECTION_CARDS       => $this->addCardsFields(),
                self::SECTION_INDEX_ASIDE => $this->addIndexAsideFields(),
                self::SECTION_ARTICLE     => $this->addArticleFields(),
                self::SECTION_COMMENTS    => $this->addCommentsFields(),
            };
        });
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class'         => BlogSettings::class,
            self::OPTION_SECTION => null,
        ]);

        $resolver->setAllowedTypes(self::OPTION_SECTION, ['null', 'string']);
        $resolver->setAllowedValues(self::OPTION_SECTION, [null, ...self::SECTIONS]);
    }

    private function addListingFields(): void
    {
        $this->addChoiceField('listingMode', [
            'choices'                   => BlogListingMode::adminChoices(),
            'expanded'                  => false,
            'multiple'                  => false,
            'choice_translation_domain' => 'NowoBlogKitBundle',
        ]);
        $this->addIntegerField('perPage', [
            'constraints' => [new Assert\Range(min: 1, max: 24)],
        ]);
        $this->addIntegerField('indexTagsLimit', [
            'constraints' => [new Assert\Range(min: 0, max: 100)],
        ]);
    }

    private function addCardsFields(): void
    {
        $this->addChoiceField('masonryStrategy', [
            'choices'                   => BlogMasonryStrategy::adminChoices(),
            'expanded'                  => false,
            'multiple'                  => false,
            'choice_translation_domain' => 'NowoBlogKitBundle',
        ]);
        $this->addIntegerField('masonryColumnsMobile', [
            'constraints' => [new Assert\Range(min: 0, max: 2)],
        ]);
        $this->addIntegerField('masonryColumnsTablet', [
            'constraints' => [new Assert\Range(min: 0, max: 2)],
        ]);
        $this->addIntegerField('masonryColumnsDesktop', [
            'constraints' => [new Assert\Range(min: 0, max: 3)],
        ]);
        $this->addCheckboxField('showCardImage', ['required' => false]);
        $this->addCheckboxField('showCardExcerpt', ['required' => false]);
        $this->addCheckboxField('showCardTags', ['required' => false]);
    }

    private function addIndexAsideFields(): void
    {
        $this->addChoiceField('indexAsideSearch', $this->placementFieldOptions());
        $this->addChoiceField('indexAsideLatest', $this->placementFieldOptions());
        $this->addChoiceField('indexAsideTags', $this->placementFieldOptions());
        $this->addIntegerField('indexLatestLimit', [
            'constraints' => [new Assert\Range(min: 1, max: 24)],
        ]);
        $this->addIntegerField('indexAsideTagsLimit', [
            'constraints' => [new Assert\Range(min: 0, max: 100)],
        ]);
    }

    private function addArticleFields(): void
    {
        $this->addChoiceField('showAsideSearch', $this->placementFieldOptions());
        $this->addChoiceField('showAsideRelated', $this->placementFieldOptions());
        $this->addChoiceField('showAsideArticleTags', $this->placementFieldOptions());
        $this->addChoiceField('showAsideResources', $this->placementFieldOptions());
        $this->addIntegerField('relatedLimit', [
            'constraints' => [new Assert\Range(min: 1, max: 24)],
        ]);
        $this->addCheckboxField('resourcesIncludeLinkedin', ['required' => false]);
        $this->addCheckboxField('showShare', ['required' => false]);
        $this->addCheckboxField('showComments', ['required' => false]);
        $this->addCheckboxField('showSourceLink', ['required' => false]);
        $this->addChoiceField('heroImageMode', [
            'choices'                   => $this->heroModeChoices(),
            'expanded'                  => false,
            'multiple'                  => false,
            'choice_translation_domain' => 'NowoBlogKitBundle',
        ]);
    }

    private function addCommentsFields(): void
    {
        $this->addChoiceField('commentRateLimitStrategy', [
            'choices'                   => CommentRateLimitStrategy::adminChoices(),
            'choice_translation_domain' => 'NowoBlogKitBundle',
        ]);
        $this->addIntegerField('commentRateLimitLimit', [
            'constraints' => [new Assert\Range(min: 0, max: 1000)],
            'required'    => false,
        ]);
        $this->addIntegerField('commentRateLimitIntervalSeconds', [
            'constraints' => [new Assert\Range(min: 0, max: 86400)],
            'required'    => false,
        ]);
        $this->addChoiceField('commentCaptchaStrategy', [
            'choices'                   => CommentCaptchaStrategy::adminChoices(),
            'choice_translation_domain' => 'NowoBlogKitBundle',
        ]);
        $this->addChoiceField('htmlSanitizeStrategy', [
            'choices'                   => HtmlSanitizeStrategy::adminChoices(),
            'choice_translation_domain' => 'NowoBlogKitBundle',
        ]);
    }

    /** @return array<string, string> */
    private function heroModeChoices(): array
    {
        $choices = [];

        foreach (BlogHeroImageMode::cases() as $mode) {
            $choices[$mode->labelKey()] = $mode->value;
        }

        return $choices;
    }

    /** @return array<string, mixed> */
    private function placementFieldOptions(): array
    {
        $choices = [];

        foreach (BlogAsidePlacement::cases() as $placement) {
            $choices[$placement->labelKey()] = $placement->value;
        }

        return [
            'choices'                   => $choices,
            'choice_translation_domain' => 'NowoBlogKitBundle',
        ];
    }
}
