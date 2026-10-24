<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * What the API writes itself about a company takes the company's wording: every printed document is rendered, and
 * every mail the company's own life sends is written, through TextsInForce naming the company. A mail the platform sends
 * in its own voice says so here, with why; a mailer added is classed when it is added.
 */
final class CompanyWordingTest extends TestCase
{
    /** The mails the platform writes in the installation's wording, each with why. */
    private const array INSTALLATION_VOICE = [
        'Identity/Infrastructure/Mail/SymfonyAccountDeletionMailer.php' => 'about a person\'s own account, in no company',
        'Identity/Infrastructure/Mail/SymfonyPasswordResetMailer.php' => 'about a person\'s own account, in no company',
        'Tenancy/Infrastructure/Mail/SymfonySignupMailer.php' => 'before any company exists',
        'Tenancy/Infrastructure/Mail/SymfonyCompanyApprovalMailer.php' => 'the platform telling an owner its company is open',
        'Licensing/Infrastructure/Mail/SymfonySubscriptionMailer.php' => 'the platform and a company settling its subscription',
    ];

    public function testEveryPrintedDocumentIsRenderedInItsCompanysWording(): void
    {
        $templates = self::filesHolding("render('pdf/");
        self::assertGreaterThanOrEqual(5, \count($templates), 'the five printed documents, found by their render');
        foreach ($templates as $file => $source) {
            self::assertStringContainsString('$this->texts->for($company->getId(), fn (): string => $this->twig->render(\'pdf/', $source, $file);
        }
    }

    public function testEveryMailIsWrittenInItsCompanysWordingOrSaysWhyNot(): void
    {
        $mailers = [];
        foreach (self::sources() as $file => $source) {
            if (str_contains($source, 'new TemplatedEmail()')) {
                $mailers[$file] = $source;
            }
        }
        self::assertGreaterThanOrEqual(7, \count($mailers));
        foreach ($mailers as $file => $source) {
            $voice = self::INSTALLATION_VOICE[$file] ?? null;
            if (null !== $voice) {
                self::assertStringNotContainsString('TextsInForce', $source, "$file is written in its company's wording: strike it off the list");
                continue;
            }
            self::assertMatchesRegularExpression('/\$this->texts->for\(\$mail->companyId, function \(\) use \(\$mail\): void \{\s*\$this->write\(\$mail\);/', $source, "$file writes a mail outside its company's wording, or is not listed with why");
        }
        self::assertSame([], array_values(array_diff(array_keys(self::INSTALLATION_VOICE), array_keys($mailers))), 'a mailer listed that is gone');
    }

    /** @return array<string, string> */
    private static function filesHolding(string $needle): array
    {
        return array_filter(self::sources(), static fn (string $source): bool => str_contains($source, $needle));
    }

    /** @return array<string, string> every PHP file of src/, by its path below src/ */
    private static function sources(): array
    {
        $root = \dirname(__DIR__, 2).'/src/';
        $sources = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $sources[substr($file->getPathname(), \strlen($root))] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($sources);

        return $sources;
    }
}
