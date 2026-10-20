<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application\Export;

/**
 * A file an accountant's software takes in as the company's books, a journal or a tax summary, rather than a list a
 * person reads. It stands for real records wherever it lands, so a demo, whose documents are worth nothing, never hands
 * one out, as it hands out no Factur-X.
 */
interface ExportForTheBooks extends DeclaresExport
{
}
