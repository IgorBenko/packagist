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

namespace App\Tests\Organization;

use App\Entity\Organization as OrganizationReadModel;
use App\Entity\OrganizationMemberRepository;
use App\Entity\OrganizationPolicyRepository;
use App\Entity\OrganizationRepository;
use App\Entity\User;
use App\Organization\Domain\Exception\TwoFactorRequiredException;
use App\Organization\Domain\Organization;
use App\Organization\Domain\PolicyComplianceReason;
use App\Organization\EventStore\Actor;
use App\Organization\EventStore\EventStore;
use App\Organization\OrganizationManager;
use App\Organization\OrganizationPolicyEnforcer;
use App\Organization\OrganizationPolicyManager;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;

class OrganizationPolicyTest extends IntegrationTestCase
{
    public function testEnablingTwoFactorEnforcementProjectsThePolicyRow(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);

        // No policy has ever been set, so there is no row: the defaults stand in for it.
        self::assertNull($this->policies()->findForOrg($organization->id));
        self::assertFalse($this->policies()->policiesFor($organization->id)->enforceTwoFactor);

        $this->policyManager()->setTwoFactorEnforcement($organization, $owner, true, null);

        $policy = $this->policies()->findForOrg($organization->id);
        self::assertNotNull($policy);
        self::assertTrue($policy->enforceTwoFactor);
    }

    public function testEnablingSuspendsMembersWithoutTwoFactorAndLogsIt(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $member = $this->joinAsMember($organization, 'plainmember', withTwoFactor: false);

        $this->policyManager()->setTwoFactorEnforcement($organization, $owner, true, null);

        $memberRow = $this->members()->findOneByOrgAndUser($organization->id, $member->getId());
        self::assertNotNull($memberRow);
        self::assertTrue($memberRow->suspended);
        self::assertSame(PolicyComplianceReason::TwoFactor, $memberRow->suspendedReason);
        self::assertSame(1, $this->members()->countSuspended($organization->id));

        // The owner has 2FA, so they are unaffected.
        $ownerRow = $this->members()->findOneByOrgAndUser($organization->id, $owner->getId());
        self::assertNotNull($ownerRow);
        self::assertFalse($ownerRow->suspended);

        self::assertSame(1, $this->auditCount($organization, 'organization_two_factor_enforcement_enabled'));
        self::assertSame(1, $this->auditCount($organization, 'organization_member_access_suspended'));
    }

    public function testDisablingRestoresTheSuspendedMembersAndLogsIt(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $member = $this->joinAsMember($organization, 'plainmember', withTwoFactor: false);

        $this->policyManager()->setTwoFactorEnforcement($organization, $owner, true, null);
        $this->policyManager()->setTwoFactorEnforcement($organization, $owner, false, null);

        $memberRow = $this->members()->findOneByOrgAndUser($organization->id, $member->getId());
        self::assertNotNull($memberRow);
        self::assertFalse($memberRow->suspended);
        self::assertNull($memberRow->suspendedReason);

        self::assertSame(1, $this->auditCount($organization, 'organization_two_factor_enforcement_disabled'));
        self::assertSame(1, $this->auditCount($organization, 'organization_member_access_restored'));
    }

    public function testEnablingRequiresTheActorToHaveTwoFactor(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: false);
        $organization = $this->createOrg($owner);

        $this->expectException(TwoFactorRequiredException::class);
        $this->policyManager()->setTwoFactorEnforcement($organization, $owner, true, null);
    }

    public function testEnforcerSuspendsAMemberWhoDropsOutOfCompliance(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $member = $this->joinAsMember($organization, 'plainmember', withTwoFactor: true);

        $this->policyManager()->setTwoFactorEnforcement($organization, $owner, true, null);

        // Compliant when the policy went live, so nothing was recorded then.
        $memberRow = $this->members()->findOneByOrgAndUser($organization->id, $member->getId());
        self::assertNotNull($memberRow);
        self::assertFalse($memberRow->suspended);

        $member->setTotpSecret(null);
        static::getEM()->flush();

        self::assertSame(PolicyComplianceReason::TwoFactor, $this->enforcer()->enforce($organization, $member));
        self::assertTrue($memberRow->suspended);
        self::assertSame(1, $this->auditCount($organization, 'organization_member_access_suspended'));
    }

    public function testEnforcerRestoresAMemberWhoComesBackIntoCompliance(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $member = $this->joinAsMember($organization, 'plainmember', withTwoFactor: false);

        $this->policyManager()->setTwoFactorEnforcement($organization, $owner, true, null);

        $member->setTotpSecret('totp-secret');
        static::getEM()->flush();

        self::assertNull($this->enforcer()->enforce($organization, $member));

        $memberRow = $this->members()->findOneByOrgAndUser($organization->id, $member->getId());
        self::assertNotNull($memberRow);
        self::assertFalse($memberRow->suspended);
        self::assertSame(1, $this->auditCount($organization, 'organization_member_access_restored'));
    }

    public function testEnforcerRecordsNothingWhenTheVerdictIsUnchanged(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $member = $this->joinAsMember($organization, 'plainmember', withTwoFactor: false);

        $this->policyManager()->setTwoFactorEnforcement($organization, $owner, true, null);
        $eventsAfterEnabling = $this->eventCount($organization);

        // Already suspended for exactly this reason: re-verifying must not append anything.
        self::assertSame(PolicyComplianceReason::TwoFactor, $this->enforcer()->enforce($organization, $member));
        self::assertSame($eventsAfterEnabling, $this->eventCount($organization));
        self::assertSame(1, $this->auditCount($organization, 'organization_member_access_suspended'));
    }

    public function testEnforcerIgnoresNonMembers(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $outsider = $this->persistUser('outsider', withTwoFactor: false);

        $this->policyManager()->setTwoFactorEnforcement($organization, $owner, true, null);

        self::assertNull($this->enforcer()->enforce($organization, $outsider));
        self::assertSame(0, $this->members()->countSuspended($organization->id));
    }

    private function createOrg(User $owner): OrganizationReadModel
    {
        static::getService(OrganizationManager::class)->create($owner, $owner, 'acme', 'ACME Corp', null);

        $organization = static::getService(OrganizationRepository::class)->findOneBySlug('acme');
        self::assertNotNull($organization);

        return $organization;
    }

    /**
     * A second org member, created the way the invitation flow would: MemberJoined on the org stream plus
     * the all-members team placement.
     */
    private function joinAsMember(OrganizationReadModel $organization, string $username, bool $withTwoFactor): User
    {
        $user = $this->persistUser($username, $withTwoFactor);
        $eventStore = static::getService(EventStore::class);

        $aggregate = Organization::reconstitute($organization->id, $eventStore->loadHistory($organization->id));
        $aggregate->joinViaInvitation($user->getId(), [$organization->allMembersTeamId], new Ulid());
        $eventStore->append($aggregate, Actor::member($user), null);

        return $user;
    }

    private function auditCount(OrganizationReadModel $organization, string $type): int
    {
        return (int) static::getService(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE type = :type AND organizationId = :org',
            ['type' => $type, 'org' => $organization->id->toBinary()],
        );
    }

    private function eventCount(OrganizationReadModel $organization): int
    {
        return (int) static::getService(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM organization_event WHERE aggregateId = :org',
            ['org' => $organization->id->toBinary()],
        );
    }

    private function policies(): OrganizationPolicyRepository
    {
        return static::getService(OrganizationPolicyRepository::class);
    }

    private function members(): OrganizationMemberRepository
    {
        return static::getService(OrganizationMemberRepository::class);
    }

    private function policyManager(): OrganizationPolicyManager
    {
        return static::getService(OrganizationPolicyManager::class);
    }

    private function enforcer(): OrganizationPolicyEnforcer
    {
        return static::getService(OrganizationPolicyEnforcer::class);
    }

    private function persistUser(string $username, bool $withTwoFactor): User
    {
        $user = new User();
        $user->setEnabled(true);
        $user->setUsername($username);
        $user->setUsernameCanonical($username);
        $user->setEmail($username.'@example.org');
        $user->setEmailCanonical($username.'@example.org');
        $user->setPassword('testtest');
        if ($withTwoFactor) {
            $user->setTotpSecret('totp-secret');
        }

        $em = static::getEM();
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
