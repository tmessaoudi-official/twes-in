<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Infrastructure\Mail;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * The keys a stop link is signed under (app.notification.stop_keys), checked when the container is built: a list of one
 * key or more, since a link with none could be written by anyone, each key text, and a key written out in services.yaml
 * at least 32 characters, an HMAC-SHA256 key's own length. A key read from the environment or another parameter
 * (`%env(MAIL_STOP_KEY)%`, `%kernel.secret%`) is only known at run time, where an empty one fails the first link.
 */
final class MailStopKeysBounds implements CompilerPassInterface
{
    public const string STOP_KEYS = 'app.notification.stop_keys';
    private const int SHORTEST_WRITTEN = 32;

    public function process(ContainerBuilder $container): void
    {
        $keys = self::listOf($container->getParameter(self::STOP_KEYS));
        if (null === $keys || [] === $keys) {
            throw self::refused('it is no list of keys');
        }
        foreach ($keys as $position => $key) {
            if (!\is_string($key) || '' === $key || (!str_contains($key, '%') && \strlen($key) < self::SHORTEST_WRITTEN)) {
                throw self::refused(\sprintf('key %d is empty, not text or too short', $position + 1));
            }
        }
    }

    /**
     * Read as what services.yaml may hold, whatever the compiled container's type says: an operator can write text there.
     *
     * @return list<mixed>|null null for what is no list
     */
    private static function listOf(mixed $value): ?array
    {
        return \is_array($value) && array_is_list($value) ? $value : null;
    }

    /** Says where, never what: a key is a secret, and this message reaches the logs. */
    private static function refused(string $where): InvalidArgumentException
    {
        return new InvalidArgumentException(\sprintf('%s is a list of one key or more, a key written out at least %d characters: %s.', self::STOP_KEYS, self::SHORTEST_WRITTEN, $where));
    }
}
