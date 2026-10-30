<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\BlogKitBundle\Tests\Integration\Kernel\HostRoutesKernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function class_exists;
use function is_string;
use function sprintf;
use function str_starts_with;
use function sys_get_temp_dir;

/**
 * REQ-SF-004 rule 7: the blog routes are opt-in. The controller services are not autoconfigured, so a host whose
 * routing is only `routing.controllers` gets no blog route; with the documented routing.yaml import the routes exist
 * once and the pages are served (the explicit service subscriber tag keeps AbstractController working).
 */
final class HostRoutesOptInTest extends TestCase
{
    private ?HostRoutesKernel $kernel = null;

    protected function setUp(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('The routing.controllers resource needs symfony/routing >= 7.3.');
        }
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        new Filesystem()->remove(sys_get_temp_dir() . '/nowo-blog-kit-host-routes');
    }

    #[Test]
    public function routingControllersAloneRegistersNoBlogRoute(): void
    {
        foreach ($this->router(false)->getRouteCollection()->all() as $name => $route) {
            $controller = $route->getDefault('_controller');
            self::assertFalse(
                is_string($controller) && str_starts_with($controller, 'Nowo\\BlogKitBundle\\Controller\\'),
                sprintf('Route "%s" points to a BlogKit controller without the bundle routing import.', $name),
            );
        }
        self::assertNull($this->router(false)->getRouteCollection()->get('blog_index'));
        self::assertNull($this->router(false)->getRouteCollection()->get('admin_blog_index'));
    }

    #[Test]
    public function theImportedRoutesServeThePublicBlog(): void
    {
        $routes = $this->router(true)->getRouteCollection();
        self::assertSame('/blog', $routes->get('blog_index')?->getPath());
        self::assertSame('/admin/blog', $routes->get('admin_blog_index')?->getPath());

        $container = $this->kernel?->getContainer();
        self::assertNotNull($container);
        $em = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        new SchemaTool($em)->createSchema($em->getMetadataFactory()->getAllMetadata());

        $response = $this->kernel?->handle(Request::create('/blog'));
        self::assertSame(200, $response?->getStatusCode(), (string) $response?->getContent());
    }

    private function router(bool $importBundleRoutes): RouterInterface
    {
        if (!$this->kernel instanceof HostRoutesKernel) {
            $this->kernel = new HostRoutesKernel($importBundleRoutes);
            $this->kernel->boot();
        }
        $router = $this->kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router;
    }
}
