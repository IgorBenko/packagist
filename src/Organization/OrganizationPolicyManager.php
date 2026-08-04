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
     * Start or stop requiring two-factor authentication from every member. An unchanged value is a no-op.
     *
     * @throws TwoFactorRequiredException the actor does not have 2FA themselves
     */
    public function setTwoFactorEnforcement(OrganizationReadModel $organization, User $actor, bool $enforced, ?string $ip): void
    {
        $aggregate = Organization::reconstitute(
            $organization->id,
            $this->eventStore->loadHistory($organization->id),
        );

        $aggregate->setTwoFactorEnforcement(
            $enforced,
            $actor->isTotpAuthenticationEnabled(),
            $this->facts->forUserIds($aggregate->members()),
        );

        $this->eventStore->append($aggregate, $this->actorResolver->resolve($aggregate, $actor), $ip);
    }

    /**
     * @throws EmailDomainMismatchException the actor's own address is not on one of the domains
     */
    public function setAllowedEmailDomains(OrganizationReadModel $organization, User $actor, AllowedEmailDomains $domains, ?string $ip): void
    {
        $aggregate = Organization::reconstitute(
            $organization->id,
            $this->eventStore->loadHistory($organization->id),
        );

        $aggregate->setAllowedEmailDomains(
            $domains,
            $this->facts->forUser($actor)->emailDomain,
            $this->facts->forUserIds($aggregate->members()),
        );

        $this->eventStore->append($aggregate, $this->actorResolver->resolve($aggregate, $actor), $ip);
    }
}
