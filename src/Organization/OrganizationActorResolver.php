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

use App\Entity\User;
use App\Organization\Domain\Organization;
use App\Organization\EventStore\Actor;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Decides the label an organization event is recorded under, for every manager. An owner acts as a
 * plain member, a platform moderator who is not an owner acts as `packagist-admin`, and any other
 * member (e.g. one leaving on their own) acts as a member too. Ownership is decided by owners-team
 * membership, not by who originally created the org.
 */
final readonly class OrganizationActorResolver
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function resolve(Organization $aggregate, User $actor): Actor
    {
        return $this->resolveFor($aggregate->isOwner($actor->getId()), $actor);
    }

    /**
     * For callers that establish ownership from the read model rather than an aggregate.
     */
    public function resolveFor(bool $isOwner, User $actor): Actor
    {
        // ROLE_ADMIN_ORGS is what grants org moderation in OrganizationVoter, so it is also what makes
        // an action a packagist-admin action.
        if (!$isOwner && $this->security->isGranted('ROLE_ADMIN_ORGS')) {
            return Actor::packagistAdmin($actor);
        }

        return Actor::member($actor);
    }
}
