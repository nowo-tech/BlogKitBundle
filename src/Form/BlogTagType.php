<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Nowo\BlogKitBundle\Entity\BlogTag;
use Override;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Symfony form type for blog tag admin editing.
 *
 * @extends AbstractBlogFormType<BlogTag>
 */
final class BlogTagType extends AbstractBlogFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addTextField('slug', [
                'constraints' => [
                    new NotBlank(),
                    new Regex(
                        pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                        message: 'admin.validation.slug',
                    ),
                ],
            ]);
            $this->addTranslationsCollectionField(BlogTagTranslationType::class);
        });
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => BlogTag::class,
        ]);
    }
}
