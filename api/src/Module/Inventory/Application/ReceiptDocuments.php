<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Module\Inventory\Domain\InvalidStockMovement;
use App\Module\Inventory\Domain\ReceiptDocument;
use App\Module\Vendors\Domain\VendorRepository;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The document a goods receipt names, from what the request said: the vendor looked up in the company's own, the
 * reference, and the day read as a calendar day. Nothing named is no document, so a receipt typed with none of it
 * writes none. The vendor is named by id and only while Vendors is switched on, as an expense names it.
 */
final readonly class ReceiptDocuments
{
    /** The module whose records a receipt's vendor is: named by key, as the application layer knows no manifest. */
    public const string VENDORS_MODULE = 'vendors';

    public function __construct(private VendorRepository $vendors, private ModuleStates $modules)
    {
    }

    /** @throws InvalidStockMovement */
    public function named(Company $company, ?string $vendorId, ?string $supplierReference, ?string $receivedOn): ?ReceiptDocument
    {
        $vendor = null;
        if (null !== $vendorId && '' !== $vendorId) {
            if (!$this->modules->isEnabled($company->getId(), self::VENDORS_MODULE)) {
                throw new InvalidStockMovement('vendorId', 'Vendors is switched off: a receipt names no vendor.');
            }
            $vendor = Uuid::isValid($vendorId) ? $this->vendors->ofIdInCompany(Uuid::fromString($vendorId), $company->getId()) : null;
            if (null === $vendor) {
                throw new InvalidStockMovement('vendorId', 'No vendor of this company has this id.');
            }
        }
        $document = new ReceiptDocument($vendor, $supplierReference, self::day($receivedOn));

        return $document->isEmpty() ? null : $document;
    }

    /** @throws InvalidStockMovement */
    private static function day(?string $text): ?\DateTimeImmutable
    {
        if (null === $text || '' === trim($text)) {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($text));
        // `2026-13-45` is not refused by the parser: it rolls into the next year, so the day must read back as typed.
        if (false === $day || $day->format('Y-m-d') !== trim($text)) {
            throw new InvalidStockMovement('receivedOn', 'A day is written year-month-day, as 2026-09-12.');
        }

        return $day;
    }
}
