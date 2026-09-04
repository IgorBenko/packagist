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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Read-model projection of an organization's policies: the security bar every member acting for the org
 * must clear. One row per org, written only by {@see \App\Organization\Projection\OrganizationReadModelProjector}
 * from the policy events.
 *
 * The row is created lazily, the first time a policy is set, so an org with no row has every policy at
 * its default (inactive) rather than a missing one.
 */
#[ORM\Entity(repositoryClass: OrganizationPolicyRepository::class)]
#[ORM\Table(name: 'organization_policy')]
class OrganizationPolicy
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $orgId,

        /** Whether every member must have two-factor authentication active. */
        #[ORM\Column]
        public bool $enforceTwoFactor,

        #[ORM\Column(type: 'datetime_immutable')]
        public \DateTimeImmutable $updatedAt,

        /**
         * The domains a member's account email may be on, empty when the policy is off.
         * {@see OrganizationPolicyRepository::policiesFor()} is the one reader and builds the value object.
         *
         * @var list<string>
         */
        #[ORM\Column(type: Types::JSON)]
        public array $allowedEmailDomains = [],
    ) {
    }
}
