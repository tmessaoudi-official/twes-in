<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain\Service;

/**
 * How an issued document is chained to the one issued before it in its series: SHA-256 over what it says and the hash
 * before it, so a figure changed afterwards, or a document taken out of the middle or put in, breaks the chain from
 * there. The newest one taken out leaves a shorter chain that holds: what shows it is the series' own counter.
 *
 * What is hashed is written out the same way whoever reads it: keys in a fixed order, decimals at their column's
 * scale and lines by their place, because the hash made at issue is checked against what the database gives back
 * (`1300` is stored and read as `1300.000`). Only what never moves after issue is in it: what is paid, credited or
 * due, the status and the stored PDF all change on an issued document. A new version adds what it covers and keeps
 * this one readable, since a document is checked against the version it was sealed with.
 */
final class InvoiceChain
{
    public const int VERSION = 1;

    /** The column scales of what is hashed: amounts and quantities 3, unit prices 4. */
    private const int AMOUNT_SCALE = 3;
    private const int PRICE_SCALE = 4;

    /** How long the fingerprint a printed document carries is, in hexadecimal digits. */
    private const int FINGERPRINT_LENGTH = 16;

    /**
     * @param array{
     *     company: string, establishment: string, type: string, id: string, number: string, issueDate: string,
     *     customer: string, corrects: string|null, totalNet: string, totalTax: string, totalGross: string,
     *     withholding: string, lines: list<array{position: int, description: string, quantity: string, unitPriceNet: string, net: string, tax: string, gross: string}>
     * } $document
     *
     * @throws \LogicException on a version this release cannot read
     */
    public static function hash(int $version, int $position, ?string $previous, array $document): string
    {
        if (self::VERSION !== $version) {
            throw new \LogicException(\sprintf('No chain version %d is known.', $version));
        }

        $lines = $document['lines'];
        usort($lines, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return hash('sha256', json_encode([
            'v' => $version,
            'position' => $position,
            'previous' => $previous,
            'company' => $document['company'],
            'establishment' => $document['establishment'],
            'type' => $document['type'],
            'id' => $document['id'],
            'number' => $document['number'],
            'issueDate' => $document['issueDate'],
            'customer' => $document['customer'],
            'corrects' => $document['corrects'],
            'totalNet' => self::scaled($document['totalNet'], self::AMOUNT_SCALE),
            'totalTax' => self::scaled($document['totalTax'], self::AMOUNT_SCALE),
            'totalGross' => self::scaled($document['totalGross'], self::AMOUNT_SCALE),
            'withholding' => self::scaled($document['withholding'], self::AMOUNT_SCALE),
            'lines' => array_map(static fn (array $line): array => [
                'position' => $line['position'],
                'description' => $line['description'],
                'quantity' => self::scaled($line['quantity'], self::AMOUNT_SCALE),
                'unitPriceNet' => self::scaled($line['unitPriceNet'], self::PRICE_SCALE),
                'net' => self::scaled($line['net'], self::AMOUNT_SCALE),
                'tax' => self::scaled($line['tax'], self::AMOUNT_SCALE),
                'gross' => self::scaled($line['gross'], self::AMOUNT_SCALE),
            ], $lines),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    /** The start of a hash, in groups of four, which a printed document carries for a reader to compare. */
    public static function fingerprint(string $hash): string
    {
        return implode(' ', str_split(substr($hash, 0, self::FINGERPRINT_LENGTH), 4));
    }

    /** @param numeric-string|string $decimal */
    private static function scaled(string $decimal, int $scale): string
    {
        if (!is_numeric($decimal)) {
            throw new \LogicException(\sprintf('"%s" is not a decimal.', $decimal));
        }

        // Rounded as the column stores it, then written with every decimal: `1300` and `1300.000` are one amount.
        return bcadd(new \BcMath\Number($decimal)->round($scale)->value, '0', $scale);
    }
}
