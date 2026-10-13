<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's data is scoped only once a request acts for it, and nothing but each provider's own call to the company
 * guard makes it so: a provider that forgets is open to every company. The sweep walks the router, signed in as the
 * owner of one company with a fresh step-up, and asks every route under a company for another company's path. Each
 * must answer as for a company that does not exist, whatever it would have done for its own.
 */
final class ForeignCompanySweepTest extends ApiTestCase
{
    use RouteSweep;

    public function testEveryCompanyRouteAnswersAnotherCompanysOwner404(): void
    {
        $this->createUser('owner@example.test', 'owner-password', $this->createCompany('Ours'));
        $theirs = $this->createCompany('Theirs');
        $this->createUser('their-owner@example.test', 'their-password', $theirs);
        $this->login('owner@example.test', 'owner-password');
        self::assertResponseIsSuccessful();
        $this->stepUp('owner-password');

        $router = static::getContainer()->get(RouterInterface::class);
        $answered = [];
        $swept = 0;
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (!str_contains($route->getPath(), '{companyId}')) {
                continue;
            }
            $nobody = Uuid::v4()->toRfc4122();
            $path = self::pathOf($router, $name, $route, ['companyId' => $theirs->getId()->toRfc4122()]);
            $nowhere = self::pathOf($router, $name, $route, ['companyId' => $nobody]);
            foreach (self::methodsOf($route) as $method) {
                ++$swept;
                $theirAnswer = $this->answerTo($method, $path, $theirs->getId()->toRfc4122());
                $noAnswer = $this->answerTo($method, $nowhere, $nobody);
                if ($theirAnswer !== $noAnswer || $theirAnswer[0] < 400) {
                    $answered[] = "$method $path ($name) answered {$theirAnswer[0]}, and {$noAnswer[0]} for a company that does not exist";
                }
            }
        }

        // Every route under a company, a module's included: losing a module's routes must red, not shrink the sweep.
        self::assertGreaterThan(250, $swept, "the sweep reached $swept company routes");
        self::assertSame([], $answered, 'these routes answer for a company the caller is not a member of');
    }

    /**
     * The status and the body, the company's id in it written out of the way: a body that names the company it was
     * asked about is the same answer for any company.
     *
     * @return array{int, string}
     */
    private function answerTo(string $method, string $path, string $companyId): array
    {
        $this->sendJson($method, $path, 'GET' === $method ? null : []);
        $response = $this->client->getResponse();

        $body = str_replace($companyId, '{companyId}', (string) $response->getContent());
        $decoded = json_decode($body, true);
        // The debug trace says which line refused, which production never sends: a JSON error's `trace`, and all but
        // the first line of the debug page a plain controller's error renders, which names the message and the status.
        if (\is_array($decoded)) {
            unset($decoded['trace']);
            $body = (string) json_encode($decoded);
        } elseif (str_starts_with($body, '<!-- ')) {
            $body = strtok($body, "\n") ?: '';
        }

        return [$response->getStatusCode(), $body];
    }
}
