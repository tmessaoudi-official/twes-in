<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App;

use App\Identity\Infrastructure\Mfa\RecoveryCodeBounds;
use App\Identity\Infrastructure\Password\PasswordResetBounds;
use App\Identity\Infrastructure\Session\SessionBounds;
use App\Identity\Infrastructure\StepUp\StepUpBounds;
use App\Shared\Infrastructure\DevelopmentKeys;
use App\Shared\Infrastructure\ErrorReporting\ErrorReportingMasksBounds;
use App\Tenancy\Infrastructure\Http\SupportVisitBounds;
use App\Tenancy\Infrastructure\Invitation\InvitationBounds;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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

    /** A deployment whose operator parameters are out of their bounds is refused when its container is built. */
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new StepUpBounds());
        $container->addCompilerPass(new SessionBounds());
        $container->addCompilerPass(new SupportVisitBounds());
        $container->addCompilerPass(new RecoveryCodeBounds());
        $container->addCompilerPass(new PasswordResetBounds());
        $container->addCompilerPass(new InvitationBounds());
        $container->addCompilerPass(new ErrorReportingMasksBounds());
    }

    /**
     * Symfony's documented override of the cache directory: the API suite's parallel workers each compile their own
     * container and keep their own cache pools there, as one worker's clearing a pool must not reach another's test.
     */
    public function getCacheDir(): string
    {
        $directory = $_SERVER['APP_CACHE_DIR'] ?? null;

        return \is_string($directory) && '' !== $directory ? $directory.'/'.$this->environment : parent::getCacheDir();
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    protected function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
