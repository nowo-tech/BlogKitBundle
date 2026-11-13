<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Tests\Integration\Worker;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Stateless test authenticator: the `X-Worker-User` header names the user of the request (alice or bob).
 */
final class WorkerHeaderAuthenticator extends AbstractAuthenticator
{
    /**
     * @param array<string, int|string> $ids user identifier => id
     */
    public function __construct(
        private readonly array $ids,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has('X-Worker-User');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $name = (string) $request->headers->get('X-Worker-User');

        return new SelfValidatingPassport(new UserBadge($name, function (string $identifier): WorkerSecurityUser {
            if (!isset($this->ids[$identifier])) {
                throw new UserNotFoundException();
            }

            return new WorkerSecurityUser($this->ids[$identifier], $identifier);
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new Response('', Response::HTTP_UNAUTHORIZED);
    }
}
