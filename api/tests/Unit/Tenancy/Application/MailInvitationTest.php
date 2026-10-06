<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Identity\Domain\Email;
use App\Tenancy\Application\Invitation\MailInvitation;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Invitation;
use App\Tenancy\Domain\InvitationToken;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryInvitationMailer;
use App\Tests\Support\InMemoryInvitations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** The worker's side of an invitation: only one still open is given a link and mailed. */
final class MailInvitationTest extends TestCase
{
    private InMemoryInvitations $invitations;
    private InMemoryInvitationMailer $mailer;
    private MockClock $clock;
    private MailInvitation $mail;

    protected function setUp(): void
    {
        $this->invitations = new InMemoryInvitations();
        $this->mailer = new InMemoryInvitationMailer();
        $this->clock = new MockClock('2026-10-06 10:00:00');
        $this->mail = new MailInvitation($this->invitations, $this->mailer, $this->clock, new FakeTransactions(), 'https://twes.test/invitations/{token}');
    }

    public function testAnOpenInvitationIsGivenANewLinkAndMailedIt(): void
    {
        $invitation = $this->invitation();
        $placeholder = $invitation->getTokenHash();

        $this->mail->handle($invitation->getId());

        self::assertCount(1, $this->mailer->sent);
        $raw = substr($this->mailer->sent[0]->acceptUrl, strrpos($this->mailer->sent[0]->acceptUrl, '/') + 1);
        self::assertSame(InvitationToken::hashOf($raw), $invitation->getTokenHash());
        self::assertNotSame($placeholder, $invitation->getTokenHash());
    }

    public function testAnInvitationAcceptedBeforeTheWorkerRanIsNeitherMailedNorChanged(): void
    {
        $invitation = $this->invitation();
        $invitation->accept($this->clock->now());
        $hash = $invitation->getTokenHash();

        $this->mail->handle($invitation->getId());

        self::assertSame([], $this->mailer->sent);
        self::assertSame($hash, $invitation->getTokenHash());
    }

    public function testAnInvitationThatExpiredBeforeTheWorkerRanIsNotMailed(): void
    {
        $invitation = $this->invitation();
        $this->clock->modify('+8 days');

        $this->mail->handle($invitation->getId());

        self::assertSame([], $this->mailer->sent);
    }

    public function testAnInvitationThatNoLongerExistsIsNotMailed(): void
    {
        $this->mail->handle(Uuid::v7());

        self::assertSame([], $this->mailer->sent);
    }

    private function invitation(): Invitation
    {
        $company = Company::pending('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $invitation = new Invitation($company, Email::fromString('joiner@twes.local'), 'member', InvitationToken::generate(), $this->clock->now(), new \DateInterval('P7D'), null);
        $this->invitations->save($invitation);

        return $invitation;
    }
}
