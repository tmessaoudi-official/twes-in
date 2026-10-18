<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Reports\Infrastructure;

use App\Module\Reports\Application\ReportColumnKind;
use App\Module\Reports\Infrastructure\ApiPlatform\Resource\ReportCatalogueResource;
use PHPUnit\Framework\TestCase;

/** The kinds the API document announces are the kinds a column can have, so the screen's generated type knows each one. */
final class ReportCatalogueResourceTest extends TestCase
{
    public function testTheDocumentNamesEveryColumnKind(): void
    {
        self::assertSame(
            array_map(static fn (ReportColumnKind $kind): string => $kind->value, ReportColumnKind::cases()),
            ReportCatalogueResource::COLUMNS['items']['properties']['kind']['enum'],
        );
    }
}
