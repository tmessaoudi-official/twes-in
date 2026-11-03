<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Domain\Service;

use App\Texts\Domain\ValueObject\TextAddress;

/**
 * The API's texts whose code hands them a number as `%count%`, in every language: Symfony then reads a « | » or a
 * leading interval as forms the count chooses from, and throws when it chooses none, so such a text is checked for
 * every count whatever its original looks like (Arabic's has one form, the data check mail's has none). A text no
 * count reaches writes its « | » as typed and is never checked. Which call hands a count is a fact of the code, not
 * a choice, so it is written here; `CountedTextsTest` holds it to every place handing one, both ways.
 */
final class CountedTexts
{
    /** By domain, the keys a template, a class or a notification's kind (`NotificationKind::$count`) counts with. */
    public const array KEYS = [
        'emails' => [
            'account_deletion.final',
            'notification.types.data_check_failed',
            'notification.types.invoice_late_fee_drafted',
            'notification.types.invoice_reminder_due',
            'password_reset.expires',
            'signup.expires',
        ],
        'pdf' => [
            'invoice.late_fee_line',
            'invoice.payment_terms',
        ],
    ];

    public static function counted(TextAddress $address): bool
    {
        return \in_array($address->key, self::KEYS[$address->domain] ?? [], true);
    }
}
