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

namespace App\Entity;

use App\Organization\Domain\UnmetPolicies;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Read-model projection of an org-level membership. A member's access is still derived from their team
 * memberships ({@see OrganizationTeamMember}); this row carries the org-scoped facts that team rows
 * cannot express (when they joined, whether they are suspended). It carries no role: an owner is simply
 * a member of the `owners` team.
 *
 * Maintained by {@see \App\Organization\Projection\OrganizationReadModelProjector} alongside team
 * membership so it stays consistent with the org aggregate, the source of truth for membership.
 */
#[ORM\Entity(repositoryClass: OrganizationMemberRepository::class)]
#[ORM\Table(name: 'organization_member')]
#[ORM\Index(name: 'org_member_user_idx', columns: ['userId'])]
class OrganizationMember
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $orgId,

        #[ORM\Id]
        #[ORM\Column]
        public readonly int $userId,

        #[ORM\Column(type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $joinedAt,

        /**
         * Whether the member fails an active org policy. Their membership and teams are untouched; only
         * their ability to act for the org is inert until they comply again. Denormalised from the policy
         * set so counting suspended members stays a single indexed lookup.
         */
        #[ORM\Column]
        public bool $suspended = false,

        /**
         * Every policy they fail, empty when they are not suspended. The full set is stored so a suspended
         * member can be shown all of it at once, and so clearing one policy restores only the members who
         * failed nothing else.
         */
        #[ORM\Column(type: 'unmet_policies')]
        public UnmetPolicies $suspendedPolicies = new UnmetPolicies(),
    ) {
    }
}
