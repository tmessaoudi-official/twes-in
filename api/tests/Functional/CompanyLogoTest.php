<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/** docs/SPEC.md § 7, 2026-09-26 22:24: each company uploads its logo, which its documents and its switcher show. */
final class CompanyLogoTest extends ApiTestCase
{
    /** A 1x1 PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
    }

    public function testAnAdministratorUploadsTheLogoAndAnyMemberReadsItBack(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->uploadFile($this->path(), 'logo.png', $this->png());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame($this->png(), $this->client->getResponse()->getContent());
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testTheProfileSaysWhetherALogoIsThereAndWhichOne(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $this->getJson('/api/companies/'.$this->company->getId()->toRfc4122().'/profile');
        self::assertArrayNotHasKey('logoVersion', $this->json());

        $this->uploadFile($this->path(), 'logo.png', $this->png());
        $this->getJson('/api/companies/'.$this->company->getId()->toRfc4122().'/profile');
        $first = $this->stringAt($this->json(), 'logoVersion');

        $this->uploadFile($this->path(), 'other.png', $this->png().'');
        $this->getJson('/api/companies/'.$this->company->getId()->toRfc4122().'/profile');
        self::assertNotSame($first, $this->stringAt($this->json(), 'logoVersion'), 'a replaced logo changes the version the screen caches by');
    }

    // docs/SPEC.md § 7, 2026-09-26 22:24: the company switcher shows each company's logo, in one read for all of them.
    public function testTheListOfMyCompaniesNamesTheLogoOfEachThatHasOne(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $other = $this->createCompany('Other');
        $this->addMembership($this->em()->getRepository(User::class)->findOneBy(['email' => 'admin@twes.local']) ?? throw new \LogicException('no user'), $other);
        $this->login('admin@twes.local', 'password-1234');
        $this->uploadFile($this->path(), 'logo.png', $this->png());

        $this->getJson('/api/me/companies');

        self::assertResponseIsSuccessful();
        $byName = [];
        foreach ($this->jsonList() as $row) {
            $byName[$this->stringAt($row, 'name')] = $row;
        }
        self::assertArrayHasKey('logoVersion', $byName['Acme']);
        self::assertArrayNotHasKey('logoVersion', $byName['Other']);
        $this->getJson('/api/companies/'.$this->company->getId()->toRfc4122().'/profile');
        self::assertSame($this->json()['logoVersion'], $byName['Acme']['logoVersion'], 'the list and the profile name the same version');
    }

    public function testUploadingAgainReplacesTheLogoRatherThanAddingOne(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $this->uploadFile($this->path(), 'one.png', $this->png());
        $this->uploadFile($this->path(), 'two.png', $this->png());

        self::assertEquals(1, $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM attachment WHERE entity_type = 'company_logo'"));
    }

    public function testAnAdministratorRemovesTheLogo(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $this->uploadFile($this->path(), 'logo.png', $this->png());

        $this->sendJson('DELETE', $this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAMemberWithoutTheSettingsPermissionCannotChangeTheLogo(): void
    {
        $this->signedIn(['company.read']);

        $this->uploadFile($this->path(), 'logo.png', $this->png());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->sendJson('DELETE', $this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testASignedOutCallerGetsNothing(): void
    {
        $this->getJson($this->path());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testOnlyAPictureIsKept(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        foreach ([
            'a PDF' => ['logo.pdf', '%PDF-1.4'."\n".'1 0 obj<<>>endobj'],
            'a script dressed as a picture' => ['logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'an empty file' => ['logo.png', ''],
            'bytes that are no picture' => ['logo.png', 'not a picture at all'],
        ] as $why => [$name, $contents]) {
            $this->uploadFile($this->path(), $name, $contents);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $why);
        }
        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a refused upload leaves no logo');
    }

    public function testALogoThatIsTooLargeIsRefused(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->uploadFile($this->path(), 'big.png', $this->png().str_repeat('0', 2 * 1024 * 1024));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnotherCompanysLogoIsNotReadable(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $other = $this->createCompany('Other');

        $this->getJson('/api/companies/'.$other->getId()->toRfc4122().'/logo');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG, true);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('admin@twes.local', 'password-1234', $this->company, $permissions, 'admin');
        $this->login('admin@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/logo';
    }
}
