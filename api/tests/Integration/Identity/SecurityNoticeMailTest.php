<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

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

    private function lastMail(): Email
    {
        $messages = self::getMailerMessages();
        $mail = end($messages);
        self::assertInstanceOf(Email::class, $mail);

        return $mail;
    }
}
