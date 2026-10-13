<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;
use App\Identity\Infrastructure\Mfa\OtphpTotpCodes;
use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Role;
use App\Tenancy\Infrastructure\Console\SeedCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedCommandTest extends ApiTestCase
{
    public function testItCreatesTheRolesTheOperatorAndTheCompanyThenSignsThemIn(): void
    {
        $tester = $this->seed(['--operator-email' => 'op@example.test', '--operator-password' => 'seeded-secret', '--company-name' => 'Seeded']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        foreach (['role owner', 'role admin', 'role member', 'role clerk', 'role accountant', 'operator op@example.test', 'company Seeded', 'membership op@example.test owns Seeded'] as $fragment) {
            self::assertStringContainsString($fragment, $display);
        }

        $em = $this->em();
        self::assertCount(5, $em->getRepository(Role::class)->findBy(['company' => null]));
        $operator = $em->getRepository(User::class)->findOneBy(['email' => 'op@example.test']);
        self::assertNotNull($operator);
        self::assertTrue($operator->isPlatformOperator());
        $company = $em->getRepository(Company::class)->findOneBy(['name' => 'Seeded']);
        self::assertNotNull($company);
        self::assertSame('owner', $em->getRepository(Membership::class)->findOneBy(['user' => $operator, 'company' => $company])?->getRole()->getName());

        $this->login('op@example.test', 'seeded-secret');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Seeded', $this->section($this->json(), 'company')['name']);
        self::assertSame(['*'], $this->json()['permissions']);
    }

    public function testAProductionSeedReadsThePasswordFromStdinAndMakesNoCompany(): void
    {
        $tester = $this->seed(['--operator-email' => 'op@example.test', '--operator-password-stdin' => true, '--without-company' => true], ["read-from-stdin-secret\n"]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringNotContainsString('company', $tester->getDisplay());
        self::assertSame([], $this->em()->getRepository(Company::class)->findAll());

        $this->login('op@example.test', 'read-from-stdin-secret');
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->json()['company'] ?? null, 'the operator belongs to no company');
    }

    public function testThePasswordIsGivenOneWayOnly(): void
    {
        $tester = $this->seed(['--operator-email' => 'op@example.test', '--operator-password' => 'a', '--operator-password-stdin' => true], ["b\n"]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertCount(0, $this->em()->getRepository(User::class)->findAll());
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->seed(['--operator-email' => 'op@example.test', '--operator-password' => 'seeded-secret']);
        $tester = $this->seed(['--operator-email' => 'op@example.test']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Nothing to do', $tester->getDisplay());
        self::assertCount(1, $this->em()->getRepository(User::class)->findAll());
        self::assertCount(5, $this->em()->getRepository(Role::class)->findAll());
    }

    public function testAnOperatorTotpSecretMakesTheOperatorSignInWithACodeFromIt(): void
    {
        $tester = $this->seed(['--operator-email' => 'op@example.test', '--operator-password' => 'seeded-secret', '--operator-totp-secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP']);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $this->postJson('/api/auth/login', ['email' => 'op@example.test', 'password' => 'seeded-secret']);
        self::assertTrue($this->boolAt($this->json(), 'mfaRequired'));

        $this->postJson('/api/auth/mfa/verify', ['code' => (new OtphpTotpCodes())->codeAt('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', new \DateTimeImmutable())]);
        self::assertResponseIsSuccessful();
        self::assertSame('op@example.test', $this->stringAt($this->section($this->json(), 'user'), 'email'));
    }

    public function testAMalformedTotpSecretIsRefused(): void
    {
        $tester = $this->seed(['--operator-email' => 'op@example.test', '--operator-password' => 'seeded-secret', '--operator-totp-secret' => 'not base32']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('--operator-totp-secret', $tester->getDisplay());
        self::assertCount(0, $this->em()->getRepository(User::class)->findAll());
    }

    public function testCreatingTheOperatorWithoutAPasswordIsRefused(): void
    {
        $tester = $this->seed(['--operator-email' => 'op@example.test']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('--operator-password is required', $tester->getDisplay());
        self::assertCount(0, $this->em()->getRepository(User::class)->findAll());
    }

    public function testAProductionKernelRefusesTheDevelopmentOptions(): void
    {
        foreach ([
            '--operator-password' => ['--operator-password' => 'on-the-command-line'],
            '--operator-totp-secret' => ['--operator-password-stdin' => true, '--operator-totp-secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'],
        ] as $option => $options) {
            $tester = new CommandTester(new SeedCommand(self::getContainer()->get(SeedPlatform::class), 'prod'));
            $tester->setInputs(["stdin-secret\n"]);
            $tester->execute(['--operator-email' => 'op@example.test', ...$options], ['interactive' => false]);

            self::assertSame(2, $tester->getStatusCode(), $option);
            self::assertStringContainsString($option, $tester->getDisplay());
            self::assertCount(0, $this->em()->getRepository(User::class)->findAll(), $option);
        }
    }

    public function testAProductionKernelStillSeedsFromStdin(): void
    {
        $tester = new CommandTester(new SeedCommand(self::getContainer()->get(SeedPlatform::class), 'prod'));
        $tester->setInputs(["stdin-secret\n"]);
        $tester->execute(['--operator-email' => 'op@example.test', '--operator-password-stdin' => true, '--without-company' => true], ['interactive' => false]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertCount(1, $this->em()->getRepository(User::class)->findAll());
    }

    /**
     * @param array<string, string|true> $options
     * @param list<string>               $stdin   what the command reads from its standard input
     */
    private function seed(array $options, array $stdin = []): CommandTester
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('kernel not booted'));
        $tester = new CommandTester($application->find('app:seed'));
        $tester->setInputs($stdin);
        $tester->execute($options, ['interactive' => false]);

        return $tester;
    }
}
