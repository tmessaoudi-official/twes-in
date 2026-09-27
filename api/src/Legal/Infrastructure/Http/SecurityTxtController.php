<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Infrastructure\Http;

use App\Legal\Application\LegalIdentity;
use App\Legal\Application\SecurityTxt;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `/.well-known/security.txt`, which the web server maps here (`infra/web/nginx.conf`). Public by the firewall, like
 * the legal pages, since whoever reports a vulnerability has no account.
 */
final readonly class SecurityTxtController
{
    public function __construct(private LegalIdentity $identity, private SecurityTxt $file, private ClockInterface $clock)
    {
    }

    #[Route('/api/legal/security.txt', name: 'api_legal_security_txt', methods: ['GET'])]
    public function __invoke(): Response
    {
        $text = $this->file->render($this->identity->values(), $this->clock->now());
        if (null === $text) {
            throw new NotFoundHttpException('No security contact is filled in.');
        }

        return new Response($text, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
