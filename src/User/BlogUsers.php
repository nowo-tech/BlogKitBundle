<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\User;

use Nowo\BlogKitBundle\Model\BlogUserInterface;
use Nowo\BlogKitBundle\ValueObject\BlogUserRef;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function is_object;

/**
 * Since 1.9.0 (REQ-ARCH-001 rule 2): how the blog rows reference host users.
 *
 * - `doctrine.user_reference: entity` (default): a Doctrine relation to `user_class`, which implements
 *   {@see BlogUserInterface}, exactly as in 1.8. DQL compares `IDENTITY(path)`; the current user is the security user
 *   when it implements the interface.
 * - `id`: the user's id in a string column ({@see BlogUserRef}); the host user class implements nothing of the bundle.
 *   DQL compares the column itself and the current user is the {@see BlogUserRef} of the security user's `getId()`.
 *
 * Stateless: the token is read per call (REQ-RUNTIME-002).
 */
final readonly class BlogUsers
{
    public const string ENTITY = 'entity';

    public const string ID = 'id';

    public function __construct(
        private string $mode = self::ENTITY,
        private ?TokenStorageInterface $tokenStorage = null,
        private ?BlogUserDirectoryInterface $directory = null,
    ) {
    }

    public function idMode(): bool
    {
        return $this->mode === self::ID;
    }

    /** The DQL expression of a user field's value: `IDENTITY(path)` for a relation, the column itself in the id mode. */
    public function identity(string $path): string
    {
        return $this->idMode() ? $path : 'IDENTITY(' . $path . ')';
    }

    /** Whether a user field is a relation the queries may fetch-join (entity mode only). */
    public function joinable(): bool
    {
        return !$this->idMode();
    }

    /**
     * The value the bundle stores for a host user: the user itself (entity mode, when it implements
     * {@see BlogUserInterface}), its {@see BlogUserRef} (id mode, when its id can be kept exactly), else null.
     */
    public function reference(?object $user): ?BlogUserInterface
    {
        if ($this->idMode()) {
            return BlogUserRef::tryFromUser($user);
        }

        return $user instanceof BlogUserInterface ? $user : null;
    }

    /** {@see reference()} of the security user of the current request (null without one). */
    public function current(): ?BlogUserInterface
    {
        return $this->reference($this->currentUser());
    }

    /** The security user of the current request, whatever its class. */
    public function currentUser(): ?object
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return is_object($user) ? $user : null;
    }

    /**
     * What a page shows for a user field: the label of `user_directory` for a {@see BlogUserRef} (else its id), the
     * user identifier of a {@see BlogUserInterface}, '' without a user.
     */
    public function label(?object $user): string
    {
        if ($user instanceof BlogUserRef) {
            $label = $this->directory?->labelOf($user->id);

            return $label !== null && $label !== '' ? $label : $user->id;
        }

        return $user instanceof BlogUserInterface ? $user->getUserIdentifier() : '';
    }
}
