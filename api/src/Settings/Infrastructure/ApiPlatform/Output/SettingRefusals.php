<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform\Output;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Settings\Application\InvalidSettingValue;
use App\Settings\Application\SettingLevelRefused;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * A settings save refused as a form's fields are: one violation per key, its key as the property path and, in its
 * payload, the reason the screens translate and that text's parameters, so a page marks every field it refused.
 */
final class SettingRefusals
{
    public static function of(InvalidSettingValue $refused): ValidationException
    {
        $violations = new ConstraintViolationList();
        foreach ($refused->refusals as $key => $refusal) {
            $violations->add(DomainRule::violation($key, $refusal->message, $refusal->reason, $refusal->params));
        }

        return new ValidationException($violations, previous: $refused);
    }

    /** A level the key is not kept at, said on the key as a value it refuses is. */
    public static function ofLevel(string $key, SettingLevelRefused $refused): ValidationException
    {
        return DomainRule::refused($key, $refused->getMessage(), 'level_refused', previous: $refused);
    }
}
