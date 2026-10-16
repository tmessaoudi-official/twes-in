<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Validation;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Symfony\Validator\Metadata\Property\Restriction\PropertySchemaRestrictionMetadataInterface;
use App\Shared\Domain\ContactRules;
use Symfony\Component\Validator\Constraint;

/**
 * What the API document says a field held to a contact rule takes: the format, length and pattern the built-in
 * constraints it replaced published, read from the domain's own values. A BIC's shape is left to the rule.
 */
final class ContactRuleSchema implements PropertySchemaRestrictionMetadataInterface
{
    /** @return array<string, string|int> */
    public function create(Constraint $constraint, ApiProperty $propertyMetadata): array
    {
        if (!$constraint instanceof ContactRule) {
            return [];
        }
        $declared = $propertyMetadata->getSchema() ?? [];
        $format = static fn (string $format): array => empty($declared['format']) ? ['format' => $format] : [];

        return match ($constraint->rule) {
            ContactRule::EMAIL => [...$format('email'), 'maxLength' => ContactRules::EMAIL_MAX],
            ContactRule::WEBSITE => [...$format('uri'), 'maxLength' => ContactRules::WEBSITE_MAX],
            ContactRule::PHONE => ['pattern' => '^('.ContactRules::PHONE_PATTERN.')$'],
            ContactRule::COUNTRY => ['pattern' => '^[A-Z]{2}$'],
            default => [],
        };
    }

    public function supports(Constraint $constraint, ApiProperty $propertyMetadata): bool
    {
        return $constraint instanceof ContactRule;
    }
}
