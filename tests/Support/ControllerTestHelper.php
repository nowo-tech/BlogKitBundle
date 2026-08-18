<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Support;

use Nowo\BlogKitBundle\Form\BlogArticleFilterType;
use Nowo\BlogKitBundle\Form\BlogArticleInlineTranslationType;
use Nowo\BlogKitBundle\Form\BlogArticleResourceType;
use Nowo\BlogKitBundle\Form\BlogArticleTranslationType;
use Nowo\BlogKitBundle\Form\BlogArticleType;
use Nowo\BlogKitBundle\Form\BlogCommentFilterType;
use Nowo\BlogKitBundle\Form\BlogInlineModalType;
use Nowo\BlogKitBundle\Form\BlogPublicSearchType;
use Nowo\BlogKitBundle\Form\BlogSettingsType;
use Nowo\BlogKitBundle\Form\BlogTagFilterType;
use Nowo\BlogKitBundle\Form\BlogTagTranslationType;
use Nowo\BlogKitBundle\Form\BlogTagType;
use Nowo\BlogKitBundle\Form\PublicBlogCommentType;
use Nowo\BlogKitBundle\Form\StaffBlogCommentReplyType;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\FormKitBundle\Form\GetFilterFormFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;
use Symfony\Component\Validator\Validation;
use Twig\Environment;

/**
 * Builds a Symfony controller container similar to HttpLogAdminControllerTest.
 */
final class ControllerTestHelper
{
    /**
     * @param array<string, object> $services
     */
    public static function bind(
        AbstractController $controller,
        Request $request,
        array $services = [],
        ?TestUser $user = null,
        bool $csrfValid = true,
    ): ContainerBuilder {
        $session = $request->hasSession() ? $request->getSession() : new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $tokenStorage = new TokenStorage();
        if ($user instanceof TestUser) {
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }

        $csrfTokenManager = self::createCsrfManager($requestStack, $csrfValid);
        $formFactory      = $services['form.factory'] ?? self::createFormFactory($csrfTokenManager);

        $container = new ContainerBuilder();
        $container->set('request_stack', $requestStack);
        $container->set('security.token_storage', $tokenStorage);
        $container->set('security.csrf.token_manager', $csrfTokenManager);
        $container->set('form.factory', $formFactory);
        $container->set('twig', $services['twig'] ?? self::createTwigStub());
        $container->set('router', $services['router'] ?? self::createRouterStub());
        $container->set('security.authorization_checker', $services['security.authorization_checker']
            ?? self::createAuthorizationCheckerStub());

        foreach ($services as $id => $service) {
            $container->set($id, $service);
        }

        $controller->setContainer($container);

        return $container;
    }

    public static function createFormFactory(?CsrfTokenManagerInterface $csrfTokenManager = null): FormFactoryInterface
    {
        $csrfTokenManager ??= new CsrfTokenManager();

        $types = [
            FormKitTestSupport::createType(BlogSettingsType::class),
            FormKitTestSupport::createType(BlogArticleType::class),
            FormKitTestSupport::createType(BlogArticleTranslationType::class),
            FormKitTestSupport::createType(BlogArticleInlineTranslationType::class),
            FormKitTestSupport::createType(BlogArticleResourceType::class),
            FormKitTestSupport::createType(BlogInlineModalType::class),
            FormKitTestSupport::createType(BlogTagType::class),
            FormKitTestSupport::createType(BlogTagTranslationType::class),
            FormKitTestSupport::createType(BlogPublicSearchType::class),
            FormKitTestSupport::createType(PublicBlogCommentType::class),
            FormKitTestSupport::createType(StaffBlogCommentReplyType::class),
            FormKitTestSupport::createType(BlogArticleFilterType::class, 'filter'),
            FormKitTestSupport::createType(BlogCommentFilterType::class, 'filter'),
            FormKitTestSupport::createType(BlogTagFilterType::class, 'filter'),
        ];

        return Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new CsrfExtension($csrfTokenManager))
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addExtension(new PreloadedExtension($types, []))
            ->getFormFactory();
    }

    public static function csrfOnlyFormFactory(?FormFactoryInterface $formFactory = null): CsrfOnlyFormFactory
    {
        return new CsrfOnlyFormFactory($formFactory ?? self::createFormFactory());
    }

    public static function filterFormFactory(?FormFactoryInterface $formFactory = null): GetFilterFormFactory
    {
        return new GetFilterFormFactory($formFactory ?? self::createFormFactory());
    }

    private static function createCsrfManager(RequestStack $requestStack, bool $valid): CsrfTokenManagerInterface
    {
        if ($valid) {
            return new CsrfTokenManager(null, new SessionTokenStorage($requestStack));
        }

        return new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'invalid');
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'invalid');
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return false;
            }
        };
    }

    private static function createRouterStub(): RouterInterface
    {
        return new class implements RouterInterface {
            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }

            public function getRouteCollection(): RouteCollection
            {
                return new RouteCollection();
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                $query = $parameters === [] ? '' : '?' . http_build_query($parameters);

                return '/generated/' . $name . $query;
            }

            public function match(string $pathinfo): array
            {
                return [];
            }
        };
    }

    private static function createTwigStub(): Environment
    {
        return new class extends Environment {
            public function __construct()
            {
            }

            public function render($name, array $context = []): string
            {
                return (string) $name;
            }
        };
    }

    private static function createAuthorizationCheckerStub(): AuthorizationCheckerInterface
    {
        return new class implements AuthorizationCheckerInterface {
            public function isGranted(mixed $attribute, mixed $subject = null, mixed $accessDecision = null): bool
            {
                return true;
            }
        };
    }
}
