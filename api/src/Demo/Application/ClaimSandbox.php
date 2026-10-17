<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Demo\Domain\Model\DemoSandbox;
use App\Demo\Domain\Repository\DemoSandboxRepository;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Company\CreateCompany;
use Psr\Clock\ClockInterface;

/**
 * A visitor takes the ready sandbox of the country they picked, which is theirs alone from then on, and the pool queues
 * its replacement. None ready (the worker is still building, or the demo is full) answers null. The claim is on the
 * company's journal, under its owner, as the visitor's own first line.
 */
final readonly class ClaimSandbox
{
    public const string CLAIMED = 'demo.sandbox_claimed';

    public function __construct(
        private DemoSandboxRepository $sandboxes,
        private KeepSandboxPool $pool,
        private AuditTrail $audit,
        private ReadSetting $settings,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DemoSwitchedOff           when the platform's operators switched the demo off, ready sandboxes or not
     * @throws \InvalidArgumentException when the country has no demo
     */
    public function handle(string $country): ?ClaimedSandbox
    {
        if (true !== $this->settings->value(new SettingContext(), DemoSettings::ENABLED)) {
            throw new DemoSwitchedOff();
        }
        if (!\in_array($country, DemoSandbox::COUNTRIES, true)) {
            throw new \InvalidArgumentException(\sprintf('There is no demo for the country "%s".', $country));
        }
        $claimed = $this->transactions->run(function () use ($country): ?ClaimedSandbox {
            $sandbox = $this->sandboxes->lockedReadyOf($country);
            if (null === $sandbox) {
                return null;
            }
            $sandbox->claim($this->clock->now());
            $this->sandboxes->save($sandbox);
            $claimed = new ClaimedSandbox(
                $sandbox->getId(),
                $sandbox->getCompanyId() ?? throw new \LogicException('A ready sandbox has a company.'),
                $sandbox->getOwnerId() ?? throw new \LogicException('A ready sandbox has an owner.'),
            );
            $this->audit->record(new AuditEntry(CreateCompany::ENTITY_TYPE, $claimed->companyId, self::CLAIMED, $claimed->ownerId, [], $claimed->companyId));

            return $claimed;
        });
        $this->pool->handle();

        return $claimed;
    }
}
