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

use App\Entity\AuditRecordRepository;
use App\Entity\Organization as OrganizationReadModel;
use App\Entity\OrganizationMemberRepository;
use App\Entity\OrganizationPolicyRepository;
use App\Entity\OrganizationRepository;
use App\Entity\User;
use App\Organization\Domain\AllowedEmailDomains;
use App\Organization\Domain\Exception\EmailDomainMismatchException;
use App\Organization\Domain\Exception\TwoFactorRequiredException;
use App\Organization\Domain\Organization;
use App\Organization\Domain\PolicyComplianceReason;
use App\Organization\Domain\PolicyRemediation;
use App\Organization\Domain\DisplayName;
use App\Organization\EventStore\Actor;
use App\Organization\EventStore\ConcurrencyException;
use App\Organization\EventStore\EventStore;
use App\Organization\OrganizationManager;
use App\Organization\OrganizationPolicyEnforcer;
use App\Organization\OrganizationPolicyManager;
use App\Tests\IntegrationTestCase;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
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

        $this->policyManager()->setPolicies($organization, $owner, true, AllowedEmailDomains::none(), null);

        $policy = $this->policies()->findForOrg($organization->id);
        self::assertNotNull($policy);
        self::assertTrue($policy->enforceTwoFactor);
    }

    public function testEnablingSuspendsMembersWithoutTwoFactorAndLogsIt(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $member = $this->joinAsMember($organization, 'plainmember', withTwoFactor: false);

        $this->policyManager()->setPolicies($organization, $owner, true, AllowedEmailDomains::none(), null);

        $memberRow = $this->members()->findOneByOrgAndUser($organization->id, $member->getId());
        self::assertNotNull($memberRow);
        self::assertTrue($memberRow->suspended);
        self::assertSame([PolicyComplianceReason::TwoFactor], $memberRow->suspendedPolicies->reasons);
        self::assertSame(1, $this->members()->countSuspended($organization->id));

        // The owner has 2FA, so they are unaffected.
        $ownerRow = $this->members()->findOneByOrgAndUser($organization->id, $owner->getId());
        self::assertNotNull($ownerRow);
        self::assertFalse($ownerRow->suspended);

        self::assertSame(1, $this->auditCount($organization, 'organization_two_factor_enforcement_enabled'));
        self::assertSame(1, $this->auditCount($organization, 'organization_member_access_suspended'));

        // Recorded so the org's own audit log can name them; rendering them is the display factory's call.
        self::assertSame(['two_factor'], $this->auditAttributes($organization, 'organization_member_access_suspended')['policies']);
    }

    public function testDisablingRestoresTheSuspendedMembersAndLogsIt(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $member = $this->joinAsMember($organization, 'plainmember', withTwoFactor: false);

        $this->policyManager()->setPolicies($organization, $owner, true, AllowedEmailDomains::none(), null);
        $this->policyManager()->setPolicies($organization, $owner, false, AllowedEmailDomains::none(), null);

        $memberRow = $this->members()->findOneByOrgAndUser($organization->id, $member->getId());
        self::assertNotNull($memberRow);
        self::assertFalse($memberRow->suspended);
        self::assertTrue($memberRow->suspendedPolicies->isEmpty());

        self::assertSame(1, $this->auditCount($organization, 'organization_two_factor_enforcement_disabled'));
        self::assertSame(1, $this->auditCount($organization, 'organization_member_access_restored'));
    }

    public function testEnablingRequiresTheActorToHaveTwoFactor(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: false);
        $organization = $this->createOrg($owner);

        $this->expectException(TwoFactorRequiredException::class);
        $this->policyManager()->setPolicies($organization, $owner, true, AllowedEmailDomains::none(), null);
    }

    public function testEnforcerSuspendsAMemberWhoDropsOutOfCompliance(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $member = $this->joinAsMember($organization, 'plainmember', withTwoFactor: true);

        $this->policyManager()->setPolicies($organization, $owner, true, AllowedEmailDomains::none(), null);

        // Compliant when the policy went live, so nothing was recorded then.
        $memberRow = $this->members()->findOneByOrgAndUser($organization->id, $member->getId());
        self::assertNotNull($memberRow);
        self::assertFalse($memberRow->suspended);

        $member->setTotpSecret(null);
        static::getEM()->flush();

        self::assertSame([PolicyComplianceReason::TwoFactor], $this->enforcer()->enforce($organization, $member)->reasons);
        self::assertTrue($memberRow->suspended);
        self::assertSame(1, $this->auditCount($organization, 'organization_member_access_suspended'));
    }

    public function testEnforcerRestoresAMemberWhoComesBackIntoCompliance(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $member = $this->joinAsMember($organization, 'plainmember', withTwoFactor: false);

        $this->policyManager()->setPolicies($organization, $owner, true, AllowedEmailDomains::none(), null);

        $member->setTotpSecret('totp-secret');
        static::getEM()->flush();

        self::assertTrue($this->enforcer()->enforce($organization, $member)->isEmpty());

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

        $this->policyManager()->setPolicies($organization, $owner, true, AllowedEmailDomains::none(), null);
        $eventsAfterEnabling = $this->eventCount($organization);

        // Already suspended for exactly this reason: re-verifying must not append anything.
        self::assertSame([PolicyComplianceReason::TwoFactor], $this->enforcer()->enforce($organization, $member)->reasons);
        self::assertSame($eventsAfterEnabling, $this->eventCount($organization));
        self::assertSame(1, $this->auditCount($organization, 'organization_member_access_suspended'));
    }

    public function testEnforcerSuspendsAnOwnerWhoDropsTwoFactorWithNoPolicySet(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);

        // Nothing has ever been set on this org: 2FA for owners is a standing rule, not the policy.
        self::assertNull($this->policies()->findForOrg($organization->id));

        $owner->setTotpSecret(null);
        static::getEM()->flush();

        self::assertSame([PolicyComplianceReason::TwoFactor], $this->enforcer()->enforce($organization, $owner)->reasons);

        $ownerRow = $this->members()->findOneByOrgAndUser($organization->id, $owner->getId());
        self::assertNotNull($ownerRow);
        self::assertTrue($ownerRow->suspended);
        self::assertSame(1, $this->auditCount($organization, 'organization_member_access_suspended'));
    }

    public function testDisablingThePolicyLeavesAnOwnerWithoutTwoFactorSuspended(): void
    {
        // A packagist-admin can enable the policy without holding 2FA, which is the only way an owner who
        // lacks it is suspended by the enabling batch rather than by their own next request.
        $admin = $this->persistUser('orgadmin', withTwoFactor: true);
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $member = $this->joinAsMember($organization, 'plainmember', withTwoFactor: false);

        $owner->setTotpSecret(null);
        static::getEM()->flush();

        $this->policyManager()->setPolicies($organization, $admin, true, AllowedEmailDomains::none(), null);
        $this->policyManager()->setPolicies($organization, $admin, false, AllowedEmailDomains::none(), null);

        // The plain member is back; the owner is not, because the rule holding them was never the policy.
        $memberRow = $this->members()->findOneByOrgAndUser($organization->id, $member->getId());
        self::assertNotNull($memberRow);
        self::assertFalse($memberRow->suspended);

        $ownerRow = $this->members()->findOneByOrgAndUser($organization->id, $owner->getId());
        self::assertNotNull($ownerRow);
        self::assertTrue($ownerRow->suspended);
        self::assertSame([PolicyComplianceReason::TwoFactor], $ownerRow->suspendedPolicies->reasons);
    }

    public function testCompliantMemberDoesNotReplayTheEventStreamOnAPageView(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);

        $this->client->enableProfiler();
        $this->client->loginUser($owner);
        $this->client->request('GET', '/organizations/'.$organization->slug.'/policies');
        self::assertResponseIsSuccessful();

        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $streamReads = 0;
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                if (str_contains((string) $query['sql'], 'FROM organization_event')) {
                    ++$streamReads;
                }
            }
        }

        // The enforcer runs on every guarded action, so only a genuine change of verdict may touch the
        // stream: replaying it here would load the org's entire history for a page view.
        self::assertSame(0, $streamReads);
    }

    public function testEnforcerIgnoresNonMembers(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $outsider = $this->persistUser('outsider', withTwoFactor: false);

        $this->policyManager()->setPolicies($organization, $owner, true, AllowedEmailDomains::none(), null);

        self::assertTrue($this->enforcer()->enforce($organization, $outsider)->isEmpty());
        self::assertSame(0, $this->members()->countSuspended($organization->id));
    }

    public function testRequiringAnEmailDomainSuspendsTheMembersElsewhere(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $outsideMember = $this->persistUser('elsewhere', withTwoFactor: true, email: 'elsewhere@other.org');
        $this->joinExistingUser($organization, $outsideMember);

        $this->policyManager()->setPolicies($organization, $owner, false, new AllowedEmailDomains('example.org'), null);

        // The owner is on example.org and is untouched; the member on another domain is suspended.
        $ownerRow = $this->members()->findOneByOrgAndUser($organization->id, $owner->getId());
        self::assertNotNull($ownerRow);
        self::assertTrue($ownerRow->suspendedPolicies->isEmpty());

        $memberRow = $this->members()->findOneByOrgAndUser($organization->id, $outsideMember->getId());
        self::assertNotNull($memberRow);
        self::assertSame([PolicyComplianceReason::EmailDomain], $memberRow->suspendedPolicies->reasons);

        self::assertSame(1, $this->auditCount($organization, 'organization_allowed_email_domains_set'));
        self::assertSame(['example.org'], $this->auditAttributes($organization, 'organization_allowed_email_domains_set')['domains']);
    }

    public function testAMemberOnAnyOfTheRequiredDomainsComplies(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $member = $this->persistUser('brand', withTwoFactor: true, email: 'brand@acme.io');
        $this->joinExistingUser($organization, $member);

        $this->policyManager()->setPolicies($organization, $owner, false, new AllowedEmailDomains('example.org', 'acme.io'), null);

        self::assertTrue($this->enforcer()->enforce($organization, $member)->isEmpty());
        self::assertSame(0, $this->members()->countSuspended($organization->id));
    }

    public function testAnOwnerCannotRequireADomainTheyAreNotOn(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);

        // This would suspend the owner from their own org, so it is refused before anything is recorded.
        $this->expectException(EmailDomainMismatchException::class);
        $this->policyManager()->setPolicies($organization, $owner, false, new AllowedEmailDomains('acme.io'), null);
    }

    public function testClearingTheDomainRequirementRestoresTheMembersItSuspended(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $member = $this->persistUser('elsewhere', withTwoFactor: true, email: 'elsewhere@other.org');
        $this->joinExistingUser($organization, $member);

        $this->policyManager()->setPolicies($organization, $owner, false, new AllowedEmailDomains('example.org'), null);
        $this->policyManager()->setPolicies($organization, $owner, false, AllowedEmailDomains::none(), null);

        $memberRow = $this->members()->findOneByOrgAndUser($organization->id, $member->getId());
        self::assertNotNull($memberRow);
        self::assertTrue($memberRow->suspendedPolicies->isEmpty());
        self::assertSame(1, $this->auditCount($organization, 'organization_allowed_email_domains_cleared'));
    }

    /** The case the set exists for: two active policies, both unmet, both reported at once. */
    public function testAMemberCanFailTwoPoliciesAtOnce(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $member = $this->persistUser('elsewhere', withTwoFactor: false, email: 'elsewhere@other.org');
        $this->joinExistingUser($organization, $member);

        $this->policyManager()->setPolicies($organization, $owner, true, new AllowedEmailDomains('example.org'), null);

        $unmet = $this->enforcer()->enforce($organization, $member);
        self::assertSame(
            [PolicyComplianceReason::TwoFactor, PolicyComplianceReason::EmailDomain],
            $unmet->reasons,
        );
        // Neither policy alone can name the remedy, so the denial falls back to the suspension itself.
        self::assertNull($unmet->sole());

        // Both remediations are offered together, the domain one naming the domain.
        $remediations = $this->policies()->policiesFor($organization->id)->remediationsFor($unmet);
        self::assertSame(
            ['Enable two-factor authentication on your account.', 'Use an account email address on example.org.'],
            array_map(static fn (PolicyRemediation $remediation): string => $remediation->text, $remediations),
        );

        // And the read model carries the whole set, so the members list and the audit record do too.
        $memberRow = $this->members()->findOneByOrgAndUser($organization->id, $member->getId());
        self::assertNotNull($memberRow);
        self::assertSame(
            [PolicyComplianceReason::TwoFactor, PolicyComplianceReason::EmailDomain],
            $memberRow->suspendedPolicies->reasons,
        );
    }

    public function testAnOwnerTheMembershipTableDoesNotKnowStillNeedsTwoFactor(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);

        // The two tables are written together, so this state only arises when something diverged: a
        // projection that fell behind, or rows loaded outside the event stream. The voter grants management
        // off the owners team, so the enforcer must not answer "nothing unmet" for the same user.
        $memberRow = $this->members()->findOneByOrgAndUser($organization->id, $owner->getId());
        self::assertNotNull($memberRow);
        static::getEM()->remove($memberRow);
        $owner->setTotpSecret(null);
        static::getEM()->flush();

        self::assertSame([PolicyComplianceReason::TwoFactor], $this->enforcer()->enforce($organization, $owner)->reasons);
    }

    public function testAFlappingMemberCannotFillTheAuditLogFromPageViews(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $member = $this->joinAsMember($organization, 'plainmember', withTwoFactor: true);

        $this->policyManager()->setPolicies($organization, $owner, true, AllowedEmailDomains::none(), null);

        $enforcer = $this->enforcer();

        // Toggling their own second factor and loading an org page each time: every flip is a transition the
        // enforcer would otherwise record, from a GET, with nothing bounding it.
        for ($flip = 0; $flip < 16; ++$flip) {
            $member->setTotpSecret($flip % 2 === 0 ? null : 'totp-secret');
            static::getEM()->flush();

            // A fresh request each time, so the memo does not stand in for the re-check.
            $enforcer->reset();
            $enforcer->enforce($organization, $member);
        }

        $recorded = $this->auditCount($organization, 'organization_member_access_suspended')
            + $this->auditCount($organization, 'organization_member_access_restored');

        self::assertGreaterThan(0, $recorded, 'The first transitions are still recorded.');
        self::assertLessThanOrEqual(10, $recorded, 'Recording is capped per member per window.');

        // Access is decided from the current facts either way, not from the row the cap left behind.
        $member->setTotpSecret(null);
        static::getEM()->flush();
        $enforcer->reset();
        self::assertSame([PolicyComplianceReason::TwoFactor], $enforcer->enforce($organization, $member)->reasons);
    }

    public function testALostRaceDetachesWhatTheRequestWasHolding(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);

        $eventStore = static::getService(EventStore::class);
        $history = $eventStore->loadHistory($organization->id);

        // Two actors that read the same history, so the second one's sequence is already taken by the time
        // it appends.
        $winner = Organization::reconstitute($organization->id, $history);
        $loser = Organization::reconstitute($organization->id, $history);

        $winner->changeName(new DisplayName('ACME Inc'));
        $eventStore->append($winner, Actor::member($owner), null);

        $loser->changeName(new DisplayName('ACME GmbH'));

        $this->expectException(ConcurrencyException::class);

        try {
            $eventStore->append($loser, Actor::member($owner), null);
        } finally {
            // Why OrganizationPolicyEnforcer does not swallow this: the failing flush closes the manager, so
            // the append resets it and the entities the request was working with are detached.
            self::assertFalse(static::getEM()->contains($owner));
        }
    }

    public function testResettingTheEnforcerDropsTheRequestMemo(): void
    {
        $owner = $this->persistUser('orgowner', withTwoFactor: true);
        $organization = $this->createOrg($owner);
        $member = $this->joinAsMember($organization, 'plainmember', withTwoFactor: false);

        $this->policyManager()->setPolicies($organization, $owner, true, AllowedEmailDomains::none(), null);

        $enforcer = $this->enforcer();
        self::assertSame([PolicyComplianceReason::TwoFactor], $enforcer->enforce($organization, $member)->reasons);

        $member->setTotpSecret('totp-secret');
        static::getEM()->flush();

        // Memoized, so within the request the same instance stands by its answer rather than re-querying
        // for every voter call a page render makes.
        self::assertSame([PolicyComplianceReason::TwoFactor], $enforcer->enforce($organization, $member)->reasons);

        // A worker runtime hands the same instance to the next request, which must not inherit that.
        $enforcer->reset();
        self::assertTrue($enforcer->enforce($organization, $member)->isEmpty());
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

    /**
     * Join an already-persisted user, the way the invitation flow would: MemberJoined on the org stream plus
     * the all-members team placement.
     */
    private function joinExistingUser(OrganizationReadModel $organization, User $user): void
    {
        $eventStore = static::getService(EventStore::class);

        $aggregate = Organization::reconstitute($organization->id, $eventStore->loadHistory($organization->id));
        $aggregate->joinViaInvitation($user->getId(), [$organization->allMembersTeamId], new Ulid());
        $eventStore->append($aggregate, Actor::member($user), null);
    }

    private function auditCount(OrganizationReadModel $organization, string $type): int
    {
        return (int) static::getService(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE type = :type AND organizationId = :org',
            ['type' => $type, 'org' => $organization->id->toBinary()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function auditAttributes(OrganizationReadModel $organization, string $type): array
    {
        $records = static::getService(AuditRecordRepository::class)->findBy(
            ['type' => $type, 'organizationId' => $organization->id],
        );
        self::assertCount(1, $records);

        return $records[0]->attributes;
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

    private function persistUser(string $username, bool $withTwoFactor, ?string $email = null): User
    {
        $email ??= $username.'@example.org';

        $user = new User();
        $user->setEnabled(true);
        $user->setUsername($username);
        $user->setUsernameCanonical($username);
        $user->setEmail($email);
        $user->setEmailCanonical($email);
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
