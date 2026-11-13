<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\ValueObject;

use Nowo\BlogKitBundle\Model\BlogUserInterface;

/**
 * Since 1.9.0 (`doctrine.user_reference: id`, audit ON-M1): a stored user id the bundle cannot keep exactly (edge
 * whitespace, control or format characters, invalid UTF-8, too long: a legacy or hand-written value).
 *
 * It is NOT "no user": the column holds something, so the row still has an owner, just one nobody can be matched to.
 * It exposes no id (`getId()` and `getUserIdentifier()` are empty), never equals or is the same as any user, and every
 * owner check denies it (fail closed). The raw value is kept only to be written back unchanged.
 */
final readonly class UnreadableBlogUserRef implements BlogUserInterface
{
    public function __construct(public string $raw)
    {
    }

    /** Empty: an unreadable reference has no id to match. */
    public function getId(): string
    {
        return '';
    }

    /** Empty: an unreadable reference has no identifier to match. */
    public function getUserIdentifier(): string
    {
        return '';
    }
}
