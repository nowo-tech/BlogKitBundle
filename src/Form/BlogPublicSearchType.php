<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Override;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Public GET search form for /blog?q=&tag=.
 *
 * @extends AbstractBlogFormType<array{q?: string, tag?: string}>
 */
final class BlogPublicSearchType extends AbstractBlogFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addNamedField('q', 'search', [
                'required' => false,
                'label'    => 'page.blog.search_label',
                'attr'     => [
                    'class'        => 'blog-search__input',
                    'placeholder'  => 'page.blog.search_placeholder',
                    'autocomplete' => 'off',
                ],
            ]);
            $this->addNamedField('tag', 'hidden', [
                'required' => false,
            ]);
        });
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'translation_domain' => 'NowoBlogKitBundle',
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
