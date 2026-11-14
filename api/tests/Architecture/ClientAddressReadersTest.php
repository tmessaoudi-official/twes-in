<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Who reads the client's address, and what for. A per-client budget is spent under `ClientAddress::of`, which refuses a
 * caller with no address rather than give every such caller one shared key; the two places recording where a request
 * came from read it as it is, null when there is none. A new reader is named here with its budget, its decision or its
 * record.
 */
final class ClientAddressReadersTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../src';

    /** Each class spending a per-client budget, and the budget it spends (config/packages/rate_limiter.yaml). */
    private const array BUDGETS = [
        'Demo/Infrastructure/Http/DemoEntryController.php' => 'demo_entry',
        'Identity/Infrastructure/Passkey/PasskeySignInController.php' => 'passkey_sign_in',
        'Identity/Infrastructure/Password/PasswordResetController.php' => 'password_reset_client, password_reset_use',
        'Identity/Infrastructure/Security/RequestCallerAddress.php' => 'a password\'s lock from one address (app.auth.lock_after_failures)',
        'Inbox/Infrastructure/Http/NotificationMailStopController.php' => 'notification_mail_stop',
        'Module/Scanning/Infrastructure/Http/PairedPhoneController.php' => 'scan_pairing_claim',
        'Shared/Infrastructure/ErrorReporting/ClientErrorController.php' => 'client_errors',
        'Tenancy/Infrastructure/ApiPlatform/State/Processor/AcceptInvitationProcessor.php' => 'invitation_link',
        'Tenancy/Infrastructure/ApiPlatform/State/Processor/CompleteSignupProcessor.php' => 'signup_complete',
        'Tenancy/Infrastructure/ApiPlatform/State/Processor/RequestSignupProcessor.php' => 'signup_client',
        'Tenancy/Infrastructure/ApiPlatform/State/Provider/InvitationProvider.php' => 'invitation_link',
    ];

    /** Each class judging a request by where it came from, and what it decides. */
    private const array JUDGES = [
        'Shared/Infrastructure/Http/ShopNetwork.php' => 'on a shop\'s computer, whether the platform\'s side answers',
    ];

    /** Each class reading the address as it is, and why: it records it, and records none when there is none. */
    private const array RECORDS = [
        'Audit/Infrastructure/DoctrineAuditTrail.php' => 'the journal\'s address of an action',
        'Identity/Infrastructure/Session/SessionRecorder.php' => 'where a session was last used',
        'Shared/Infrastructure/Http/ClientAddress.php' => 'the canon itself',
    ];

    public function testEveryBudgetIsSpentUnderTheCanon(): void
    {
        $expected = [...array_keys(self::BUDGETS), ...array_keys(self::JUDGES)];
        sort($expected);

        self::assertSame($expected, $this->filesHolding('ClientAddress::of('), 'A class spending a per-client budget is named in BUDGETS with the budget it spends, one judging by the address in JUDGES.');
    }

    public function testNoOtherClassReadsTheAddressItself(): void
    {
        $readers = $this->filesHolding('getClientIp(');
        $expected = array_keys(self::RECORDS);
        sort($expected);

        self::assertSame($expected, $readers, 'The client\'s address is read through ClientAddress::of, whose refusal of a caller with none no fallback (?? \'\', ?? \'unknown\') may undo: one key shared by every such caller lets one spend all the others\' budget.');
    }

    /** @return list<string> */
    private function filesHolding(string $needle): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SOURCES, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension() && str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                $found[] = substr($file->getPathname(), \strlen(self::SOURCES) + 1);
            }
        }
        sort($found);
        self::assertNotEmpty($found, \sprintf('no file under src holds %s: the scan has stopped reading the sources', $needle));

        return $found;
    }
}
