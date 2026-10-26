<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Application;

use App\Audit\Domain\ValueObject\AuditScope;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What a class records in the audit trail, declared beside the constants it records them with: the trail refuses an
 * action nobody declares, and a company's journal must say each one it shows in words, so an action added without its
 * declaration reds the first test that records it rather than reaching a journal as its raw name.
 */
#[AutoconfigureTag('app.audit.actions')]
interface DeclaresAuditActions
{
    /** @return array<string, AuditScope> each action it records, by where it records it */
    public function auditActions(): array;
}
