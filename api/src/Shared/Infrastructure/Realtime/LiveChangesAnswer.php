<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Realtime;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Names in the answer what the request changed, for the tab that made it: the connection says a change to every tab
 * but that one, so without this a panel of the acting tab (a count, the bell, another list) would stay as it was until
 * a reload. A proxy refuses an answer whose headers outgrow its buffer (4 KB by default for nginx), so past 2 KB the
 * list is folded to one bulk change per kind and action, which every screen already reads as « read your list again »,
 * and what still does not fit is left out.
 */
final readonly class LiveChangesAnswer
{
    public const string HEADER = 'X-Live-Changes';

    private const int MOST_BYTES = 2048;

    public function __construct(private StagedLiveChanges $changes)
    {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $header = self::header($this->changes->saidHere());
        if (null !== $header) {
            $event->getResponse()->headers->set(self::HEADER, $header);
        }
    }

    /**
     * @param list<array{kind: string, id: ?string, action: string, bulk?: true, count?: int}> $said
     */
    public static function header(array $said): ?string
    {
        if ([] === $said) {
            return null;
        }
        $whole = self::encode($said);
        if (\strlen($whole) <= self::MOST_BYTES) {
            return $whole;
        }
        $folded = [];
        foreach ($said as $change) {
            $key = $change['kind'].'|'.$change['action'];
            $folded[$key] ??= ['kind' => $change['kind'], 'id' => null, 'action' => $change['action'], 'bulk' => true, 'count' => 0];
            $folded[$key]['count'] += $change['count'] ?? 1;
        }
        $kept = [];
        foreach ($folded as $change) {
            if (\strlen(self::encode([...$kept, $change])) > self::MOST_BYTES) {
                break;
            }
            $kept[] = $change;
        }

        return [] === $kept ? null : self::encode($kept);
    }

    /** @param list<array<string, mixed>> $changes */
    private static function encode(array $changes): string
    {
        return json_encode($changes, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
