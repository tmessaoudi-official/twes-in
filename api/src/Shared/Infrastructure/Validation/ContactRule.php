<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * Holds a form's field to the rule the domain holds every writer to (`App\Shared\Domain\Service\ContactRules`), so the form, a
 * file and anything else that writes it refuse the same values under the same reason: the rule is declared once, in
 * the domain, and a form gets every field's fault in one answer before the domain sees the first.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ContactRule extends Constraint
{
    public const string EMAIL = 'email';
    public const string PHONE = 'phone';
    public const string WEBSITE = 'website';
    public const string COUNTRY = 'country';
    public const string BIC = 'bic';
    public const string IBAN = 'iban';

    /** @param self::EMAIL|self::PHONE|self::WEBSITE|self::COUNTRY|self::BIC|self::IBAN $rule */
    public function __construct(public readonly string $rule, ?array $groups = null)
    {
        parent::__construct(null, $groups);
    }
}
