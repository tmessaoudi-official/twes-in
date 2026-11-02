<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Fuzz;

use App\Shared\Application\Spreadsheet\SpreadsheetFormat;

/** One hostile file of the corpus: what it is called when it fails, the name it is sent under, and its bytes. */
final readonly class FuzzFile
{
    /**
     * @param bool $declaresDoctype a workbook one of whose parts declares a document type, which is refused before anything parses it
     * @param bool $rowLevel        built on the subject's own header, so its hostile values reach the subject's import of a row
     */
    public function __construct(
        public string $name,
        public string $filename,
        public string $bytes,
        public bool $declaresDoctype = false,
        public bool $rowLevel = false,
    ) {
    }

    /** Null for a name the import does not read at all (an .ods), which is refused before the file is opened. */
    public function format(): ?SpreadsheetFormat
    {
        return SpreadsheetFormat::ofFilename($this->filename);
    }
}
