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

use App\Organization\Domain\OrganizationPolicies;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<OrganizationPolicy>
 */
class OrganizationPolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationPolicy::class);
    }

    /**
     * Null when the org has never set a policy, which is equivalent to every policy being inactive.
     */
    public function findForOrg(Ulid $orgId): ?OrganizationPolicy
    {
        return $this->findOneBy(['orgId' => $orgId]);
    }

    /**
     * The org's active policies as the domain sees them, so a read-model caller can ask whether someone
     * satisfies them without reconstituting the aggregate. An org with no row has none active.
     */
    public function policiesFor(Ulid $orgId): OrganizationPolicies
    {
        return new OrganizationPolicies($this->findForOrg($orgId)?->enforceTwoFactor === true);
    }
}
