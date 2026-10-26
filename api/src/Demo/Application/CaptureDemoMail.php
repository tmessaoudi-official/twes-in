<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Demo\Domain\Model\DemoMail;
use App\Demo\Domain\Model\DemoSandbox;
use App\Demo\Domain\Repository\DemoMailRepository;
use App\Demo\Domain\Repository\DemoSandboxRepository;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Service\TypedText;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A demo's mail never leaves: whatever the application sends to a sandbox's owner, built or being built, is kept for
 * « Courriers de la démo » instead. A mail naming a sandbox's owner among others is kept whole and sent to none of
 * them, since it speaks of a made-up company; nothing the application sends does that today. Each mail kept is a line of
 * the sandbox company's journal, which is also what tells its open page to read the mails again.
 */
final readonly class CaptureDemoMail implements DeclaresAuditActions
{
    public const string ENTITY_TYPE = 'demo_mail';
    public const string CAPTURED = 'demo.mail_captured';

    public function __construct(
        private DemoSandboxRepository $sandboxes,
        private DemoMailRepository $mails,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $recipients every address the mail goes to, copies included
     *
     * @return bool whether the mail is a demo's, kept and not to be sent
     */
    public function handle(array $recipients, string $subject, string $html): bool
    {
        $sandboxes = [];
        foreach ($recipients as $recipient) {
            $sandbox = $this->sandboxes->ownedByAddress(mb_strtolower(TypedText::trimmed($recipient)));
            if (null !== $sandbox) {
                $sandboxes[$sandbox->getId()->toRfc4122()] = $sandbox;
            }
        }
        if ([] === $sandboxes) {
            return false;
        }
        $this->transactions->run(function () use ($sandboxes, $subject, $html): void {
            foreach ($sandboxes as $sandbox) {
                $this->keep($sandbox, $subject, $html);
            }
        });

        return true;
    }

    private function keep(DemoSandbox $sandbox, string $subject, string $html): void
    {
        $mail = new DemoMail(Uuid::v7(), $sandbox, $subject, $html, $this->clock->now());
        $this->mails->save($mail);
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $mail->getId(), self::CAPTURED, null, [], $sandbox->getCompanyId()));
    }

    public function auditActions(): array
    {
        return [
            self::CAPTURED => AuditScope::Company,
        ];
    }
}
