<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\Password\NewPasswordPolicy;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\InvitationResource;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\SignupResource;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The length a new password must have is read by the screens before they send one, signed in or not, and is the one
 * every way of choosing a password holds it to: the policy a change or a reset applies, an invitation accepted, a
 * signup finished.
 */
final class PasswordRuleTest extends ApiTestCase
{
    public function testTheScreensReadTheLengthTheApiHoldsANewPasswordTo(): void
    {
        $this->getJson('/api/auth/password/rule');

        self::assertResponseIsSuccessful();
        self::assertSame(['minLength' => 12, 'maxLength' => 4096], $this->json());
    }

    public function testEveryWayOfChoosingAPasswordHoldsItToThatLength(): void
    {
        foreach ([[InvitationResource::class, 'password'], [SignupResource::class, 'password']] as [$class, $property]) {
            $lengths = (new \ReflectionProperty($class, $property))->getAttributes(Length::class);
            self::assertCount(1, $lengths, "$class::\$$property");
            $length = $lengths[0]->newInstance();
            self::assertSame(NewPasswordPolicy::MIN_LENGTH, $length->min, "$class::\$$property");
            self::assertSame(NewPasswordPolicy::MAX_LENGTH, $length->max, "$class::\$$property");
        }
    }

    /**
     * The class row 215 names, found mechanically: a screen holding a field to a length of its own (`minLength(12)`) or a
     * text writing a password's length out (« 12 caractères ») goes stale the day the rule moves. Every length a web
     * control is held to below its own size comes from the API (`shared/password/password-rule.ts`), and every password
     * text says the `{{min}}` it is handed.
     */
    public function testNoScreenWritesAPasswordLengthOfItsOwn(): void
    {
        $web = \dirname(__DIR__, 3).'/web';
        $written = [];
        $read = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($web.'/src/app', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || 'ts' !== $file->getExtension() || str_ends_with($file->getFilename(), '.spec.ts')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $read += preg_match_all('/=\s*heldToPasswordRule\(/', $source);
            if (1 === preg_match('/Validators\.minLength\(\s*\d/', $source)) {
                $written[] = $file->getPathname();
            }
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($web.'/public/i18n', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || 'json' !== $file->getExtension()) {
                continue;
            }
            $texts = json_decode((string) file_get_contents($file->getPathname()), true, flags: \JSON_THROW_ON_ERROR);
            foreach (self::textsIn($texts) as $path => $text) {
                if (str_contains($path, 'password') && 1 === preg_match('/\d/', $text)) {
                    $written[] = $file->getPathname().' '.$path;
                }
            }
        }

        // Three pages choosing a password from a form (signing up, an invitation, a reset); « Mon compte » reads it too.
        self::assertGreaterThanOrEqual(3, $read, 'the pages choosing a password are no longer found');
        self::assertSame([], $written, 'a password length written out instead of read from GET /api/auth/password/rule');
    }

    /**
     * Every text of a texts file by its dotted path, so a text is judged by the section it sits in.
     *
     * @return iterable<string, string>
     */
    private static function textsIn(mixed $texts, string $path = ''): iterable
    {
        if (\is_string($texts)) {
            yield $path => $texts;
        } elseif (\is_array($texts)) {
            foreach ($texts as $key => $text) {
                yield from self::textsIn($text, '' === $path ? (string) $key : $path.'.'.$key);
            }
        }
    }
}
