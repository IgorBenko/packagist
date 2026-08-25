<?php declare(strict_types=1);

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Organization;

use App\Entity\Organization as OrganizationReadModel;
use App\Entity\User;
use App\Organization\Domain\AllowedEmailDomains;
use App\Organization\Domain\Exception\EmailDomainMismatchException;
use App\Organization\Domain\Exception\TwoFactorRequiredException;
use App\Organization\Domain\Organization;
use App\Organization\EventStore\EventStore;

/**
 * Application service for the org's policies. Follows the reconstitute → command → append pattern of
 * {@see OrganizationManager}: the external facts each member is judged on are resolved here, the aggregate
 * decides who becomes suspended or restored, and the whole batch lands in one transaction.
 */
final class OrganizationPolicyManager
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly MemberPolicyFactsResolver $facts,
        private readonly OrganizationActorResolver $actorResolver,
    ) {
    }

    /**
     * Save the policies the org's policy page owns, as one command in one transaction. A policy whose
     * value is unchanged is a no-op, and a submission that any guard refuses records nothing at all.
     *
     * @throws TwoFactorRequiredException   the actor does not have 2FA themselves
     * @throws EmailDomainMismatchException the actor's own address is not on one of the domains
     */
    public function setPolicies(OrganizationReadModel $organization, User $actor, bool $enforceTwoFactor, AllowedEmailDomains $allowedEmailDomains, ?string $ip): void
    {
        $aggregate = Organization::reconstitute(
            $organization->id,
            $this->eventStore->loadHistory($organization->id),
        );

        // Layered onto the aggregate's own set, not the read model the form was rendered from, so a policy
        // this call does not carry keeps its value.
        $desired = $aggregate->policies()
            ->withTwoFactorEnforcement($enforceTwoFactor)
            ->withAllowedEmailDomains($allowedEmailDomains);

        $aggregate->setPolicies(
            $desired,
            $this->facts->forUser($actor),
            $this->facts->forUserIds($aggregate->members()),
        );

        $this->eventStore->append($aggregate, $this->actorResolver->resolve($aggregate, $actor), $ip);
    }
}
