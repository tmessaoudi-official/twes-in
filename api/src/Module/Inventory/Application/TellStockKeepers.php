<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\MembershipRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Tells the people who keep a company's stock (its members whose role grants `stock.write`) what a delivery note did not
 * move: the note is already committed, so a log line alone would reach nobody. Each is
 * told on their own channel, so a member who cannot act on it never sees it.
 */
final readonly class TellStockKeepers
{
    public const string PERMISSION = 'stock.write';
    /** Some lines of a validated note moved no stock: another unit than the product's, or no quantity. */
    public const string LINES_LEFT_OUT = 'stock.delivery_note_lines_left_out';
    /** Moving a note's stock failed and nothing moved; `app:stock:replay-delivery-note` moves it again. */
    public const string MOVED_NO_STOCK = 'stock.delivery_note_moved_no_stock';

    /** Some lines of an issued invoice moved no stock, for the reasons a delivery note's lines may not. */
    public const string INVOICE_LINES_LEFT_OUT = 'stock.invoice_lines_left_out';
    /** Moving an invoice's stock failed and nothing moved. */
    public const string INVOICE_MOVED_NO_STOCK = 'stock.invoice_moved_no_stock';
    /** A credit note's returned lines brought back nothing, or less than they said. */
    public const string CREDIT_LINES_NOT_RETURNED = 'stock.credit_lines_not_returned';
    /** Returning a credit note's goods failed and nothing came back. */
    public const string CREDIT_MOVED_NO_STOCK = 'stock.credit_moved_no_stock';

    /** A count found a different quantity from the one expected: told to the other keepers, the counter knowing. */
    public const string COUNT_DIFFERENCE = 'stock.count_difference';
    /** A product's stock fell to its reorder point in an establishment. */
    public const string LOW = 'stock.low';
    /** A file's counts found differences: told once for the file, the person who imported it knowing. */
    public const string IMPORTED = 'stock.imported';

    public function __construct(private MembershipRepository $memberships, private Notifications $notifications)
    {
    }

    /** @param array<string, scalar|null> $payload what the notification says, without the company */
    public function countDifference(Uuid $companyId, ?Uuid $counter, array $payload): void
    {
        $this->tellAll($companyId, self::COUNT_DIFFERENCE, $payload, $counter);
    }

    /** @param array<string, scalar|null> $payload what the notification says, without the company */
    public function imported(Uuid $companyId, ?Uuid $importer, array $payload): void
    {
        $this->tellAll($companyId, self::IMPORTED, $payload, $importer);
    }

    /** @param array<string, scalar|null> $payload what the notification says, without the company */
    public function low(Uuid $companyId, array $payload): void
    {
        $this->tellAll($companyId, self::LOW, $payload, null);
    }

    public function deliveryNoteLeftLinesOut(Uuid $companyId, Uuid $deliveryNoteId, string $number): void
    {
        $this->tell($companyId, self::LINES_LEFT_OUT, $deliveryNoteId, $number);
    }

    public function deliveryNoteMovedNoStock(Uuid $companyId, Uuid $deliveryNoteId, string $number): void
    {
        $this->tell($companyId, self::MOVED_NO_STOCK, $deliveryNoteId, $number);
    }

    public function invoiceLeftLinesOut(Uuid $companyId, Uuid $invoiceId, string $number): void
    {
        $this->tellAll($companyId, self::INVOICE_LINES_LEFT_OUT, ['invoice_id' => $invoiceId->toRfc4122(), 'number' => $number], null);
    }

    public function invoiceMovedNoStock(Uuid $companyId, Uuid $invoiceId, string $number): void
    {
        $this->tellAll($companyId, self::INVOICE_MOVED_NO_STOCK, ['invoice_id' => $invoiceId->toRfc4122(), 'number' => $number], null);
    }

    public function creditLinesNotReturned(Uuid $companyId, Uuid $creditNoteId, string $number): void
    {
        $this->tellAll($companyId, self::CREDIT_LINES_NOT_RETURNED, ['invoice_id' => $creditNoteId->toRfc4122(), 'number' => $number], null);
    }

    public function creditMovedNoStock(Uuid $companyId, Uuid $creditNoteId, string $number): void
    {
        $this->tellAll($companyId, self::CREDIT_MOVED_NO_STOCK, ['invoice_id' => $creditNoteId->toRfc4122(), 'number' => $number], null);
    }

    private function tell(Uuid $companyId, string $type, Uuid $deliveryNoteId, string $number): void
    {
        $this->tellAll($companyId, $type, ['delivery_note_id' => $deliveryNoteId->toRfc4122(), 'number' => $number], null);
    }

    /** @param array<string, scalar|null> $payload */
    private function tellAll(Uuid $companyId, string $type, array $payload, ?Uuid $except): void
    {
        foreach ($this->memberships->ofCompany($companyId) as $membership) {
            $user = $membership->getUser()->getId();
            if ($membership->getRole()->grants(self::PERMISSION) && !$user->equals($except)) {
                $this->notifications->publish(new Notification('user:'.$user->toRfc4122(), $type, [...$payload, 'company' => self::company($membership)], $companyId->toRfc4122()));
            }
        }
    }

    private static function company(Membership $membership): string
    {
        return $membership->getCompany()->getName();
    }
}
