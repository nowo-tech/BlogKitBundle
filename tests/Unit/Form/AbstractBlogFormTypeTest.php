<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Form;

use Closure;
use InvalidArgumentException;
use Nowo\BlogKitBundle\Form\AbstractBlogFormType;
use Nowo\BlogKitBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;

final class AbstractBlogFormTypeTest extends TestCase
{
    #[Test]
    public function itThrowsForUnknownFormTypeFqcn(): void
    {
        $type = new class(FormKitTestSupport::merger(), FormKitTestSupport::typeMap()) extends AbstractBlogFormType {
            public function resolveAliasForTest(string $typeName): string
            {
                $resolver = Closure::bind(
                    fn (string $value): string => $this->resolveTypeAlias($value),
                    $this,
                    AbstractBlogFormType::class,
                );

                return $resolver($typeName);
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown form type FQCN');

        // Must contain a backslash so resolveTypeAlias does not treat it as a snake_case alias.
        $type->resolveAliasForTest('Acme\\Forms\\UnknownEditorType');
    }

    #[Test]
    public function itDefaultsPlaceholderToFalseWhenAbsent(): void
    {
        $type = new class(FormKitTestSupport::merger(), FormKitTestSupport::typeMap()) extends AbstractBlogFormType {
            public function exposeAddWithDefaults(FormBuilderInterface $builder): void
            {
                $this->addWithDefaults($builder, 'summary', TextareaType::class);
            }
        };

        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->expects(self::atLeastOnce())
            ->method('add')
            ->with(
                'summary',
                TextareaType::class,
                self::callback(
                    // placeholder:false is consumed by FormOptionsMerger (clears attr.placeholder).
                    static fn (array $options): bool => !isset($options['attr']['placeholder'])
                    && ($options['attr']['class'] ?? '') === 'nowo-ui-input form-control',
                ),
            )
            ->willReturnSelf();

        $type->exposeAddWithDefaults($builder);
    }

    #[Test]
    public function itFallsBackToTextareaWhenCkeditorIsUnavailable(): void
    {
        self::assertFalse(class_exists('Nowo\\Ckeditor5EditorBundle\\Form\\Ckeditor5EditorType'));

        $type = new class(FormKitTestSupport::merger(), FormKitTestSupport::typeMap()) extends AbstractBlogFormType {
            public function exposeAddCkeditor(FormBuilderInterface $builder): void
            {
                $this->addCkeditor5($builder, 'body', [
                    'config'     => 'simple',
                    'theme'      => 'auto',
                    'min_height' => '320px',
                ]);
            }
        };

        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->expects(self::atLeastOnce())
            ->method('add')
            ->with(
                'body',
                TextareaType::class,
                self::callback(static fn (array $options): bool => ($options['attr']['rows'] ?? null) === 12
                    && !isset($options['config'])
                    && !isset($options['theme'])
                    && !isset($options['min_height'])),
            )
            ->willReturnSelf();

        $type->exposeAddCkeditor($builder);
    }
}
