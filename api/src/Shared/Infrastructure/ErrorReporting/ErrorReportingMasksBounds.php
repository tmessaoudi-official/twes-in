<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ErrorReporting;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * The operator's own masks for an error report (app.error_reporting.extra_masks), checked when the container is built:
 * a list of {pattern, replacement}, each pattern a regular expression PHP compiles. A mask that does not compile would
 * fail on the first report and send it unmasked, or not at all, so a deployment holding one never starts. They only
 * add to the built-in masks of ScrubPersonalData, which no parameter reaches.
 */
final class ErrorReportingMasksBounds implements CompilerPassInterface
{
    public const string EXTRA_MASKS = 'app.error_reporting.extra_masks';

    public function process(ContainerBuilder $container): void
    {
        $masks = $container->getParameter(self::EXTRA_MASKS);
        if (!self::isList($masks)) {
            throw self::refused('a list of {pattern, replacement}', $masks);
        }
        foreach ($masks as $mask) {
            if (!\is_array($mask) || ['pattern', 'replacement'] !== self::sortedKeys($mask) || !\is_string($mask['pattern']) || !\is_string($mask['replacement'])) {
                throw self::refused('a list of {pattern, replacement}, both text', $mask);
            }
            if (!self::compiles($mask['pattern'])) {
                throw self::refused('a list of masks whose patterns PHP compiles', $mask['pattern']);
            }
        }
    }

    /**
     * Read as what services.yaml may hold, whatever the compiled container's type says: an operator can write text there.
     *
     * @phpstan-assert-if-true list<mixed> $value
     */
    private static function isList(mixed $value): bool
    {
        return \is_array($value) && array_is_list($value);
    }

    /**
     * @param array<mixed> $mask
     *
     * @return list<int|string>
     */
    private static function sortedKeys(array $mask): array
    {
        $keys = array_keys($mask);
        sort($keys);

        return $keys;
    }

    /** Whether PCRE compiles the pattern: preg_match answers false, with a warning caught here, for one it cannot. */
    private static function compiles(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return false !== preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }
    }

    private static function refused(string $expected, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(\sprintf('%s is %s; %s is not.', self::EXTRA_MASKS, $expected, json_encode($value)));
    }
}
