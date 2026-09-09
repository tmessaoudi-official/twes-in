<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Email;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/** Stores the Email value object as the VARCHAR it always was (doctrine.yaml registers it as "email"). */
final class EmailType extends Type
{
    public const string NAME = 'email';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Email
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('An email column holds a string.');
        }

        return Email::fromString($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return match (true) {
            null === $value => null,
            $value instanceof Email => $value->value,
            \is_string($value) => Email::fromString($value)->value,
            default => throw new \UnexpectedValueException('Not an email.'),
        };
    }
}
