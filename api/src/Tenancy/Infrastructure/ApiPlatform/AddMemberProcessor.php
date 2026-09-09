<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\Application\Company\AddMember;
use App\Tenancy\Application\Company\AddMemberRequest;
use App\Tenancy\Application\Company\AlreadyAMember;
use App\Tenancy\Application\Company\MemberView;
use App\Tenancy\Application\Company\UnknownRole;
use App\Tenancy\Application\Company\UserNotFound;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<MemberResource, MemberResource> */
final readonly class AddMemberProcessor implements ProcessorInterface
{
    public function __construct(private AddMember $addMember, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MemberResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), 'user.write');

        try {
            $membership = $this->addMember->handle(
                new AddMemberRequest($company->getId(), $data->email, $data->role),
                $this->guard->account()->getId(),
            );
        } catch (AlreadyAMember $already) {
            throw new ConflictHttpException($already->getMessage(), $already);
        } catch (UserNotFound|UnknownRole $refused) {
            // No user with that address yet: an invitation is the answer, and it lands in the second half of G1b.
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }

        return MemberResource::of(new MemberView(
            $membership->getUser()->getId()->toRfc4122(),
            $membership->getUser()->getEmail()->value,
            $membership->getUser()->getDisplayName(),
            $membership->getRole()->getName(),
            $membership->getCreatedAt()->format(\DATE_ATOM),
        ));
    }
}
