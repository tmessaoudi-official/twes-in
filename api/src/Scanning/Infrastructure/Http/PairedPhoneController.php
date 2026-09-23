<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Scanning\Infrastructure\Http;

use App\Scanning\Application\PhonePairings;
use App\Scanning\Domain\ScanPairingRefused;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * The phone's side of a pairing (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4), with no session by design: it claims
 * the link once, then presents the key the claim gave it in `X-Pairing-Key`. It reads nothing of the company; all it
 * can do is hand a code or a tapped choice to the tab that lent it, and hear that tab's echoes. Public in
 * config/packages/security.yaml, budgeted in rate_limiter.yaml, documented in ScanningOpenApi.
 */
#[AsController]
final readonly class PairedPhoneController
{
    public const string KEY_HEADER = 'X-Pairing-Key';

    public function __construct(
        private PhonePairings $pairings,
        #[Target('scan_pairing_claim')]
        private RateLimiterFactoryInterface $claimLimiter,
        #[Target('scan_pairing_phone')]
        private RateLimiterFactoryInterface $phoneLimiter,
    ) {
    }

    #[Route('/api/scan-pairings/claim', name: 'api_scan_pairing_claim', methods: ['POST'])]
    public function claim(Request $request): Response
    {
        if (!$this->claimLimiter->create($request->getClientIp() ?? '')->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Too many pairing links tried from here; try again later.');
        }
        $link = PairingErrors::body($request)['link'] ?? null;
        if (!\is_string($link) || 1 !== preg_match('/^[0-9a-f]{64}$/', $link)) {
            return new JsonResponse(['error' => 'unknown'], Response::HTTP_NOT_FOUND);
        }
        try {
            $claimed = $this->pairings->claim($link);
        } catch (ScanPairingRefused $refused) {
            return PairingErrors::refused($refused);
        }

        return new JsonResponse(['id' => $claimed->id->toRfc4122(), 'key' => $claimed->key]);
    }

    #[Route('/api/scan-pairings/{id}/scans', name: 'api_scan_pairing_scan', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function scan(string $id, Request $request): Response
    {
        $body = PairingErrors::body($request);

        return $this->asThePhone($id, $request, function (Uuid $pairing, string $key) use ($body): Response {
            $code = $body['code'] ?? null;
            $scan = $body['scan'] ?? null;
            if (!\is_string($code) || !\is_string($scan)) {
                return PairingErrors::invalid('A scan is a code and the scan id the phone gave it.');
            }
            $this->pairings->scan($pairing, $key, $code, $scan);

            return new Response(null, Response::HTTP_ACCEPTED);
        });
    }

    #[Route('/api/scan-pairings/{id}/choices', name: 'api_scan_pairing_choice', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function choose(string $id, Request $request): Response
    {
        $body = PairingErrors::body($request);

        return $this->asThePhone($id, $request, function (Uuid $pairing, string $key) use ($body): Response {
            $echo = $body['echo'] ?? null;
            $choice = $body['choice'] ?? null;
            if (!\is_string($echo) || !\is_string($choice)) {
                return PairingErrors::invalid('A choice names the echo it answers and one of its choices.');
            }
            $this->pairings->choose($pairing, $key, $echo, $choice);

            return new Response(null, Response::HTTP_ACCEPTED);
        });
    }

    #[Route('/api/scan-pairings/{id}/realtime-token', name: 'api_scan_pairing_realtime_token', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function realtimeToken(string $id, Request $request): Response
    {
        return $this->asThePhone($id, $request, function (Uuid $pairing, string $key): Response {
            $token = $this->pairings->token($pairing, $key);

            return new JsonResponse(['token' => $token->token, 'expiresAt' => $token->expiresAt->format(\DATE_ATOM)]);
        });
    }

    /** @param \Closure(Uuid, string): Response $act */
    private function asThePhone(string $id, Request $request, \Closure $act): Response
    {
        if (!$this->phoneLimiter->create($id)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Too many scans at once; slow down.');
        }
        try {
            return $act(Uuid::fromString($id), (string) $request->headers->get(self::KEY_HEADER, ''));
        } catch (ScanPairingRefused $refused) {
            return PairingErrors::refused($refused);
        } catch (\InvalidArgumentException $malformed) {
            return PairingErrors::invalid($malformed->getMessage());
        }
    }
}
