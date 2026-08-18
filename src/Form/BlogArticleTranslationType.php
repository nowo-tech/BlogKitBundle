<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Override;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Symfony form type for a single blog article translation.
 *
 * @extends AbstractBlogFormType<BlogArticleTranslation>
 */
final class BlogArticleTranslationType extends AbstractBlogFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addHiddenLocaleField();
            $this->addTextField('title');
            $this->addTextField('metaTitle', [
                'required' => false,
            ]);
            $this->addTextareaField('metaDescription', [
                'required' => false,
                'attr'     => ['rows' => 3],
            ]);
            $this->addTextareaField('excerpt', [
                'required' => false,
                'attr'     => ['rows' => 3],
            ]);
            $this->addCkeditor5Field('body', [
                'config'     => 'simple',
                'theme'      => 'auto',
                'min_height' => '320px',
            ]);
        });
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => BlogArticleTranslation::class,
        ]);
    }
}
