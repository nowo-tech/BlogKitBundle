<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Unit\Service;

use Nowo\BlogKitBundle\Locale\BlogLocales;
use Nowo\BlogKitBundle\Service\BlogLocalesLocaleResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BlogLocalesLocaleResolverTest extends TestCase
{
    private BlogLocales $blogLocales;
    private BlogLocalesLocaleResolver $resolver;

    protected function setUp(): void
    {
        $this->blogLocales = new BlogLocales('es', ['es', 'en']);
        $this->resolver    = new BlogLocalesLocaleResolver($this->blogLocales);
    }

    #[Test]
    public function itReturnsDefaultLocaleWhenRequestIsMissing(): void
    {
        self::assertSame('es', $this->resolver->resolve(null));
    }

    #[Test]
    public function itReturnsRequestLocaleWhenSupported(): void
    {
        $request = Request::create('/blog');
        $request->setLocale('en');

        self::assertSame('en', $this->resolver->resolve($request));
    }

    #[Test]
    public function itFallsBackToDefaultWhenLocaleIsUnsupported(): void
    {
        $request = Request::create('/blog');
        $request->setLocale('fr');

        self::assertSame('es', $this->resolver->resolve($request));
    }
}
