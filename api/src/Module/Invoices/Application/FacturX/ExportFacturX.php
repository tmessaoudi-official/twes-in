<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\FacturX;

use App\Files\Application\StoredFileCorrupted;
use App\Files\Application\StoredFileMissing;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\PrintInvoice;
use App\Shared\Application\FacturXPdf;
use App\Shared\Application\PdfRenderingFailed;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * An issued invoice's or credit note's Factur-X (docs/SPEC.md § 8 row 145): its Cross Industry Invoice alone, or embedded
 * in the PDF it was issued with. Nothing is stored: the XML is written from what issuing fixed each time it is asked.
 */
final readonly class ExportFacturX
{
    public function __construct(private DescribeFacturX $describe, private CiiInvoiceXml $xml, private PrintInvoice $print, private FacturXPdf $pdf)
    {
    }

    /**
     * @throws InvoiceNotFound
     * @throws FacturXRefused
     */
    public function xml(Company $company, Uuid $id): FacturXFile
    {
        $invoice = $this->describe->describe($company, $id);

        return new FacturXFile(self::fileName($invoice->number).'.xml', $this->xml->write($invoice));
    }

    /**
     * The XML is written first, so a document it refuses never reaches the PDF engine.
     *
     * @throws InvoiceNotFound
     * @throws FacturXRefused
     * @throws PdfRenderingFailed
     * @throws StoredFileMissing
     * @throws StoredFileCorrupted
     */
    public function pdf(Company $company, Uuid $id): FacturXFile
    {
        $invoice = $this->describe->describe($company, $id);
        $xml = $this->xml->write($invoice);

        return new FacturXFile(self::fileName($invoice->number).'-factur-x.pdf', $this->pdf->embed($this->print->pdf($company, $id)->contents, $xml));
    }

    /** A numbering format may carry slashes, which a file name may not. */
    private static function fileName(string $number): string
    {
        return str_replace('/', '-', $number);
    }
}
