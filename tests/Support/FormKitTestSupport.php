<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\FormKitBundle\Form\FormTypeMap;
use ReflectionClass;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

use function call_user_func;
use function method_exists;

/**
 * Builds a FormOptionsMerger with blog_kit / filter profiles for unit form tests.
 */
final class FormKitTestSupport
{
    public static function merger(string $defaultProfile = 'blog_kit'): FormOptionsMerger
    {
        $blogKit = [
            'translation_domain' => 'NowoBlogKitBundle',
            'defaults'           => [
                'attr'     => ['class' => 'nowo-ui-input form-control'],
                'row_attr' => ['class' => 'mb-2'],
            ],
            'field_types' => [],
        ];

        $filter = [
            'translation_domain' => 'NowoBlogKitBundle',
            'defaults'           => [
                'label'    => false,
                'required' => false,
                'attr'     => [],
                'row_attr' => [],
            ],
            'auto_placeholder' => true,
            'auto_help'        => true,
            'field_types'      => [],
        ];

        return new FormOptionsMerger(
            [
                'blog_kit' => $blogKit,
                'filter'   => $filter,
                'default'  => $blogKit,
            ],
            $defaultProfile,
            new ConstraintDefinitionFactory(),
        );
    }

    public static function typeMap(): FormTypeMap
    {
        return new FormTypeMap([
            'entity' => EntityType::class,
        ]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function createType(string $class, string $defaultProfile = 'blog_kit'): object
    {
        $merger = self::merger($defaultProfile);
        $map    = self::typeMap();
        $type   = new $class($merger, $map);

        if (method_exists($type, 'setFormOptionsMerger')) {
            call_user_func([$type, 'setFormOptionsMerger'], $merger);
        }

        return $type;
    }

    /**
     * @template T of object
     *
     * @param T $formType
     *
     * @return T
     */
    public static function withMerger(object $formType, string $defaultProfile = 'blog_kit'): object
    {
        $merger = self::merger($defaultProfile);

        if (method_exists($formType, 'setFormOptionsMerger')) {
            call_user_func([$formType, 'setFormOptionsMerger'], $merger);

            return $formType;
        }

        $reflection = new ReflectionClass($formType);
        if ($reflection->hasProperty('formOptionsMerger')) {
            $property = $reflection->getProperty('formOptionsMerger');
            $property->setValue($formType, $merger);
        }

        return $formType;
    }
}
