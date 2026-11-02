<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testHealthReportsOkWhenTheDatabaseIsReachable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        // An image built with no version (here, the test kernel) says so rather than inventing one.
        self::assertSame(['status' => 'ok', 'database' => 'ok', 'build' => ['version' => null, 'commit' => null, 'mode' => 'test'], 'deployment' => null, 'statusUrl' => null, 'passkeys' => ['host' => 'localhost', 'address' => 'http://localhost:8090'], 'web' => ['errorReportsPerPage' => 5, 'submissionRetryMinutes' => 15, 'passwordResetSeconds' => 3600, 'signupSeconds' => 86400, 'stepUpSeconds' => 300, 'recordsUndoableSeconds' => 86400, 'erasureUndoableSeconds' => 86400]], $body);
    }

    /**
     * WebAuthn binds a passkey to one host, which every ceremony's options name already: the web reads it here, before
     * anyone signs in, so a page opened at another address (a shop's network one) offers no passkey that cannot work
     * and says where they do.
     */
    public function testHealthSaysWherePasskeysWork(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['host' => 'localhost', 'address' => 'http://localhost:8090'], $body['passkeys'] ?? null, 'the relying party\'s id and the first origin the API allows');
    }

    /**
     * What the operator sets for the page itself, read by every page as it starts, a signed-out one too: how many errors
     * one page load reports (app.client_errors.per_page), how long a write whose answer never came keeps its key
     * (app.submissions.retry_minutes), and the durations the screens state: a reset or signup link's life, a step-up
     * proof's, and how long a delete, an import's undo or an erasure can be taken back. Container parameters, so a
     * degraded API still says them.
     */
    public function testHealthTellsThePageWhatTheOperatorSetForIt(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $container = static::getContainer();
        self::assertSame([
            'errorReportsPerPage' => $container->getParameter('app.client_errors.per_page'),
            'submissionRetryMinutes' => $container->getParameter('app.submissions.retry_minutes'),
            'passwordResetSeconds' => self::seconds($container->getParameter('app.password_reset.valid_for')),
            'signupSeconds' => self::seconds($container->getParameter('app.signup.valid_for')),
            'stepUpSeconds' => self::seconds($container->getParameter('app.step_up.valid_for')),
            'recordsUndoableSeconds' => self::seconds($container->getParameter('app.records.undoable_for')),
            'erasureUndoableSeconds' => self::seconds($container->getParameter('app.erasure.undoable_for')),
        ], $body['web'] ?? null);
    }

    /**
     * The footer shows the API's build beside the web's (docs/SPEC.md § 7, the build line): the version and commit the
     * image was built as, the environment the kernel runs in, and the deployment, each as it is; the web leaves out
     * what reads « prod ».
     */
    public function testHealthSaysWhichBuildAnswersAndWhere(): void
    {
        $values = ['BUILD_VERSION' => '2026.10.07.5-dirty', 'BUILD_COMMIT' => 'a7adf55f', 'DEPLOY_ENV' => 'staging'];
        foreach ($values as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        try {
            $client = static::createClient();
            $client->request('GET', '/api/health');
        } finally {
            foreach (array_keys($values) as $name) {
                $_SERVER[$name] = $_ENV[$name] = '';
            }
        }

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['version' => '2026.10.07.5-dirty', 'commit' => 'a7adf55f', 'mode' => 'test'], $body['build'] ?? null);
        self::assertSame('staging', $body['deployment'] ?? null);
    }

    /**
     * A caller with no session gets none read or started: the answer keeps Symfony's own header, which the web tier
     * passes through (web/e2e/auth.spec.ts), and nothing on the way asks who is signed in.
     */
    public function testACallerWithNoSessionHasNoneOpened(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseHeaderSame('cache-control', 'no-cache, private');
        self::assertNull($client->getResponse()->headers->getCookies()[0] ?? null);
    }

    /** An ISO 8601 duration the container holds, in seconds, as the screens say how long a link or an undo lasts. */
    private static function seconds(mixed $duration): int
    {
        self::assertIsString($duration);
        $from = new \DateTimeImmutable('2000-01-01T00:00:00Z');

        return $from->add(new \DateInterval($duration))->getTimestamp() - $from->getTimestamp();
    }
}
