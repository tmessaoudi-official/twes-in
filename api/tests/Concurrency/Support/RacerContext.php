<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support;

use App\Identity\Application\StepUp\StepUpProofs;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Domain\Model\Company;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

/** What a racer's step reads before it acts: the race's company, a racer's own element of a list, and a proof given again. */
final class RacerContext
{
    /** @param array<string, mixed> $arguments */
    public static function company(TestContainer $container, array $arguments): Company
    {
        $company = $container->get(EntityManagerInterface::class)->find(Company::class, Uuid::fromString(Rows::text($arguments['company'] ?? null)));

        return $company instanceof Company ? $company : throw new \LogicException('The race names no company of the stress database.');
    }

    /**
     * The racer's own element of the list `$key`, by its index.
     *
     * @param array<string, mixed> $arguments
     */
    public static function mine(array $arguments, string $key, int $index): string
    {
        $list = $arguments[$key] ?? null;

        return Rows::text(\is_array($list) ? ($list[$index] ?? null) : null);
    }

    /**
     * A proof of who is at the screen given a moment ago, as a request after the step-up dialog carries one: the proof
     * lives in the sign-in's session, and a racer has no request of its own, so it is handed one.
     */
    public static function provedAgain(TestContainer $container, Uuid $userId): void
    {
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        $container->get(StepUpProofs::class)->remember($userId, $container->get(ClockInterface::class)->now());
    }
}
