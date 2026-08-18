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

/**
 * @extends AbstractBlogFormType<BlogSettings>
 */
final class BlogSettingsType extends AbstractBlogFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addChoiceField('listingMode', [
                'choices'                   => BlogListingMode::adminChoices(),
                'expanded'                  => true,
                'multiple'                  => false,
                'choice_translation_domain' => 'NowoBlogKitBundle',
            ]);
            $this->addIntegerField('perPage', [
                'constraints' => [new Assert\Range(min: 1, max: 24)],
            ]);
            $this->addChoiceField('masonryStrategy', [
                'choices'                   => BlogMasonryStrategy::adminChoices(),
                'expanded'                  => true,
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
            $this->addIntegerField('indexTagsLimit', [
                'constraints' => [new Assert\Range(min: 0, max: 100)],
            ]);

            $this->addChoiceField('indexAsideSearch', $this->placementFieldOptions());
            $this->addChoiceField('indexAsideLatest', $this->placementFieldOptions());
            $this->addChoiceField('indexAsideTags', $this->placementFieldOptions());
            $this->addIntegerField('indexLatestLimit', [
                'constraints' => [new Assert\Range(min: 1, max: 24)],
            ]);
            $this->addIntegerField('indexAsideTagsLimit', [
                'constraints' => [new Assert\Range(min: 0, max: 100)],
            ]);

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
                'expanded'                  => true,
                'multiple'                  => false,
                'choice_translation_domain' => 'NowoBlogKitBundle',
            ]);
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
        });
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => BlogSettings::class,
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
