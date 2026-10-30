<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every spent budget of the sign-in, its second factor and the proofs of who is at the screen answers through
 * `SpentBudget`, which says when to come back (`Retry-After`): the screens say « Réessayez à 14:32 » from it, and a
 * 429 written by hand would leave them saying nothing about the wait.
 */
final class SpentBudgetsSayTheWaitTest extends TestCase
{
    private const string IDENTITY = __DIR__.'/../../src/Identity';

    public function testEveryIdentityBudgetAnswersThroughSpentBudget(): void
    {
        $answered = 0;
        $byHand = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::IDENTITY, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension() || 'SpentBudget.php' === $file->getFilename()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $answered += preg_match_all('/SpentBudget::answer(?:In)?\(/', $code);
            if (1 === preg_match('/HTTP_TOO_MANY_REQUESTS|TooManyRequestsHttpException\(/', $code)) {
                $byHand[] = $file->getFilename();
            }
        }

        // Today's count: the second factor's, the passkey sign-in's, the proofs', the password's and the login's.
        self::assertGreaterThanOrEqual(15, $answered, 'every spent budget was found');
        self::assertSame([], $byHand, 'a 429 written by hand says nothing about when to come back: answer through SpentBudget');
    }
}
