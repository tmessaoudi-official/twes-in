<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Session;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How long a company an account has proved itself for stays open to the sign-in is the operator's (app.company_proof.idle
 * while used, app.company_proof.lifetime in all: CompanyEntry), within bounds checked when the container is built. Their
 * ceilings are the shipped thirty minutes and twelve hours the proof was ruled with, a session's own, so an operator may
 * only shorten them; under five minutes idle, or an hour in all, the person moving between two companies proves themself
 * again at nearly every switch. The idle ceiling sits below the floor in all, so a proof is never idle longer than it lasts.
 */
final class CompanyProofBounds implements CompilerPassInterface
{
    public const string IDLE = 'app.company_proof.idle';
    public const string LIFETIME = 'app.company_proof.lifetime';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::duration($container, self::IDLE, 300, 1_800, 'five minutes to thirty (PT5M to PT30M)');
        ParameterBounds::duration($container, self::LIFETIME, 3_600, 43_200, 'an hour to twelve (PT1H to PT12H)');
    }
}
