<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Session;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\Model\UserSession;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use App\Shared\Application\RealtimeCuts;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Says a session ended to its person's open screens at once, whatever ended it: a new security stamp ends every
 * session of the account (an operator's « Terminer les sessions » or « Désactiver », a new password, factors replaced,
 * a deletion asked), a session marked revoked ends that one (« Déconnecter les autres », a sign-out). Each screen
 * asks again whether its own still stands, and the realtime connections of what ended are closed with it: every
 * session's when the stamp changed, that session's alone when one was revoked, and nobody else's. Read from what is
 * flushed, so a new way of ending a session is said with no call to remember; one flushed outside Transactions::run()
 * is not, as no change is said there.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class SessionEndsSaid
{
    public const string KIND = 'session';
    public const string ENDED = 'session.ended';

    public function __construct(private LiveChanges $changes, private RealtimeCuts $cuts)
    {
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        $work = $event->getObjectManager()->getUnitOfWork();
        foreach ($work->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof User && \array_key_exists('securityStamp', $work->getEntityChangeSet($entity))) {
                $this->said($entity);
                $this->cuts->cut($entity->getId());
            } elseif ($entity instanceof UserSession && self::revokedNow($work->getEntityChangeSet($entity))) {
                $this->said($entity->getUser());
                $this->cuts->cutSession($entity->getUser()->getId(), $entity->getId());
            }
        }
    }

    /** @param array<string, mixed> $changes what the flush changes of a session's record */
    private static function revokedNow(array $changes): bool
    {
        $revoked = $changes['revokedAt'] ?? null;

        return \is_array($revoked) && null === $revoked[0] && null !== $revoked[1];
    }

    private function said(User $user): void
    {
        $this->changes->stage(new LiveChange(self::KIND, null, self::ENDED, null, null, forUserId: $user->getId()));
    }
}
