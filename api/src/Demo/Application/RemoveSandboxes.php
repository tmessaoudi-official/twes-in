<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Demo\Domain\Repository\DemoSandboxRepository;
use App\Files\Application\Files;
use App\Identity\Application\Session\ManageSessions;
use App\Identity\Domain\Repository\UserRepository;
use App\Settings\Application\ForgetSettings;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Company\CreateCompany;
use App\Tenancy\Domain\Repository\CompanyRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Takes sandboxes away whole, each in a transaction of its own: its company with every row that is the company's, then
 * its owner, then the bytes of its files once that is committed. The journal keeps its lines, which name the company by
 * its id only (ruled: they are all that stays). A claimed sandbox is taken away once its visitor is done with it, signed
 * out or away longer than a demo session stays signed in; a build stopped halfway once it has been building longer than
 * a build ever takes, so the pool queues its country again.
 */
final readonly class RemoveSandboxes
{
    public const string REMOVED = 'demo.sandbox_removed';

    public function __construct(
        private DemoSandboxRepository $sandboxes,
        private CompanyRepository $companies,
        private UserRepository $users,
        private ForgetSettings $settings,
        private Files $files,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        #[Autowire(param: 'app.demo.build_gives_up_after')]
        private string $givesUpAfter,
        #[Autowire(param: 'app.demo.idle_ttl')]
        private int $idleTtl,
    ) {
    }

    /**
     * @return int how many sandboxes their visitor is done with were taken away: signed out, or away longer than a demo
     *             session stays signed in, give or take the record's own precision
     */
    public function doneWith(): int
    {
        $lastUsedBefore = $this->clock->now()->sub(new \DateInterval(\sprintf('PT%dS', $this->idleTtl)))->sub(new \DateInterval(ManageSessions::TOUCH_EVERY));
        $removed = 0;
        foreach ($this->sandboxes->doneWith($lastUsedBefore) as $sandboxId) {
            $removed += $this->remove($sandboxId) ? 1 : 0;
        }

        return $removed;
    }

    /** @return int how many sandboxes stopped halfway were taken away */
    public function stale(): int
    {
        $removed = 0;
        foreach ($this->sandboxes->buildingSince($this->clock->now()->sub(new \DateInterval($this->givesUpAfter))) as $sandboxId) {
            $removed += $this->remove($sandboxId) ? 1 : 0;
        }

        return $removed;
    }

    private function remove(Uuid $sandboxId): bool
    {
        $keys = $this->transactions->run(function () use ($sandboxId): ?array {
            $sandbox = $this->sandboxes->lockedOfId($sandboxId);
            if (null === $sandbox) {
                return null;
            }
            $companyId = $sandbox->getCompanyId();
            $ownerId = $sandbox->getOwnerId();
            $keys = [];
            $this->sandboxes->remove($sandbox);
            if (null !== $companyId) {
                $company = $this->companies->ofId($companyId) ?? throw new \LogicException('A sandbox\'s company is removed with it.');
                $keys = $this->files->storageKeysOf($companyId);
                $this->companies->removeWhole($company);
                $this->audit->record(new AuditEntry(CreateCompany::ENTITY_TYPE, $companyId, self::REMOVED, null));
            }
            if (null !== $ownerId) {
                $owner = $this->users->ofId($ownerId);
                if (null !== $owner) {
                    $this->settings->at(SettingAddress::user($ownerId));
                    $this->users->remove($owner);
                }
            }

            return $keys;
        });
        if (null === $keys) {
            return false;
        }
        $this->files->deleteContents($keys);

        return true;
    }
}
