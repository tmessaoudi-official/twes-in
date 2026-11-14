<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A mailed link opens the app at DEFAULT_URI, which on a shop's computer is its Tailscale name or its network address
 * (compose.onprem.yaml): it opens from a member's device, and nowhere for a customer, who is on neither. So a mail
 * reaching a customer (a document sent, a reminder) carries no link into the app, nor any address built from
 * DEFAULT_URI. Every class writing mail is named here with whom it reaches; a customer's is read for links.
 */
final class CustomerMailLinksTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../src';
    private const string TEMPLATES = __DIR__.'/../../templates';

    /** Each class writing mail: true when it reaches a customer, false when every recipient is a person with an account or signing up for one. */
    private const array REACHES_A_CUSTOMER = [
        'Identity/Infrastructure/Mail/SymfonyAccountDeletionMailer.php' => false,
        'Identity/Infrastructure/Mail/SymfonyPasswordResetMailer.php' => false,
        'Identity/Infrastructure/Mail/SymfonySecurityNoticeMailer.php' => false,
        'Inbox/Infrastructure/Mail/SymfonyNotificationMailer.php' => false,
        'Licensing/Infrastructure/Mail/SymfonySubscriptionMailer.php' => false,
        'Tenancy/Infrastructure/Mail/SymfonyCompanyApprovalMailer.php' => false,
        'Tenancy/Infrastructure/Mail/SymfonyInvitationMailer.php' => false,
        'Tenancy/Infrastructure/Mail/SymfonySignupMailer.php' => false,
    ];

    /** What a link into the app looks like in a mail's class or template. */
    private const string LINK = '/href\s*=|https?:\/\/|DEFAULT_URI|_url\b|Url\b|\burl\(/i';

    public function testEveryClassWritingMailSaysWhetherItReachesACustomer(): void
    {
        self::assertSame(array_keys(self::reaches()), $this->writers(), 'A class writing mail is named in REACHES_A_CUSTOMER: a mail to a customer carries no link into the app, which opens only on the shop\'s network or tailnet.');
    }

    public function testAMailReachingACustomerCarriesNoLinkIntoTheApp(): void
    {
        $read = 0;
        foreach (self::reaches() as $writer => $customer) {
            $read += \count($this->texts($writer));
            if (!$customer) {
                continue;
            }
            foreach ($this->texts($writer) as $name => $text) {
                self::assertDoesNotMatchRegularExpression(self::LINK, $text, \sprintf('%s reaches a customer and %s holds a link: the app\'s address opens nowhere a customer is.', $writer, $name));
            }
        }
        // Each class and the templates it names: none missing, or the reading above reads nothing.
        self::assertGreaterThanOrEqual(2 * \count(self::reaches()), $read, 'the mails\' classes or templates were not all read');
    }

    /**
     * The map as the reading takes it, a customer's mail possible: none reaches one today, and the reading must still hold the first that does.
     *
     * @return array<string, bool>
     */
    private static function reaches(): array
    {
        return self::REACHES_A_CUSTOMER;
    }

    /** @return list<string> */
    private function writers(): array
    {
        $writers = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SOURCES, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            if (str_contains($content, 'Symfony\Component\Mailer\MailerInterface') || str_contains($content, 'Symfony\Component\Mailer\Transport')) {
                $writers[] = substr($file->getPathname(), \strlen(self::SOURCES) + 1);
            }
        }
        sort($writers);

        return $writers;
    }

    /** @return array<string, string> the class's source and each template it renders, by name */
    private function texts(string $writer): array
    {
        $source = (string) file_get_contents(self::SOURCES.'/'.$writer);
        $texts = [$writer => $source];
        preg_match_all("/(?:html|text)Template\\('([^']+)'\\)/", $source, $found);
        foreach ($found[1] as $template) {
            $texts[$template] = (string) file_get_contents(self::TEMPLATES.'/'.$template);
        }

        return $texts;
    }
}
