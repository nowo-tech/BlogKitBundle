<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Nowo\BlogKitBundle\Entity\BlogArticleResource;
use Override;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractBlogFormType<BlogArticleResource>
 */
final class BlogArticleResourceType extends AbstractBlogFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addTextField('title', [
                'required' => false,
            ]);
            $this->addTextField('image', [
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 255),
                ],
            ]);
            $this->addIntegerField('position');
        });
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => BlogArticleResource::class,
        ]);
    }
}
