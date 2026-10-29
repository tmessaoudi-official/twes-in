<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Infrastructure\Intl\LibPhoneNumbers;
use App\Shared\Infrastructure\Twig\PhoneExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/** A printed document and a mail show a kept phone grouped, never the E.164 it is kept as. */
final class PhoneExtensionTest extends TestCase
{
    private const string TEMPLATES = __DIR__.'/../../../../templates';

    public function testAKeptPhoneIsPrintedGroupedAndAnOlderValueAsItIs(): void
    {
        $twig = new Environment(new ArrayLoader(['t' => '{{ a|phone }}|{{ b|phone }}|{{ c|phone }}']));
        $twig->addExtension(new PhoneExtension(new LibPhoneNumbers(new IdentityTranslator())));

        self::assertSame('+216 71 900 868|71.900.868 poste 12|', $twig->render('t', ['a' => '+21671900868', 'b' => '71.900.868 poste 12', 'c' => null]));
    }

    /** Every phone a template prints goes through the filter: one printed bare would show +21671900868. */
    public function testEveryTemplatePrintsAPhoneThroughTheFilter(): void
    {
        $printed = 0;
        $bare = [];
        foreach (glob(self::TEMPLATES.'/*/*.twig') ?: [] as $file) {
            $text = (string) file_get_contents($file);
            $printed += (int) preg_match_all('/\{\{[^}]*\.phone\b[^}]*\}\}/', $text);
            if (preg_match_all('/\{\{[^}]*\.phone\b(?![^}]*\|phone)[^}]*\}\}/', $text, $found) > 0) {
                $bare[] = basename($file).': '.implode(', ', $found[0]);
            }
        }

        self::assertGreaterThanOrEqual(6, $printed, 'the documents print the seller\'s, the buyer\'s and the vendor\'s phone');
        self::assertSame([], $bare);
        // The mail telling an owner their company was closed lists the support channels, the phone among them.
        self::assertStringContainsString("channel == 'phone' ? value|phone : value", (string) file_get_contents(self::TEMPLATES.'/email/company_closed_by_operator.html.twig'));
    }
}
