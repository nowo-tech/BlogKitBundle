<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Nowo\FormKitBundle\Form\AbstractGetFilterType;
use Override;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * GET filter for admin blog comments (/admin/blog/comments?author=&article=&body=&status=).
 *
 * Status is a hidden preserve field for tab navigation; text filters are visible.
 * Profile: filter (via {@see AbstractGetFilterType}). CSRF: off.
 */
final class BlogCommentFilterType extends AbstractGetFilterType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addNamedField('author', 'search', [
                'required' => false,
                'help'     => false,
                'label'    => 'admin.filter.author',
                'attr'     => ['class' => 'admin-list-filters__control'],
            ]);

            $this->addNamedField('article', 'search', [
                'required' => false,
                'help'     => false,
                'label'    => 'admin.filter.article',
                'attr'     => ['class' => 'admin-list-filters__control'],
            ]);

            $this->addNamedField('body', 'search', [
                'required' => false,
                'help'     => false,
                'label'    => 'admin.filter.body',
                'attr'     => ['class' => 'admin-list-filters__control'],
            ]);

            $this->addHiddenFilterField('status');
        });
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'admin_blog_comment_filter';
    }
}
