<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Nowo\BlogKitBundle\Entity\BlogTagTranslation;
use Override;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Symfony form type for a single blog tag translation.
 *
 * @extends AbstractBlogFormType<BlogTagTranslation>
 */
final class BlogTagTranslationType extends AbstractBlogFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addHiddenLocaleField();
            $this->addTextField('name');
        });
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => BlogTagTranslation::class,
        ]);
    }
}
