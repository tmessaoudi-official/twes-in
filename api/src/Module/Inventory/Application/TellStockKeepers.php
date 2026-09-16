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
 * move (docs/SPEC.md § 7, 2026-09-16): the note is already committed, so a log line alone would reach nobody. Each is
 * told on their own channel, so a member who cannot act on it never sees it.
 */
final readonly class TellStockKeepers
{
    public const string PERMISSION = 'stock.write';
    /** Some lines of a validated note moved no stock: another unit than the product's, or no quantity. */
    public const string LINES_LEFT_OUT = 'stock.delivery_note_lines_left_out';
    /** Moving a note's stock failed and nothing moved; `app:stock:replay-delivery-note` moves it again. */
    public const string MOVED_NO_STOCK = 'stock.delivery_note_moved_no_stock';

    public function __construct(private MembershipRepository $memberships, private Notifications $notifications)
    {
    }

    public function deliveryNoteLeftLinesOut(Uuid $companyId, Uuid $deliveryNoteId, string $number): void
    {
        $this->tell($companyId, self::LINES_LEFT_OUT, $deliveryNoteId, $number);
    }

    public function deliveryNoteMovedNoStock(Uuid $companyId, Uuid $deliveryNoteId, string $number): void
    {
        $this->tell($companyId, self::MOVED_NO_STOCK, $deliveryNoteId, $number);
    }

    private function tell(Uuid $companyId, string $type, Uuid $deliveryNoteId, string $number): void
    {
        foreach ($this->memberships->ofCompany($companyId) as $membership) {
            if ($membership->getRole()->grants(self::PERMISSION)) {
                $this->notifications->publish(new Notification(
                    'user:'.$membership->getUser()->getId()->toRfc4122(),
                    $type,
                    ['delivery_note_id' => $deliveryNoteId->toRfc4122(), 'number' => $number, 'company' => self::company($membership)],
                ));
            }
        }
    }

    private static function company(Membership $membership): string
    {
        return $membership->getCompany()->getName();
    }
}
