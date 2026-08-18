<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Nowo\BlogKitBundle\Entity\BlogArticleTranslation;
use Override;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Inline modal translation form — reuses blog_article_translation.* keys.
 *
 * @extends AbstractBlogFormType<BlogArticleTranslation>
 */
final class BlogArticleInlineTranslationType extends AbstractBlogFormType
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
    public function getBlockPrefix(): string
    {
        return 'blog_article_translation';
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
