<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Service\TypedText;
use Symfony\Component\Uid\Uuid;

/**
 * Who received a document: the goods of a delivery note or of an invoice, a quote's answer, a vendor's goods. The name
 * is copied onto the document as it was then, so renaming or removing the contact it came from never changes what the
 * document says; the contact, when there is one, is kept beside it. A name typed for someone who is no contact names none.
 */
final readonly class Receiver
{
    /** A contact's first and last names, a hundred characters each, and the space between them. */
    public const int NAME_MAX = 201;

    private function __construct(public string $name, public ?Uuid $contactId)
    {
    }

    /** A name the caller checked with `refusal()` first; one it did not is a programming error. */
    public static function named(string $name, ?Uuid $contactId = null): self
    {
        if (null !== self::refusal($name)) {
            throw new \LogicException('A receiver is named once its name is checked with Receiver::refusal().');
        }

        return new self(TypedText::trimmed($name), $contactId);
    }

    /** Why this name cannot be printed as who received, or null when it can. */
    public static function refusal(string $name): ?Refusal
    {
        $name = TypedText::trimmed($name);
        if ('' === $name) {
            return new Refusal('value_required', 'Name who received, or leave the receiver out.');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return new Refusal('invalid_text', \sprintf('At most %d characters.', self::NAME_MAX), ['max' => self::NAME_MAX]);
        }

        return null;
    }

    public function equals(?self $other): bool
    {
        return null !== $other && $this->name === $other->name && $this->contactId?->toRfc4122() === $other->contactId?->toRfc4122();
    }
}
