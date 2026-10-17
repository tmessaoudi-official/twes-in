<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Application;

use App\Demo\Domain\Model\DemoSandbox;
use App\Demo\Domain\Repository\DemoSandboxRepository;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Keeps one sandbox ready, or on its way, for each country a visitor may pick, within the most the platform allows at
 * once; nothing while the demo is switched off. Run on the worker's schedule and after every claim.
 */
final readonly class KeepSandboxPool
{
    public function __construct(
        private DemoSandboxRepository $sandboxes,
        private SandboxBuilds $builds,
        private ReadSetting $settings,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** @return int how many sandboxes it queued */
    public function handle(): int
    {
        if (true !== $this->settings->value(new SettingContext(), DemoSettings::ENABLED)) {
            return 0;
        }
        $most = $this->settings->value(new SettingContext(), DemoSettings::MOST);
        if (!\is_int($most)) {
            throw new \UnexpectedValueException('The most demo sandboxes at once is not a number.');
        }

        return $this->transactions->run(function () use ($most): int {
            $this->sandboxes->lockPool();
            $waiting = $this->sandboxes->waitingByCountry();
            $room = $most - $this->sandboxes->count();
            $queued = 0;
            foreach (DemoSandbox::COUNTRIES as $country) {
                if ($room <= 0 || ($waiting[$country] ?? 0) > 0) {
                    continue;
                }
                $sandbox = new DemoSandbox(Uuid::v7(), $country, $this->clock->now());
                $this->sandboxes->save($sandbox);
                $this->builds->queue($sandbox->getId());
                --$room;
                ++$queued;
            }

            return $queued;
        });
    }
}
