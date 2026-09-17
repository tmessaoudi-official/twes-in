<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\HttpFoundation\Response;

/**
 * The platform's own settings, which belong to its operators (docs/SPEC.md § 3 Settings): whether anyone may sign up,
 * and whether a company that signs up waits for an operator's approval. No company reads or changes them.
 */
final class PlatformSettingsTest extends ApiTestCase
{
    private const string PATH = '/api/platform/settings';

    public function testAnOperatorReadsThePlatformSettingsWithTheirDefaults(): void
    {
        $this->signedInAsOperator();

        $this->getJson(self::PATH);

        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();
        $keys = array_column($rows, 'key');
        sort($keys);
        self::assertSame(['licensing.grace_days', 'licensing.hold_days', 'licensing.unpaid_mode', 'signup.approval_required', 'signup.enabled'], $keys);
        $rows = array_column($rows, null, 'key');
        self::assertSame(7, $rows['licensing.grace_days']['value']);
        self::assertSame(7, $rows['licensing.hold_days']['value']);
        self::assertSame('read_only', $rows['licensing.unpaid_mode']['value']);
        $rows = [$rows['signup.enabled'], $rows['signup.approval_required']];
        self::assertFalse($rows[0]['value']);
        self::assertTrue($rows[1]['value']);
        self::assertNull($rows[0]['source']);
        self::assertSame('platform', $rows[0]['chain']);
        self::assertSame('bool', $rows[0]['type']);
        self::assertSame(['platform'], $rows[0]['writableLevels']);
    }

    public function testAnOperatorChangesAPlatformSettingAndItHolds(): void
    {
        $this->signedInAsOperator();

        $this->sendJson('PUT', self::PATH.'/signup.enabled', ['value' => true]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['value']);
        self::assertSame('platform', $this->json()['source']);

        $this->getJson(self::PATH);
        self::assertTrue(array_column($this->jsonList(), null, 'key')['signup.enabled']['value']);

        // Audited like a company default, with no company: the platform is nobody's tenant.
        $companies = $this->em()->getConnection()->fetchFirstColumn("SELECT company_id FROM audit_log WHERE action = 'setting.changed'");
        self::assertSame([null], $companies);
    }

    public function testAValueOfTheWrongShapeIsUnprocessable(): void
    {
        $this->signedInAsOperator();

        $this->sendJson('PUT', self::PATH.'/signup.enabled', ['value' => 'yes']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testOnlyThePlatformChainIsReachedHere(): void
    {
        $this->signedInAsOperator();

        $this->sendJson('PUT', self::PATH.'/signup.invented', ['value' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // A company's setting has no platform value to set from this screen.
        $this->sendJson('PUT', self::PATH.'/presentation.density', ['value' => 'compact']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testACompanyOwnerIsNotAnOperator(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->login('owner@twes.local', 'password-1234');

        $this->getJson(self::PATH);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->sendJson('PUT', self::PATH.'/signup.enabled', ['value' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testACompanyNeverSeesThePlatformChain(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->login('owner@twes.local', 'password-1234');

        $this->getJson('/api/companies/'.$company->getId()->toRfc4122().'/settings?chain=platform');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // An owner may set every company default, and still not the platform's, by either verb.
        $this->sendJson('PUT', '/api/companies/'.$company->getId()->toRfc4122().'/settings/signup.enabled', ['level' => 'platform', 'value' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->sendJson('DELETE', '/api/companies/'.$company->getId()->toRfc4122().'/settings/signup.approval_required?level=platform');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertSame([], $this->em()->getConnection()->fetchFirstColumn("SELECT key FROM setting WHERE level = 'platform'"));
    }

    private function signedInAsOperator(): void
    {
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->login('op@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }
}
