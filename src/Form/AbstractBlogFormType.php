<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Form;

use InvalidArgumentException;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Override;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_key_exists;
use function sprintf;

/**
 * Blog kit product forms — FormKit profile blog_kit.
 *
 * @template TData
 */
#[FormKitConfig('blog_kit')]
abstract class AbstractBlogFormType extends FormKitAbstractType
{
    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'translation_domain' => 'NowoBlogKitBundle',
        ]);
    }

    /**
     * @param FormBuilderInterface<TData> $builder
     * @param array<string, mixed> $options
     */
    protected function addWithDefaults(
        FormBuilderInterface $builder,
        string $name,
        string $type,
        array $options = [],
    ): void {
        if (!array_key_exists('placeholder', $options)) {
            $options['placeholder'] = false;
        }

        $this->withBuilder($builder, function () use ($name, $type, $options): void {
            $this->addNamedField($name, $this->resolveTypeAlias($type), $options);
        });
    }

    /** @param array<string, mixed> $options */
    protected function addTypedField(string $name, string $type, array $options = []): void
    {
        $this->addNamedField($name, $this->resolveTypeAlias($type), $options);
    }

    /** @param class-string $entryType
     * @param array<string, mixed> $options
     */
    protected function addTranslationsCollectionField(string $entryType, array $options = []): void
    {
        $this->addTranslationsCollection($this->boundBuilder(), $entryType, $options);
    }

    /**
     * @param FormBuilderInterface<TData> $formBuilder
     * @param class-string $entryType
     * @param array<string, mixed> $options
     */
    protected function addTranslationsCollection(
        FormBuilderInterface $formBuilder,
        string $entryType,
        array $options = [],
    ): void {
        $this->addWithDefaults($formBuilder, 'translations', CollectionType::class, [
            'entry_type'   => $entryType,
            'allow_add'    => false,
            'allow_delete' => false,
            'label'        => false,
            ...$options,
        ]);
    }

    /** @param array<string, mixed> $options */
    protected function addCkeditor5Field(string $name, array $options = []): void
    {
        $this->addCkeditor5($this->boundBuilder(), $name, $options);
    }

    /**
     * @param FormBuilderInterface<TData> $formBuilder
     * @param array<string, mixed> $options
     */
    protected function addCkeditor5(
        FormBuilderInterface $formBuilder,
        string $name,
        array $options = [],
    ): void {
        $ckeditor = 'Nowo\\Ckeditor5EditorBundle\\Form\\Ckeditor5EditorType';
        $type     = class_exists($ckeditor) ? $ckeditor : TextareaType::class;
        if ($type === TextareaType::class) {
            $options['attr'] = array_merge(['rows' => 12], $options['attr'] ?? []);
            unset($options['config'], $options['theme'], $options['min_height']);
        }
        $this->addWithDefaults($formBuilder, $name, $type, $options);
    }

    /** @param array<string, mixed> $options */
    protected function addHiddenLocaleField(array $options = []): void
    {
        $this->addHiddenLocale($this->boundBuilder(), $options);
    }

    /**
     * @param FormBuilderInterface<TData> $formBuilder
     * @param array<string, mixed> $options
     */
    protected function addHiddenLocale(
        FormBuilderInterface $formBuilder,
        array $options = [],
    ): void {
        $this->addWithDefaults($formBuilder, 'locale', HiddenType::class, [
            'label' => false,
            'help'  => false,
            ...$options,
        ]);
    }

    private function resolveTypeAlias(string $type): string
    {
        if (!str_contains($type, '\\')) {
            return $type;
        }

        $ckeditor = 'Nowo\\Ckeditor5EditorBundle\\Form\\Ckeditor5EditorType';

        return match ($type) {
            CollectionType::class => 'collection',
            HiddenType::class     => 'hidden',
            $ckeditor             => class_exists($ckeditor) ? 'ckeditor5' : 'textarea',
            DateType::class       => 'date',
            EntityType::class     => 'entity',
            TextareaType::class   => 'textarea',
            default               => throw new InvalidArgumentException(sprintf('Unknown form type FQCN "%s". Register it in nowo_form_kit.type_map or pass a snake_case alias.', $type)),
        };
    }
}
