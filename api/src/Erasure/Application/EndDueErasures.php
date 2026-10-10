<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Erasure\Domain\DataErasureRepository;
use App\Files\Application\Files;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Ends every erasure whose 24 hours are over: it becomes final, its copy is deleted, and so are the files only the copy
 * still named. Running it again ends nothing twice. The files' bytes go once their records are gone for good, after the
 * commit: bytes left behind by a crash in between are only unused, never served.
 */
final readonly class EndDueErasures
{
    public const string ENDED = 'data.erasure_final';

    public function __construct(
        private DataErasureRepository $erasures,
        private ErasureStore $store,
        private ErasureCatalogue $catalogue,
        private Files $files,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** @return int how many erasures it ended */
    public function handle(?Uuid $companyId = null): int
    {
        $now = $this->clock->now();
        $ended = 0;
        foreach ($this->erasures->dueAt($now, $companyId) as $id) {
            $keys = $this->transactions->run(function () use ($id, $now): ?array {
                $erasure = $this->erasures->lockedOfId($id);
                if (null === $erasure || !$erasure->finishIfDue($now)) {
                    return null;
                }
                $company = $erasure->getCompany()->getId();
                $named = $this->store->forget($company, $id, $this->catalogue->files());
                $this->erasures->save($erasure);
                // Nobody ends it: its time does, so the journal names no one.
                $this->audit->record(new AuditEntry(EraseData::ENTITY_TYPE, $id, self::ENDED, null, ['parts' => $erasure->getParts(), 'counts' => $erasure->getCounts()], $company));

                return $this->files->forget($company, $named);
            });
            if (null !== $keys) {
                $this->files->deleteContents($keys);
                ++$ended;
            }
        }

        return $ended;
    }
}
