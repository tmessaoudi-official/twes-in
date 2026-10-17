<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Domain\Repository;

use App\Demo\Domain\Model\DemoMail;
use Symfony\Component\Uid\Uuid;

interface DemoMailRepository
{
    public function save(DemoMail $mail): void;

    /** @return list<DemoMail> the mails kept for the sandbox, the latest first */
    public function ofSandbox(Uuid $sandboxId): array;
}
