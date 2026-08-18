<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use Doctrine\ORM\QueryBuilder;
use Nowo\BlogKitBundle\Entity\BlogArticle;
use Nowo\BlogKitBundle\Entity\BlogTag;
use Nowo\BlogKitBundle\Repository\BlogTagRepository;
use Override;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Symfony form type for blog article admin editing.
 *
 * @extends AbstractBlogFormType<BlogArticle>
 */
final class BlogArticleType extends AbstractBlogFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addTextField('slug');
            $this->addTextField('image', [
                'required' => false,
            ]);
            $this->addUrlField('linkedinUrl', [
                'required' => false,
            ]);
            $this->addTypedField('publishedAt', DateType::class, [
                'required' => false,
                'widget'   => 'single_text',
            ]);
            $this->addIntegerField('position');
            $this->addCheckboxField('published', [
                'required' => false,
            ]);
            $this->addTypedField('tags', EntityType::class, [
                'class'         => BlogTag::class,
                'choice_label'  => static fn (BlogTag $blogTag): string => $blogTag->getName('es') . ' (' . $blogTag->getSlug() . ')',
                'query_builder' => static fn (BlogTagRepository $blogTagRepository): QueryBuilder => $blogTagRepository->createQueryBuilder('t')
                    ->leftJoin('t.translations', 'tt')
                    ->addSelect('tt')
                    ->orderBy('t.slug', 'ASC'),
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'attr'     => [
                    'class' => 'admin-form-multiselect',
                    'size'  => 8,
                ],
            ]);
            $this->addTypedField('resources', CollectionType::class, [
                'entry_type'    => BlogArticleResourceType::class,
                'allow_add'     => true,
                'allow_delete'  => true,
                'by_reference'  => false,
                'required'      => false,
                'prototype'     => true,
                'entry_options' => [
                    'label' => false,
                ],
            ]);
            $this->addTranslationsCollectionField(BlogArticleTranslationType::class);
        });
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => BlogArticle::class,
        ]);
    }
}
