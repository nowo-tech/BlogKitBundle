<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Nowo\FormKitBundle\Form\AbstractGetFilterType;
use Override;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * GET filter for admin blog tags (/admin/blog/tags?slug=&name=).
 *
 * Profile: filter (via {@see AbstractGetFilterType}). CSRF: off.
 */
final class BlogTagFilterType extends AbstractGetFilterType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addNamedField('slug', 'search', [
                'required' => false,
                'help'     => false,
                'label'    => 'admin.filter.slug',
                'attr'     => ['class' => 'admin-list-filters__control'],
            ]);

            $this->addNamedField('name', 'search', [
                'required' => false,
                'help'     => false,
                'label'    => 'admin.filter.name',
                'attr'     => ['class' => 'admin-list-filters__control'],
            ]);
        });
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'admin_blog_tag_filter';
    }
}
