<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application\Receivers;

use App\Shared\Application\Receivers\ReceiverChoice;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\Receiver;
use App\Shared\Domain\ValueObject\Refusal;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * Who received a vendor's goods, from what a person chose: one of the company's members, their name copied as it reads
 * now with their account beside it, or any name typed, which joins no list. The member's account rides in the choice's
 * and the receiver's contact slot, the one link a receiver keeps.
 */
final readonly class ChooseReceiptReceiver
{
    public function __construct(private ReceivingMembers $members)
    {
    }

    /**
     * The members a receipt offers, the person recording first, never chosen for them.
     *
     * @return list<ReceivingMember>
     */
    public function offered(Company $company, ?Uuid $recording): array
    {
        return $this->members->membersOf($company, $recording);
    }

    /**
     * Null when nobody was named; `$current` itself when it is who was named again, as a form sends back what it read,
     * so a member renamed since keeps the name the receipt copied; a refusal when the choice cannot be a receiver here.
     */
    public function receiver(Company $company, ?ReceiverChoice $choice, ?Receiver $current): Receiver|Refusal|null
    {
        if (null !== $choice && null !== $current && $choice->names($current)) {
            return $current;
        }
        if (null !== $choice?->contactId) {
            $memberId = Uuid::isValid($choice->contactId) ? Uuid::fromString($choice->contactId) : null;
            $name = null === $memberId ? null : $this->members->nameOf($company, $memberId);
            if (null === $memberId || null === $name || null !== Receiver::refusal($name)) {
                return new Refusal('unknown_choice', 'This person is not a member of the company: choose one of its members, or type a name.');
            }

            return Receiver::named($name, $memberId);
        }
        $typed = $choice->name ?? '';
        if ('' === TypedText::trimmed($typed)) {
            return null;
        }

        return Receiver::refusal($typed) ?? Receiver::named($typed);
    }
}
