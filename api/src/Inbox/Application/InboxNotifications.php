<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

use App\Identity\Domain\UserRepository;
use App\Inbox\Domain\InboxItem;
use App\Inbox\Domain\InboxRepository;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Shared\Application\RealtimePublisher;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The notification centre behind the `Notifications` port (docs/SPEC.md § 7, 2026-09-13). A `user:` channel keeps
 * one row for that user; a `company:` channel keeps one row for each member of that company at this moment. The
 * rows are written first and the push follows, so a page that was closed, or a push that never arrived, still
 * finds the notification in the centre.
 */
final readonly class InboxNotifications implements Notifications
{
    private const string CHANNEL = '/^(user|company):([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/';

    public function __construct(
        private UserRepository $users,
        private CompanyRepository $companies,
        private MembershipRepository $memberships,
        private InboxRepository $inbox,
        private RealtimePublisher $realtime,
        private ClockInterface $clock,
    ) {
    }

    public function publish(Notification $notification): void
    {
        if (1 !== preg_match(self::CHANNEL, $notification->channel, $match)) {
            throw new \InvalidArgumentException(\sprintf('A notification channel is "user:<uuid>" or "company:<uuid>", "%s" given.', $notification->channel));
        }

        $id = Uuid::fromString($match[2]);
        $now = $this->clock->now();

        if ('user' === $match[1]) {
            $user = $this->users->ofId($id)
                ?? throw new \LogicException(\sprintf('A notification was published to user %s, who does not exist.', $id->toRfc4122()));
            $items = [new InboxItem($user, null, $notification->type, $notification->payload, $now)];
        } else {
            $company = $this->companies->ofId($id)
                ?? throw new \LogicException(\sprintf('A notification was published to company %s, which does not exist.', $id->toRfc4122()));
            $items = array_map(
                static fn (Membership $membership) => new InboxItem($membership->getUser(), $company, $notification->type, $notification->payload, $now),
                $this->memberships->ofCompany($id),
            );
        }

        $this->inbox->add(...$items);

        $this->realtime->push($notification->channel, ['type' => $notification->type, 'payload' => $notification->payload]);
    }
}
