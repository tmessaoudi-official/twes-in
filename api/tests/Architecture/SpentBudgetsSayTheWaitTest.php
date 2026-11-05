<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every spent budget of the API says when to come back (`Retry-After`) through `SpentBudget`: an answer of its own
 * through `answer`, a problem through `TooManyRequestsHttpException` given `SpentBudget::secondsUntil`. The screens say
 * « Réessayez à 14:32 » from it, and a 429 written by hand would leave them saying nothing about the wait.
 */
final class SpentBudgetsSayTheWaitTest extends TestCase
{
    private const string SOURCE = __DIR__.'/../../src';

    public function testEveryBudgetAnswersThroughSpentBudget(): void
    {
        $answered = 0;
        $byHand = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SOURCE, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension() || 'SpentBudget.php' === $file->getFilename()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $answered += preg_match_all('/SpentBudget::(?:answer|answerIn|secondsUntil)\(/', $code);
            $thrown = preg_match_all('/TooManyRequestsHttpException\(/', $code);
            $waited = preg_match_all('/TooManyRequestsHttpException\(\s*SpentBudget::secondsUntil\(/', $code);
            if (1 === preg_match('/HTTP_TOO_MANY_REQUESTS/', $code) || $thrown !== $waited) {
                $byHand[] = $file->getFilename();
            }
        }

        // A file answering by hand is named first: it is what a red needs to say.
        sort($byHand);
        self::assertSame([], $byHand, 'a 429 written by hand says nothing about when to come back: answer through SpentBudget');
        // Today's count: the second factor's, the passkey sign-in's, the proofs', the password's and the login's; the
        // demo's entry, the stop link of a mail, the browser's error reports; an invitation read and accepted, a signup
        // asked and finished; a phone's pairing, its photos and its scans.
        self::assertGreaterThanOrEqual(25, $answered, 'every spent budget was found');
    }
}
