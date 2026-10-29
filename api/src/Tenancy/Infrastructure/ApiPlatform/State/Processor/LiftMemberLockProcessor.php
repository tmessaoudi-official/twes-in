<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\StepUp\RecentProof;
use App\Tenancy\Application\Member\LiftMemberLock;
use App\Tenancy\Application\Member\ListMembers;
use App\Tenancy\Application\Member\NotAMember;
use App\Tenancy\Application\Member\OwnerOnly;
use App\Tenancy\Application\Member\OwnMembership;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\MemberResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\Security\MemberPermission;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * The lock on a member's password lifted by the company's owner, with a recent proof, as changing a member asks;
 * answers the member's row as the list shows it.
 *
 * @implements ProcessorInterface<mixed, MemberResource>
 */
final readonly class LiftMemberLockProcessor implements ProcessorInterface
{
    public function __construct(private LiftMemberLock $lift, private ListMembers $members, private CompanyGuard $guard, private RecentProof $proof)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MemberResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), MemberPermission::WRITE);
        $raw = $uriVariables['userId'] ?? null;
        if (!\is_string($raw) || !Uuid::isValid($raw)) {
            throw new NotFoundHttpException('No such member.');
        }
        $userId = Uuid::fromString($raw);
        $actorId = $this->guard->account()->getId();

        try {
            // What the role refuses is said first: nobody proves who they are only to be told no.
            $this->lift->assertMayLift($company->getId(), $userId, $actorId);
            $this->proof->demand($actorId);
            $this->lift->handle($company->getId(), $userId, $actorId);
        } catch (NotAMember $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (OwnerOnly|OwnMembership $refused) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }

        foreach ($this->members->for($company->getId()) as $view) {
            if ($userId->toRfc4122() === $view->userId) {
                return MemberResource::of($view);
            }
        }

        throw new NotFoundHttpException('No such member.');
    }
}
