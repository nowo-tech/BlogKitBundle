<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Controller\Admin;

use Nowo\BlogKitBundle\Controller\Admin\AdminListFilterTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;

final class AdminListFilterTraitTest extends TestCase
{
    #[Test]
    public function resolveAdminListFiltersUsesSubmittedValidFormData(): void
    {
        $request    = Request::create('/admin/blog?title=ignored', 'GET');
        $titleField = $this->field('  Symfony  ');
        $slugField  = $this->field('blog-post');

        $form = $this->createMock(FormInterface::class);
        $form->expects(self::once())->method('handleRequest')->with($request);
        $form->expects(self::once())->method('isSubmitted')->willReturn(true);
        $form->expects(self::once())->method('isValid')->willReturn(true);
        $form->expects(self::exactly(2))->method('has')->willReturnMap([
            ['title', true],
            ['slug', true],
        ]);
        $form->expects(self::exactly(2))->method('get')->willReturnMap([
            ['title', $titleField],
            ['slug', $slugField],
        ]);

        $filters = $this->createTraitHarness()->resolve($request, $form, ['title', 'slug']);

        self::assertSame([
            'title' => 'Symfony',
            'slug'  => 'blog-post',
        ], $filters);
    }

    #[Test]
    public function resolveAdminListFiltersFallsBackToQueryStringWhenFormIsNotSubmitted(): void
    {
        $request = Request::create('/admin/blog?title=Symfony&slug=blog-post', 'GET');

        $form = $this->createConfiguredMock(FormInterface::class, [
            'isSubmitted' => false,
            'createView'  => new FormView(),
        ]);
        $form->expects(self::once())->method('handleRequest')->with($request);

        $filters = $this->createTraitHarness()->resolve($request, $form, ['title', 'slug']);

        self::assertSame([
            'title' => 'Symfony',
            'slug'  => 'blog-post',
        ], $filters);
    }

    #[Test]
    public function resolveAdminListFiltersSkipsEmptyTrimmedValues(): void
    {
        $request = Request::create('/admin/blog', 'GET');

        $form = $this->createMock(FormInterface::class);
        $form->expects(self::once())->method('handleRequest')->with($request);
        $form->expects(self::once())->method('isSubmitted')->willReturn(true);
        $form->expects(self::once())->method('isValid')->willReturn(true);
        $form->expects(self::exactly(2))->method('has')->willReturnMap([
            ['title', true],
            ['slug', true],
        ]);
        $form->expects(self::exactly(2))->method('get')->willReturnMap([
            ['title', $this->field('   ')],
            ['slug', $this->field('')],
        ]);

        $filters = $this->createTraitHarness()->resolve($request, $form, ['title', 'slug']);

        self::assertSame([], $filters);
    }

    #[Test]
    public function resolveAdminListFiltersSkipsMissingFormFields(): void
    {
        $request = Request::create('/admin/blog', 'GET');

        $form = $this->createMock(FormInterface::class);
        $form->expects(self::once())->method('handleRequest')->with($request);
        $form->expects(self::once())->method('isSubmitted')->willReturn(true);
        $form->expects(self::once())->method('isValid')->willReturn(true);
        $form->expects(self::exactly(2))->method('has')->willReturnMap([
            ['title', true],
            ['slug', false],
        ]);
        $form->expects(self::once())->method('get')->with('title')->willReturn($this->field('Visible'));

        $filters = $this->createTraitHarness()->resolve($request, $form, ['title', 'slug']);

        self::assertSame(['title' => 'Visible'], $filters);
    }

    private function createTraitHarness(): object
    {
        return new class {
            use AdminListFilterTrait;

            /** @param list<string> $allowedKeys */
            public function resolve(Request $request, FormInterface $form, array $allowedKeys): array
            {
                return $this->resolveAdminListFilters($request, $form, $allowedKeys);
            }
        };
    }

    private function field(mixed $value): FormInterface
    {
        return $this->createConfiguredMock(FormInterface::class, [
            'getData'    => $value,
            'createView' => new FormView(),
        ]);
    }
}
