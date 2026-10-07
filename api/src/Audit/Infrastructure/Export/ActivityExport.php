<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\Export;

use App\Audit\Application\ReadActivity;
use App\Audit\Infrastructure\ApiPlatform\ActivitySearchReader;
use App\Audit\Infrastructure\ApiPlatform\AuditPermission;
use App\ImportExport\Application\DeclaresExport;
use App\ImportExport\Application\ExportQuery;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;

/**
 * The activity journal as a file (docs/SPEC.md § 7, 2026-09-26 23:04), under the filters its screen shows and within
 * what the company keeps. The addresses are left out: a file travels further than a screen, and who reads it later is
 * not known.
 */
final readonly class ActivityExport implements DeclaresExport
{
    public const string KEY = 'activity';
    private const int BATCH = 200;
    private const array COLUMNS = ['at', 'actor', 'action', 'entity_type', 'entity_id', 'fields'];

    public function __construct(private ReadActivity $read)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function permission(): string
    {
        return AuditPermission::READ;
    }

    public function module(): ?string
    {
        return null;
    }

    public function columns(Company $company): array
    {
        return self::COLUMNS;
    }

    public function rows(Company $company, ExportQuery $query): iterable
    {
        $search = ActivitySearchReader::read($query->parameters(), $query->text(), $company);
        $zone = new \DateTimeZone($company->getTimezone());

        for ($page = 1;; ++$page) {
            $answer = $this->read->search($company, $search, new PageRequest($page, self::BATCH));
            foreach ($answer->items as $entry) {
                yield [
                    $entry->at->setTimezone($zone)->format('Y-m-d H:i:s'),
                    $entry->actorName ?? '',
                    $entry->action,
                    $entry->entityType,
                    $entry->entityId?->toRfc4122() ?? '',
                    implode(' ', $entry->fields),
                ];
            }
            if ($page * self::BATCH >= $answer->total || [] === $answer->items) {
                return;
            }
        }
    }
}
