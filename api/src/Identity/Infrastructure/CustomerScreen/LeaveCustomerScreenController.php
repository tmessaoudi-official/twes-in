<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\CustomerScreen;

use App\Identity\Application\CustomerScreen\LeaveCustomerScreen;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The way back from the customer screen, once the clerk proved who they are (docs/SPEC.md § 7, 2026-10-06 19:44). */
final readonly class LeaveCustomerScreenController
{
    public function __construct(private Security $security, private LeaveCustomerScreen $leave)
    {
    }

    #[Route('/api/auth/customer-screen', name: 'api_auth_customer_screen_leave', methods: ['DELETE'])]
    public function __invoke(): Response
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            // access_control requires ROLE_USER here, so this is a contradiction, not a user error.
            throw new \LogicException('Leaving the customer screen runs behind the firewall.');
        }

        try {
            $this->leave->handle($account->getId());
        } catch (StepUpRequired) {
            return new JsonResponse(['error' => 'step_up_required'], Response::HTTP_FORBIDDEN);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
