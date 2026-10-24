<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A demo's mail never leaves, and `CaptureDemoMail` knows a demo's mail by its recipient: a sandbox's owner, the only
 * member a sandbox has. That holds while every mail the application writes goes to an account or to an address the
 * demo cannot type. A new class writing mail is named here with who it writes to, so a mail to a customer, which a
 * sandbox could send to an address its visitor typed, is never added without a capture of its own.
 */
final class DemoMailReachTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../src';

    /** Each class writing mail, and why a sandbox's copy of it is caught or never written. */
    private const array WRITERS = [
        'Identity/Infrastructure/Mail/SymfonyAccountDeletionMailer.php' => 'to the account deleted; a demo is refused deleting it',
        'Identity/Infrastructure/Mail/SymfonyPasswordResetMailer.php' => 'to the account\'s own address: a sandbox\'s owner, caught',
        'Identity/Infrastructure/Mail/SymfonySecurityNoticeMailer.php' => 'to the account\'s own address: a sandbox\'s owner, caught',
        'Inbox/Infrastructure/Mail/SymfonyNotificationMailer.php' => 'to members, a sandbox\'s owner caught; the operators\' mailed kind skips sandboxes',
        'Licensing/Infrastructure/Mail/SymfonySubscriptionMailer.php' => 'to the company\'s owners: a sandbox\'s owner, caught',
        'Tenancy/Infrastructure/Mail/SymfonyCompanyApprovalMailer.php' => 'to the company\'s owner: a sandbox\'s owner, caught',
        'Tenancy/Infrastructure/Mail/SymfonyInvitationMailer.php' => 'to an invited address; a demo is refused inviting',
        'Tenancy/Infrastructure/Mail/SymfonySignupMailer.php' => 'to an address signing up, which is never a sandbox',
    ];

    public function testEveryClassWritingMailSaysHowADemosCopyIsCaught(): void
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

        self::assertGreaterThanOrEqual(\count(self::WRITERS), \count($writers), 'the scan finds fewer writers than are named: it has stopped reading the sources');
        self::assertSame(array_keys(self::WRITERS), $writers, 'A class writing mail is named in WRITERS with who it writes to, and a mail a sandbox could send elsewhere than its owner is caught first.');
    }
}
