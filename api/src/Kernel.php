<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App;

use App\Shared\Infrastructure\DevelopmentKeys;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Before the container is built, not after: a production instance running on the committed development keys
     * is not something to report, it is something to stop (docs/SPEC.md § 8 row 22, review S10). Dotenv has
     * already put the environment in `$_SERVER` by the time anything constructs a kernel.
     */
    public function boot(): void
    {
        DevelopmentKeys::check($this->getEnvironment(), $_SERVER + $_ENV);

        parent::boot();
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    protected function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
