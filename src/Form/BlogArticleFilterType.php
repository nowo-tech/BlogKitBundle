<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Nowo\FormKitBundle\Form\AbstractGetFilterType;
use Override;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * GET filter for admin blog articles (/admin/blog?title=&slug=&published=).
 *
 * Profile: filter (via {@see AbstractGetFilterType}). CSRF: off.
 */
final class BlogArticleFilterType extends AbstractGetFilterType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addNamedField('title', 'search', [
                'required' => false,
                'help'     => false,
                'attr'     => ['class' => 'admin-list-filters__control'],
            ]);

            $this->addNamedField('slug', 'search', [
                'required' => false,
                'help'     => false,
                'attr'     => ['class' => 'admin-list-filters__control'],
            ]);

            $this->addFilterSelect('published', [
                'required' => false,
                'help'     => false,
                'choices'  => [
                    'admin.filter.published_yes' => '1',
                    'admin.filter.published_no'  => '0',
                ],
                'choice_translation_domain' => 'NowoBlogKitBundle',
                'attr'                      => ['class' => 'admin-list-filters__control'],
            ]);
        });
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'admin_blog_article_filter';
    }
}
