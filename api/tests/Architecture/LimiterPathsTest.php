<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A rate limiter is spent by a person's whole path, not by one endpoint: putting the authenticator's setup confirms on
 * `mfa_verify` let a step-up, an enrolment, a recovery-code sign-in, a step-up and one typo spend its five, and the right
 * code answered 429. Every method that spends a named limiter (config/packages/rate_limiter.yaml) is declared here with
 * the route family it serves; a limiter more than one family spends says why they share it. A method that starts spending
 * a limiter reds here, naming the families already on it: walk every e2e that crosses more than one of them before
 * declaring it.
 */
final class LimiterPathsTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../src';

    private const string LIMITERS = __DIR__.'/../../config/packages/rate_limiter.yaml';

    /**
     * Each limiter: why its families share it (null when one family spends it), and each method spending it, as
     * `Class::method`, with the route family it serves.
     *
     * @var array<string, array{shared: ?string, spent: array<string, string>}>
     */
    private const array PATHS = [
        'client_errors' => ['shared' => null, 'spent' => ['ClientErrorController::__invoke' => 'a browser error report (POST /api/client-errors)']],
        'demo_entry' => ['shared' => null, 'spent' => ['DemoEntryController::enter' => 'the demo entry']],
        'invitation_link' => ['shared' => null, 'spent' => [
            'AcceptInvitationProcessor::process' => 'an invitation link',
            'InvitationProvider::provide' => 'an invitation link',
        ]],
        'mfa_confirm' => ['shared' => null, 'spent' => [
            'MfaController::confirm' => 'an authenticator set up (POST /api/auth/mfa/enrolment/confirm, …/authenticator/replacement/confirm)',
            'MfaController::confirmReplacement' => 'an authenticator set up (POST /api/auth/mfa/enrolment/confirm, …/authenticator/replacement/confirm)',
        ]],
        'mfa_verify' => [
            'shared' => 'every answer to who is at the screen guesses at the same secrets (a code, a recovery code, a passkey, the password), so one budget per person counts its guesses whichever door they come through; each that holds gives its try back',
            'spent' => [
                'ChangePasswordController::__invoke' => 'a password change (PUT /api/auth/password)',
                'CompanyProofController::passkey' => "a company's entry proof (POST /api/companies/{companyId}/proof, …/proof/passkey)",
                'CompanyProofController::password' => "a company's entry proof (POST /api/companies/{companyId}/proof, …/proof/passkey)",
                'MfaController::__invoke' => "the sign-in's second step (POST /api/auth/mfa/verify, /api/auth/mfa/passkey-login)",
                'MfaController::regenerateRecoveryCodes' => 'new recovery codes (POST /api/auth/mfa/recovery-codes, …/recovery-codes/passkey)',
                'PasskeyController::login' => "the sign-in's second step (POST /api/auth/mfa/verify, /api/auth/mfa/passkey-login)",
                'PasskeyController::regenerateRecoveryCodes' => 'new recovery codes (POST /api/auth/mfa/recovery-codes, …/recovery-codes/passkey)',
                'StepUpController::passkey' => 'a step-up (POST /api/auth/step-up, …/step-up/passkey)',
                'StepUpController::password' => 'a step-up (POST /api/auth/step-up, …/step-up/passkey)',
            ],
        ],
        'notification_mail_stop' => ['shared' => null, 'spent' => ['NotificationMailStopController::__invoke' => "a notification mail's stop link"]],
        'passkey_sign_in' => ['shared' => null, 'spent' => ['PasskeySignInController::signIn' => 'a sign-in with a passkey alone']],
        'password_reset_address' => ['shared' => null, 'spent' => ['PasswordResetController::forgot' => 'a forgotten password asked']],
        'password_reset_client' => ['shared' => null, 'spent' => ['PasswordResetController::forgot' => 'a forgotten password asked']],
        'password_reset_use' => ['shared' => null, 'spent' => ['PasswordResetController::reset' => 'a new password chosen from its link']],
        'scan_pairing_claim' => ['shared' => null, 'spent' => ['PairedPhoneController::claim' => 'a phone claiming its pairing link']],
        // asThePhone is what every call of a paired phone but a photo goes through: its scans, choices and token renewals.
        'scan_pairing_phone' => ['shared' => null, 'spent' => ['PairedPhoneController::asThePhone' => "a paired phone's scans, choices and token renewals"]],
        'scan_pairing_photo' => ['shared' => null, 'spent' => ['PairedPhoneController::photo' => "a paired phone's photos"]],
        'signup_address' => ['shared' => null, 'spent' => ['RequestSignupProcessor::process' => 'a signup link asked']],
        'signup_client' => ['shared' => null, 'spent' => ['RequestSignupProcessor::process' => 'a signup link asked']],
        'signup_complete' => ['shared' => null, 'spent' => ['CompleteSignupProcessor::process' => 'a signup finished']],
    ];

    public function testEveryMethodSpendingALimiterIsDeclaredWithItsFamily(): void
    {
        $spent = self::spent();
        $wrong = [];
        foreach ($spent as $limiter => $methods) {
            $declared = self::PATHS[$limiter]['spent'] ?? [];
            foreach (array_diff($methods, array_keys($declared)) as $method) {
                $already = [] === $declared ? 'nothing yet' : implode(', ', array_map(static fn (string $m, string $f): string => "$m ($f)", array_keys($declared), $declared));
                $wrong[] = "$method spends « $limiter », undeclared. Already on it: $already. A person's path spends them all from one budget: run every e2e crossing more than one of them, then declare it here with its family.";
            }
            foreach (array_diff(array_keys($declared), $methods) as $gone) {
                $wrong[] = "$gone is declared on « $limiter » but no longer spends it: strike it off";
            }
        }
        foreach (array_diff(array_keys(self::PATHS), array_keys($spent)) as $idle) {
            $wrong[] = "« $idle » is declared but nothing spends it any more";
        }

        self::assertSame([], $wrong, implode("\n", $wrong)."\n\nFound:\n".var_export($spent, true));
    }

    public function testALimiterSeveralFamiliesSpendSaysWhyTheyShareIt(): void
    {
        $wrong = [];
        foreach (self::PATHS as $limiter => ['shared' => $shared, 'spent' => $spent]) {
            $families = array_values(array_unique($spent));
            if (\count($families) > 1 && (null === $shared || '' === trim($shared))) {
                $wrong[] = "« $limiter » is spent by ".implode(', ', $families).': say why they share one budget, or give one its own';
            }
            if (1 === \count($families) && null !== $shared) {
                $wrong[] = "« $limiter » is spent by one family only: its shared reason says nothing";
            }
        }

        self::assertSame([], $wrong, implode("\n", $wrong));
    }

    public function testEveryConfiguredLimiterIsDeclaredAndEveryDeclaredOneConfigured(): void
    {
        $configured = self::configured();

        self::assertGreaterThanOrEqual(16, \count($configured), 'rate_limiter.yaml names some sixteen limiters: fewer read means the file moved');
        self::assertSame([], array_values(array_diff($configured, array_keys(self::PATHS))), 'a limiter configured is declared here with what spends it');
        self::assertSame([], array_values(array_diff(array_keys(self::PATHS), $configured)), 'a limiter declared here is configured');
    }

    /**
     * Each limiter, with the methods that spend it: a method reading the property its factory is injected into. A factory
     * injected without its `#[Target]` (Symfony also binds one by the argument's name) would escape the reading, so it reds.
     *
     * @return array<string, list<string>>
     */
    private static function spent(): array
    {
        $configured = self::configured();
        $spent = [];
        $injected = 0;
        $untraced = [];
        $sources = (string) realpath(self::SOURCES);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sources, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (!str_contains($code, 'RateLimiterFactoryInterface $')) {
                continue;
            }
            preg_match('/\b(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/', $code, $class);
            $owner = $class[1] ?? $file->getBasename('.php');
            preg_match_all('/RateLimiterFactoryInterface\s+\$(\w+)/', $code, $all);
            preg_match_all("/#\\[Target\\('(\\w+)'\\)\\]\\s*(?:(?:private|protected|public|readonly)\\s+)*RateLimiterFactoryInterface\\s+\\$(\\w+)/", $code, $targeted, \PREG_SET_ORDER);
            $properties = [];
            foreach ($targeted as [, $limiter, $property]) {
                if (\in_array($limiter, $configured, true)) {
                    $properties[$property] = $limiter;
                }
            }
            foreach (array_diff($all[1], array_keys($properties)) as $property) {
                $untraced[] = "$owner::\$$property";
            }
            $injected += \count($properties);
            foreach (self::methods($code) as $method => $body) {
                foreach ($properties as $property => $limiter) {
                    if (1 === preg_match('/\$this\s*\??->\s*'.$property.'\b/', $body)) {
                        $spent[$limiter][] = "$owner::$method";
                    }
                }
            }
        }
        ksort($spent);

        self::assertGreaterThanOrEqual(20, $injected, 'some twenty limiter factories are injected today: fewer found means the reading lost them');
        self::assertSame([], $untraced, 'a limiter factory is injected with #[Target(<its name>)], or this test cannot say what spends it');

        return array_map(static function (array $methods): array {
            sort($methods);

            return array_values(array_unique($methods));
        }, $spent);
    }

    /** @return array<string, string> each named method's text, up to the next named method */
    private static function methods(string $code): array
    {
        preg_match_all('/\bfunction\s+(\w+)\s*\(/', $code, $found, \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER);
        $methods = [];
        foreach ($found as $index => [[, $start], [$name]]) {
            $end = $found[$index + 1][0][1] ?? \strlen($code);
            $methods[$name] = substr($code, $start, $end - $start);
        }

        return $methods;
    }

    /** @return list<string> */
    private static function configured(): array
    {
        $config = Yaml::parseFile(self::LIMITERS);
        $limiters = \is_array($config) && \is_array($config['framework'] ?? null) && \is_array($config['framework']['rate_limiter'] ?? null) ? $config['framework']['rate_limiter'] : [];

        return array_map(strval(...), array_keys($limiters));
    }
}
