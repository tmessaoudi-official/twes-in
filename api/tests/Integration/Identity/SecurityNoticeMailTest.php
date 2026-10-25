<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Security\LockEnd;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNoticeMail;
use App\Identity\Application\Security\SecurityNoticeMailer;
use App\Tests\Support\ShippedLanguages;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/**
 * Every change to how an account signs in is told in every language the interface speaks: its own heading in the
 * subject, its own sentence, the passkey it concerns named as its owner typed it, and what to do if it was not them,
 * but where the installation's operator reset the factors, which the mail says instead.
 */
final class SecurityNoticeMailTest extends KernelTestCase
{
    public function testEveryChangeIsWrittenInEveryLanguageWithNoKeyLeftUntranslated(): void
    {
        self::bootKernel();
        $mailer = static::getContainer()->get(SecurityNoticeMailer::class);
        self::assertInstanceOf(SecurityNoticeMailer::class, $mailer);

        $languages = ShippedLanguages::catalogue()->interface();
        self::assertContains('ar', $languages);
        $written = 0;
        foreach ($languages as $language) {
            foreach (SecurityChange::cases() as $change) {
                $mailer->send(new SecurityNoticeMail('someone@twes.local', $language, $change, 'Téléphone de Nadia'));
                $mail = $this->lastMail();
                $html = (string) $mail->getHtmlBody();
                $where = "$language {$change->value}";

                self::assertSame('someone@twes.local', $mail->getTo()[0]->getAddress(), $where);
                self::assertStringNotContainsString('security_notice', (string) $mail->getSubject().$html, "$where: every text is found");
                self::assertStringContainsString('dir="'.('ar' === $language ? 'rtl' : 'ltr').'"', $html, $where);
                $passkey = \in_array($change, [SecurityChange::PasskeyAdded, SecurityChange::PasskeyRemoved], true);
                self::assertSame($passkey, str_contains($html, '<bdi dir="auto">Téléphone de Nadia</bdi>'), "$where: the passkey named only where it is the subject");
                ++$written;
            }
        }
        self::assertSame(\count($languages) * \count(SecurityChange::cases()), $written);
    }

    public function testEachChangeHasItsOwnSubjectAndOnlyTheOperatorsResetSaysNoIfItWasNotYou(): void
    {
        self::bootKernel();
        $mailer = static::getContainer()->get(SecurityNoticeMailer::class);
        self::assertInstanceOf(SecurityNoticeMailer::class, $mailer);

        $subjects = [];
        foreach (SecurityChange::cases() as $change) {
            $mailer->send(new SecurityNoticeMail('someone@twes.local', 'fr', $change));
            $mail = $this->lastMail();
            $subjects[] = (string) $mail->getSubject();
            self::assertSame(
                SecurityChange::FactorsReplaced !== $change,
                str_contains((string) $mail->getHtmlBody(), 'Si ce n’est pas vous'),
                $change->value,
            );
        }
        self::assertCount(\count(SecurityChange::cases()), array_unique($subjects));
        self::assertStringContainsString('Application d’authentification remplacée', implode("\n", $subjects));
    }

    /**
     * A lock is told with when it ends, as its reader writes a moment and in the zone named, and with the way to end it
     * now; never the « if it was you » of a change someone made, since nobody signed in to make it.
     */
    public function testALockIsToldWithWhenItEndsAndHowToEndItNow(): void
    {
        self::bootKernel();
        $mailer = static::getContainer()->get(SecurityNoticeMailer::class);
        self::assertInstanceOf(SecurityNoticeMailer::class, $mailer);

        $mailer->send(new SecurityNoticeMail('someone@twes.local', 'fr', SecurityChange::AccountLocked, lockEnd: new LockEnd('2026-10-25 14:30', 'Africa/Tunis', 'dmy')));
        $mail = $this->lastMail();
        $html = (string) $mail->getHtmlBody();

        self::assertSame('Sécurité de votre compte twes-in : Compte verrouillé', str_replace("\u{a0}", ' ', (string) $mail->getSubject()));
        self::assertStringContainsString('jusqu’au 25/10/2026 14:30 (fuseau horaire Africa/Tunis)', $html);
        self::assertStringContainsString('Mot de passe oublié', $html);
        self::assertStringNotContainsString('il n’y a rien à faire', $html);

        foreach (ShippedLanguages::catalogue()->interface() as $language) {
            $mailer->send(new SecurityNoticeMail('someone@twes.local', $language, SecurityChange::AccountLocked, lockEnd: new LockEnd('2026-10-25 14:30', 'UTC')));
            $html = (string) $this->lastMail()->getHtmlBody();
            self::assertStringNotContainsString('security_notice', $html, $language);
            self::assertStringContainsString('UTC', $html, $language);
            self::assertStringContainsString('14:30', $html, $language);
        }
    }

    private function lastMail(): Email
    {
        $messages = self::getMailerMessages();
        $mail = end($messages);
        self::assertInstanceOf(Email::class, $mail);

        return $mail;
    }
}
