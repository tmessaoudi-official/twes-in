<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Effects;

use App\Shared\Application\Effect;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Says in the answer what the request did beyond its subject, for the screen of whoever made it to say at once. A
 * proxy refuses an answer whose headers outgrow its buffer (4 KB by default for nginx), and `X-Live-Changes` may take
 * 2 KB of it, so past 1 KB the effects are folded to one per kind with how many there were and none of their figures,
 * which a screen says as a count, and what still does not fit is left out.
 */
final readonly class EffectsAnswer
{
    public const string HEADER = 'X-Effects';

    private const int MOST_BYTES = 1024;

    public function __construct(private StagedEffects $effects)
    {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $header = self::header($this->effects->saidHere());
        if (null !== $header) {
            $event->getResponse()->headers->set(self::HEADER, $header);
        }
    }

    /**
     * @param list<Effect> $said
     */
    public static function header(array $said): ?string
    {
        if ([] === $said) {
            return null;
        }
        $whole = self::encode(array_map(static fn (Effect $effect): array => ['kind' => $effect->kind, 'id' => $effect->id?->toRfc4122(), 'params' => (object) $effect->params], $said));
        if (\strlen($whole) <= self::MOST_BYTES) {
            return $whole;
        }
        $counts = [];
        foreach ($said as $effect) {
            $counts[$effect->kind] = ($counts[$effect->kind] ?? 0) + 1;
        }
        $kept = [];
        foreach ($counts as $kind => $count) {
            $folded = ['kind' => $kind, 'id' => null, 'params' => (object) [], 'count' => $count];
            if (\strlen(self::encode([...$kept, $folded])) > self::MOST_BYTES) {
                break;
            }
            $kept[] = $folded;
        }

        return [] === $kept ? null : self::encode($kept);
    }

    /** @param list<array<string, mixed>> $effects */
    private static function encode(array $effects): string
    {
        return json_encode($effects, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
