<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Settings\Application\SupportSettings;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use App\Shared\Infrastructure\ErrorReporting\ClientErrorBounds;
use App\Shared\Infrastructure\Submissions\SubmissionRetryBounds;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/health — liveness for the web tier, CI's end-to-end job and any uptime monitor.
 * Public and unauthenticated by design; it discloses reachability and which build answers: the version and commit the
 * image was built as (scripts/build-version.sh), the kernel's environment and the deployment, which the web's footer
 * shows to everyone (docs/SPEC.md § 7, the build line). The source they name is public under the AGPL already. And the
 * monitor's public status page, which the same footer links to: read only while the database answers, where it is kept.
 * And where passkeys work, the host WebAuthn binds them to and the first address the API allows, which every passkey
 * ceremony's options name already: a page opened at another address, a shop's network one, offers none that cannot work.
 * And what the operator set for the page itself, which every page reads as it starts, signed out too: how many errors one
 * page load reports (app.client_errors.per_page) and how long a write whose answer never came keeps its key
 * (app.submissions.retry_minutes); and the durations a screen states, in seconds, so it never says a fixed hour the
 * operator changed: a reset link's life (app.password_reset.valid_for), a signup link's (app.signup.valid_for), a step-up
 * proof's (app.step_up.valid_for), and how long a delete, a merge or an import's undo (app.records.undoable_for) and an
 * erasure (app.erasure.undoable_for) can be taken back. Container parameters, so a degraded API still says them.
 */
final readonly class HealthController
{
    public function __construct(
        private DatabaseProbe $database,
        private ReadSetting $settings,
        #[Autowire(env: 'BUILD_VERSION')] private string $version,
        #[Autowire(env: 'BUILD_COMMIT')] private string $commit,
        #[Autowire('%kernel.environment%')] private string $mode,
        #[Autowire(env: 'DEPLOY_ENV')] private string $deployment,
        #[Autowire(param: 'app.webauthn.rp_id')] private string $passkeyHost,
        /** @var list<string> */
        #[Autowire(param: 'app.webauthn.origins')] private array $passkeyOrigins,
        #[Autowire(param: ClientErrorBounds::PER_PAGE)] private int $errorReportsPerPage,
        #[Autowire(param: SubmissionRetryBounds::RETRY_MINUTES)] private int $submissionRetryMinutes,
        #[Autowire(param: 'app.password_reset.valid_for')] private string $passwordResetValidFor,
        #[Autowire(param: 'app.signup.valid_for')] private string $signupValidFor,
        #[Autowire(param: 'app.step_up.valid_for')] private string $stepUpValidFor,
        #[Autowire(param: 'app.records.undoable_for')] private string $recordsUndoableFor,
        #[Autowire(param: 'app.erasure.undoable_for')] private string $erasureUndoableFor,
    ) {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $reachable = $this->database->isReachable();

        return new JsonResponse(
            [
                'status' => $reachable ? 'ok' : 'degraded',
                'database' => $reachable ? 'ok' : 'unreachable',
                'build' => ['version' => self::given($this->version), 'commit' => self::given($this->commit), 'mode' => $this->mode],
                'deployment' => self::given($this->deployment),
                'statusUrl' => $reachable ? $this->statusUrl() : null,
                'passkeys' => ['host' => $this->passkeyHost, 'address' => $this->passkeyOrigins[0] ?? null],
                'web' => [
                    'errorReportsPerPage' => $this->errorReportsPerPage,
                    'submissionRetryMinutes' => $this->submissionRetryMinutes,
                    'passwordResetSeconds' => self::seconds($this->passwordResetValidFor),
                    'signupSeconds' => self::seconds($this->signupValidFor),
                    'stepUpSeconds' => self::seconds($this->stepUpValidFor),
                    'recordsUndoableSeconds' => self::seconds($this->recordsUndoableFor),
                    'erasureUndoableSeconds' => self::seconds($this->erasureUndoableFor),
                ],
            ],
            $reachable ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private function statusUrl(): ?string
    {
        $value = $this->settings->value(new SettingContext(), SupportSettings::STATUS_URL);

        return \is_string($value) ? self::given(TypedText::trimmed($value)) : null;
    }

    /**
     * A duration the container holds, in seconds. Each is read as a DateInterval by the use case it times, so one that is
     * none would already fail there; said here rather than answered as a number nobody set.
     */
    private static function seconds(string $duration): int
    {
        return ParameterBounds::seconds($duration) ?? throw new \LogicException(\sprintf('%s is no ISO 8601 duration.', json_encode($duration)));
    }

    /** An empty value is one nobody gave: an image built without its version, a deployment that names none. */
    private static function given(string $value): ?string
    {
        return '' === $value ? null : $value;
    }
}
