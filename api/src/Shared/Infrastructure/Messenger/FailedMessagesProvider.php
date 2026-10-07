<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Reads the failed transport as `messenger:failed:show` does, through the receiver, never its table: the message's
 * class is inside the serialized body, which only the transport's serializer reads.
 *
 * @implements ProviderInterface<FailedMessages>
 */
final readonly class FailedMessagesProvider implements ProviderInterface
{
    public function __construct(#[Autowire(service: 'messenger.transport.failed')] private TransportInterface $failed)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): FailedMessages
    {
        if (!$this->failed instanceof ListableReceiverInterface || !$this->failed instanceof MessageCountAwareInterface) {
            throw new \LogicException('The failed transport can no longer be listed and counted; the operator would read nothing.');
        }
        $counts = [];
        foreach ($this->failed->all() as $envelope) {
            $class = $envelope->getMessage()::class;
            $kind = substr($class, (int) strrpos($class, '\\') + 1);
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }
        uksort($counts, static fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);
        $kinds = [];
        foreach ($counts as $kind => $count) {
            $kinds[] = new FailedMessageKind((string) $kind, $count);
        }

        return new FailedMessages($this->failed->getMessageCount(), $kinds);
    }
}
