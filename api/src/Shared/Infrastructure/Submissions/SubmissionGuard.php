<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Submissions;

use App\Identity\Infrastructure\Security\SecurityUser;
use App\Shared\Infrastructure\Effects\EffectsAnswer;
use App\Shared\Infrastructure\Http\RoutedPath;
use App\Shared\Infrastructure\Realtime\LiveChangesAnswer;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Holds a route that takes part in « one submission, one write » (`Submissions`) to its key: claims it before the work,
 * answers again what the first answered to the same key and body, and refuses a missing key, a key that is not one, a
 * key sent again with another body and a key whose work is still running. After the work, keeps the answer when the
 * work was made and frees the key when it was not. Runs once the firewall has said who is asking.
 */
final readonly class SubmissionGuard
{
    /** The request attribute naming the claim this request won, so only that one is answered or freed. */
    public const string WON = '_submission_won';

    /** The answer's headers a replay writes back: what the screen of a tab that lost the first answer reads. */
    private const array KEPT_HEADERS = ['Content-Type', 'Location', LiveChangesAnswer::HEADER, EffectsAnswer::HEADER];

    public function __construct(
        private Security $security,
        private SubmissionClaims $claims,
        private ClaimedSubmission $claimed,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire('%app.long_request.max_seconds%')] private int $longestRequestSeconds,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !Submissions::guards($request)) {
            return;
        }
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            return;
        }
        try {
            $key = Submissions::keyOf($request);
        } catch (\InvalidArgumentException) {
            $event->setResponse(self::problem(Submissions::KEY_INVALID, Response::HTTP_BAD_REQUEST, 'The Idempotency-Key header is not a UUID.'));

            return;
        }
        if (null === $key) {
            $event->setResponse(self::problem(Submissions::KEY_MISSING, Response::HTTP_BAD_REQUEST, 'This write adds to what is there: send an Idempotency-Key header naming this attempt.'));

            return;
        }

        $fingerprint = Submissions::fingerprintOf($request);
        $now = $this->clock->now();
        $company = $request->attributes->get('companyId');
        $wanted = new HeldClaim($account->getId(), $key, Uuid::v7());
        $held = $this->claims->claim(
            $wanted,
            \is_string($company) && Uuid::isValid($company) ? Uuid::fromString($company) : null,
            $fingerprint,
            $now,
            $now->modify(\sprintf('-%d seconds', $this->longestRequestSeconds)),
        );
        if (null === $held) {
            $request->attributes->set(self::WON, $wanted);
            $this->claimed->hold($wanted);

            return;
        }

        $event->setResponse(match (true) {
            $held['fingerprint'] !== $fingerprint => self::problem(Submissions::KEY_REUSED, Response::HTTP_UNPROCESSABLE_ENTITY, 'This Idempotency-Key was sent with another request: name each attempt with a key of its own.'),
            null !== $held['status'] && null !== $held['body'] => new Response($held['body'], $held['status'], [...$held['headers'], Submissions::REPLAYED_HEADER => 'true']),
            $held['workDone'] => self::problem(Submissions::UNANSWERED, Response::HTTP_CONFLICT, 'This request was made, and its answer was lost: read the record again.'),
            default => self::problem(Submissions::IN_PROGRESS, Response::HTTP_CONFLICT, 'This request is still being worked on.'),
        });
    }

    /** Last, once every other listener has written its headers. */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -64)]
    public function onResponse(ResponseEvent $event): void
    {
        $won = $event->getRequest()->attributes->get(self::WON);
        if (!$event->isMainRequest() || !$won instanceof HeldClaim) {
            return;
        }
        if (!$this->claimed->made()) {
            $this->claims->release($won);

            return;
        }
        $response = $event->getResponse();
        $body = $response->getContent();
        // The work was made, so an answer saying it failed would be untrue each time it is replayed, and a stream cannot be
        // kept: the claim stays done and unanswered, and the key answers that the work was made and is to be read again.
        if ($response->isServerError() || false === $body) {
            $this->logger->error('A submission made its work but its answer cannot be kept ({status}): {path}', ['status' => $response->getStatusCode(), 'path' => RoutedPath::of($event->getRequest())]);

            return;
        }
        $headers = [];
        foreach (self::KEPT_HEADERS as $name) {
            $value = $response->headers->get($name);
            if (null !== $value) {
                $headers[$name] = $value;
            }
        }
        $this->claims->answer($won, $response->getStatusCode(), $headers, $body);
    }

    private static function problem(string $type, int $status, string $detail): JsonResponse
    {
        return new JsonResponse(['type' => $type, 'title' => Response::$statusTexts[$status], 'status' => $status, 'detail' => $detail], $status, ['Content-Type' => 'application/problem+json']);
    }
}
