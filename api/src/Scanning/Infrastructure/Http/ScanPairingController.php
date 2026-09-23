<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Scanning\Infrastructure\Http;

use App\Identity\Domain\UserRepository;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Scanning\Application\PairingEcho;
use App\Scanning\Application\PhonePairings;
use App\Scanning\Domain\ScanPairingRefused;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * The computer tab's side of a phone pairing (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4), signed in and acting for
 * a company with product.read, the right every scan handler needs anyway. Documented in ScanningOpenApi.
 */
#[AsController]
final readonly class ScanPairingController
{
    private const string PATH = '/api/companies/{companyId}/scan-pairings';

    public function __construct(
        private PhonePairings $pairings,
        private CompanyGuard $guard,
        private Security $security,
        private UserRepository $users,
    ) {
    }

    #[Route(self::PATH, name: 'api_scan_pairing_open', requirements: ['companyId' => Requirement::UUID], methods: ['POST'])]
    public function open(string $companyId, Request $request): Response
    {
        $tab = (string) $request->headers->get('X-Tab', '');
        if ('' === $tab || \strlen($tab) > 64) {
            return PairingErrors::invalid('A pairing is opened by a tab: the X-Tab header names it.');
        }
        $company = $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), ProductPermission::READ);
        $user = $this->users->ofId($this->userId()) ?? throw new \LogicException('The signed-in account exists.');
        $opened = $this->pairings->open($company, $user, $tab);

        return new JsonResponse(['id' => $opened->id->toRfc4122(), 'link' => $opened->link], Response::HTTP_CREATED);
    }

    #[Route(self::PATH.'/{id}/heartbeat', name: 'api_scan_pairing_heartbeat', requirements: ['companyId' => Requirement::UUID, 'id' => Requirement::UUID], methods: ['POST'])]
    public function heartbeat(string $companyId, string $id): Response
    {
        return $this->guarded($companyId, fn () => $this->pairings->renew($this->userId(), Uuid::fromString($id)));
    }

    #[Route(self::PATH.'/{id}', name: 'api_scan_pairing_end', requirements: ['companyId' => Requirement::UUID, 'id' => Requirement::UUID], methods: ['DELETE'])]
    public function end(string $companyId, string $id): Response
    {
        return $this->guarded($companyId, fn () => $this->pairings->end($this->userId(), Uuid::fromString($id)));
    }

    #[Route(self::PATH.'/{id}/echo', name: 'api_scan_pairing_echo', requirements: ['companyId' => Requirement::UUID, 'id' => Requirement::UUID], methods: ['POST'])]
    public function echo(string $companyId, string $id, Request $request): Response
    {
        $body = PairingErrors::body($request);
        try {
            $echo = PairingEcho::fromArray($body);
        } catch (\InvalidArgumentException $malformed) {
            return PairingErrors::invalid($malformed->getMessage());
        }

        return $this->guarded($companyId, fn () => $this->pairings->echo($this->userId(), Uuid::fromString($id), $echo), Response::HTTP_ACCEPTED);
    }

    private function guarded(string $companyId, \Closure $act, int $status = Response::HTTP_NO_CONTENT): Response
    {
        $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), ProductPermission::READ);
        try {
            $act();
        } catch (ScanPairingRefused $refused) {
            return PairingErrors::refused($refused);
        }

        return new Response(null, $status);
    }

    private function userId(): Uuid
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            // access_control requires ROLE_USER on these paths, so this is a contradiction, not a user error.
            throw new \LogicException('The tab side of a pairing runs behind the firewall.');
        }

        return $account->getId();
    }
}
