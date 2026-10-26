<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/**
 * Whether a document names who received it, as each document's setting says per company, customer group and customer:
 * not at all, when the person who records it wishes, or always, a delivery then refused without a receiver.
 */
enum ReceiverRule: string
{
    case Off = 'off';
    case Optional = 'optional';
    case Required = 'required';

    /** Why the rule refuses this receiver, or its absence; null when it takes it. */
    public function refusal(?Receiver $receiver): ?Refusal
    {
        return match (true) {
            self::Required === $this && null === $receiver => new Refusal('receiver_required', 'Name who received: this customer\'s documents require it.'),
            self::Off === $this && null !== $receiver => new Refusal('receiver_off', 'This customer\'s documents name nobody who received them: the setting is off.'),
            default => null,
        };
    }
}
