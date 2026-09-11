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

use App\Organization\Domain\AllowedEmailDomains;
use App\Organization\Domain\Event\AllowedEmailDomainsEdited;
use App\Organization\Domain\Event\MemberPolicyComplianceFailed;
use App\Organization\Domain\Event\MemberPolicyComplianceRestored;
use App\Organization\Domain\Event\TwoFactorEnforcementEdited;
use App\Organization\Domain\Exception\EmailDomainMismatchException;
use App\Organization\Domain\Exception\TwoFactorRequiredException;
use App\Organization\Domain\MemberPolicyFacts;
use App\Organization\Domain\Organization;
use App\Organization\Domain\OrganizationPolicies;
use App\Organization\Domain\PolicyComplianceReason;
use App\Organization\EventStore\OrganizationEventType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

class OrganizationPolicyAggregateTest extends TestCase
{
    private const int OWNER = 1;
    private const int MEMBER = 2;

    public function testEnablingTwoFactorEnforcementSuspendsMembersWithout(): void
    {
        $organization = $this->orgWithSecondMember();

        self::enforceTwoFactor($organization, true, [
            self::OWNER => new MemberPolicyFacts(self::OWNER, true),
            self::MEMBER => new MemberPolicyFacts(self::MEMBER, false),
        ]);

        $events = $organization->pullPendingEvents();
        self::assertCount(2, $events);

        self::assertInstanceOf(TwoFactorEnforcementEdited::class, $events[0]);
        self::assertTrue($events[0]->enforced);

        // The owner has 2FA and is untouched; only the member without it is suspended.
        self::assertInstanceOf(MemberPolicyComplianceFailed::class, $events[1]);
        self::assertSame(self::MEMBER, $events[1]->userId);
        self::assertSame([PolicyComplianceReason::TwoFactor], $events[1]->unmetPolicies->reasons);

        self::assertSame([PolicyComplianceReason::TwoFactor], $organization->unmetPoliciesFor(self::MEMBER)->reasons);
        self::assertTrue($organization->unmetPoliciesFor(self::OWNER)->isEmpty());
    }

    public function testDisablingTwoFactorEnforcementRestoresTheMembersItSuspended(): void
    {
        $organization = $this->orgWithSecondMember();
        $facts = [
            self::OWNER => new MemberPolicyFacts(self::OWNER, true),
            self::MEMBER => new MemberPolicyFacts(self::MEMBER, false),
        ];

        self::enforceTwoFactor($organization, true, $facts);
        $organization->pullPendingEvents();

        self::enforceTwoFactor($organization, false, $facts);

        $events = $organization->pullPendingEvents();
        self::assertCount(2, $events);
        self::assertInstanceOf(TwoFactorEnforcementEdited::class, $events[0]);
        self::assertFalse($events[0]->enforced);
        self::assertInstanceOf(MemberPolicyComplianceRestored::class, $events[1]);
        self::assertSame(self::MEMBER, $events[1]->userId);

        self::assertTrue($organization->unmetPoliciesFor(self::MEMBER)->isEmpty());
    }

    public function testSettingTheSameValueIsANoOp(): void
    {
        $organization = $this->orgWithSecondMember();

        self::enforceTwoFactor($organization, false);

        self::assertSame([], $organization->pullPendingEvents());
    }

    public function testEnablingRequiresTheActorToHaveTwoFactor(): void
    {
        $organization = $this->orgWithSecondMember();

        // An owner cannot impose a policy that would immediately lock them out.
        $this->expectException(TwoFactorRequiredException::class);
        self::enforceTwoFactor($organization, true, actorHasTwoFactor: false);
    }

    public function testDisablingDoesNotRequireTheActorToHaveTwoFactor(): void
    {
        $organization = $this->orgWithSecondMember();
        self::enforceTwoFactor($organization, true);
        $organization->pullPendingEvents();

        self::enforceTwoFactor($organization, false, actorHasTwoFactor: false);

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TwoFactorEnforcementEdited::class, $events[0]);
    }

    public function testMemberWithoutFactsIsLeftAlone(): void
    {
        $organization = $this->orgWithSecondMember();

        // No facts at all, e.g. the user records have gone: the policy still changes, nobody is judged.
        self::enforceTwoFactor($organization, true);

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertTrue($organization->unmetPoliciesFor(self::MEMBER)->isEmpty());
    }

    public function testVerifyMemberComplianceSuspendsAndRestores(): void
    {
        $organization = $this->orgWithSecondMember();
        self::enforceTwoFactor($organization, true);
        $organization->pullPendingEvents();

        $organization->verifyMemberCompliance(new MemberPolicyFacts(self::MEMBER, false));

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MemberPolicyComplianceFailed::class, $events[0]);

        $organization->verifyMemberCompliance(new MemberPolicyFacts(self::MEMBER, true));

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MemberPolicyComplianceRestored::class, $events[0]);
        self::assertTrue($organization->unmetPoliciesFor(self::MEMBER)->isEmpty());
    }

    public function testVerifyMemberComplianceIsIdempotent(): void
    {
        $organization = $this->orgWithSecondMember();
        self::enforceTwoFactor($organization, true);
        $organization->verifyMemberCompliance(new MemberPolicyFacts(self::MEMBER, false));
        $organization->pullPendingEvents();

        // Already suspended for this reason, and separately: already compliant.
        $organization->verifyMemberCompliance(new MemberPolicyFacts(self::MEMBER, false));
        $organization->verifyMemberCompliance(new MemberPolicyFacts(self::OWNER, true));

        self::assertSame([], $organization->pullPendingEvents());
    }

    public function testOwnerWithoutTwoFactorIsSuspendedEvenWithNoPolicySet(): void
    {
        $organization = $this->orgWithSecondMember();

        // No policy has ever been set here: 2FA for owners is a standing rule, not this policy.
        $organization->verifyMemberCompliance(new MemberPolicyFacts(self::OWNER, false));

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MemberPolicyComplianceFailed::class, $events[0]);
        self::assertSame(self::OWNER, $events[0]->userId);
        self::assertSame([PolicyComplianceReason::TwoFactor], $events[0]->unmetPolicies->reasons);
    }

    public function testPlainMemberWithoutTwoFactorIsFineWithNoPolicySet(): void
    {
        $organization = $this->orgWithSecondMember();

        $organization->verifyMemberCompliance(new MemberPolicyFacts(self::MEMBER, false));

        self::assertSame([], $organization->pullPendingEvents());
        self::assertTrue($organization->unmetPoliciesFor(self::MEMBER)->isEmpty());
    }

    public function testDisablingTheTwoFactorPolicyDoesNotRestoreAnOwnerWithoutTwoFactor(): void
    {
        $organization = $this->orgWithSecondMember();
        $facts = [
            self::OWNER => new MemberPolicyFacts(self::OWNER, false),
            self::MEMBER => new MemberPolicyFacts(self::MEMBER, false),
        ];

        // A packagist-admin can enable the policy without holding 2FA, which is how an owner who lacks it
        // ends up suspended by the same batch.
        self::enforceTwoFactor($organization, true, $facts);
        $organization->pullPendingEvents();
        self::assertSame([PolicyComplianceReason::TwoFactor], $organization->unmetPoliciesFor(self::OWNER)->reasons);

        self::enforceTwoFactor($organization, false, $facts);

        // The plain member is restored; the owner is not, since the rule holding them was never this policy.
        $restored = array_values(array_filter(
            $organization->pullPendingEvents(),
            static fn ($event): bool => $event instanceof MemberPolicyComplianceRestored,
        ));
        self::assertCount(1, $restored);
        self::assertSame(self::MEMBER, $restored[0]->userId);

        self::assertSame([PolicyComplianceReason::TwoFactor], $organization->unmetPoliciesFor(self::OWNER)->reasons);
        self::assertTrue($organization->unmetPoliciesFor(self::MEMBER)->isEmpty());
    }

    public function testOwnerIsRestoredOnceTheyEnableTwoFactor(): void
    {
        $organization = $this->orgWithSecondMember();
        $organization->verifyMemberCompliance(new MemberPolicyFacts(self::OWNER, false));
        $organization->pullPendingEvents();

        $organization->verifyMemberCompliance(new MemberPolicyFacts(self::OWNER, true));

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MemberPolicyComplianceRestored::class, $events[0]);
        self::assertTrue($organization->unmetPoliciesFor(self::OWNER)->isEmpty());
    }

    public function testVerifyMemberComplianceIgnoresNonMembers(): void
    {
        $organization = $this->orgWithSecondMember();
        self::enforceTwoFactor($organization, true);
        $organization->pullPendingEvents();

        $organization->verifyMemberCompliance(new MemberPolicyFacts(99, false));

        self::assertSame([], $organization->pullPendingEvents());
    }

    public function testLeavingClearsTheSuspension(): void
    {
        $organization = $this->orgWithSecondMember();
        self::enforceTwoFactor($organization, true, [
            self::MEMBER => new MemberPolicyFacts(self::MEMBER, false),
        ]);
        $organization->pullPendingEvents();

        $organization->leave(self::MEMBER);

        // A former member carries no compliance state, so re-joining starts clean.
        self::assertTrue($organization->unmetPoliciesFor(self::MEMBER)->isEmpty());
    }

    public function testPoliciesAndSuspensionsAreReplayedFromTheStream(): void
    {
        $organization = Organization::reconstitute(new Ulid(), [
            ...$this->bootstrapHistory(),
            ['type' => OrganizationEventType::TwoFactorEnforcementEdited, 'payload' => ['enforced' => true]],
            ['type' => OrganizationEventType::MemberPolicyComplianceFailed, 'payload' => ['userId' => self::MEMBER, 'reasons' => ['two_factor']]],
        ]);

        self::assertTrue($organization->policies()->enforceTwoFactor);
        self::assertSame([PolicyComplianceReason::TwoFactor], $organization->unmetPoliciesFor(self::MEMBER)->reasons);

        // A restore later in the stream clears it again.
        $restored = Organization::reconstitute(new Ulid(), [
            ...$this->bootstrapHistory(),
            ['type' => OrganizationEventType::TwoFactorEnforcementEdited, 'payload' => ['enforced' => true]],
            ['type' => OrganizationEventType::MemberPolicyComplianceFailed, 'payload' => ['userId' => self::MEMBER, 'reasons' => ['two_factor']]],
            ['type' => OrganizationEventType::MemberPolicyComplianceRestored, 'payload' => ['userId' => self::MEMBER]],
        ]);

        self::assertTrue($restored->unmetPoliciesFor(self::MEMBER)->isEmpty());
    }

    public function testBothPoliciesInOneEditSuspendAMemberOnce(): void
    {
        $organization = $this->orgWithSecondMember();

        $organization->setPolicies(
            new OrganizationPolicies(true, new AllowedEmailDomains('example.org')),
            self::actor(),
            [
                self::OWNER => new MemberPolicyFacts(self::OWNER, true, emailDomain: 'example.org'),
                self::MEMBER => new MemberPolicyFacts(self::MEMBER, false, emailDomain: 'other.org'),
            ],
        );

        $events = $organization->pullPendingEvents();
        self::assertCount(3, $events);
        self::assertInstanceOf(TwoFactorEnforcementEdited::class, $events[0]);
        self::assertInstanceOf(AllowedEmailDomainsEdited::class, $events[1]);

        // One suspension naming both policies, rather than one per policy the edit touched.
        self::assertInstanceOf(MemberPolicyComplianceFailed::class, $events[2]);
        self::assertSame(self::MEMBER, $events[2]->userId);
        self::assertSame(
            [PolicyComplianceReason::TwoFactor, PolicyComplianceReason::EmailDomain],
            $events[2]->unmetPolicies->reasons,
        );
    }

    public function testARefusedPolicyLeavesTheRestOfTheSameEditUnrecorded(): void
    {
        $organization = $this->orgWithSecondMember();

        // The 2FA half would be accepted on its own; the domain the actor is not on refuses the whole edit.
        try {
            $organization->setPolicies(
                new OrganizationPolicies(true, new AllowedEmailDomains('acme.io')),
                self::actor(),
                [self::MEMBER => new MemberPolicyFacts(self::MEMBER, false)],
            );
            self::fail('Requiring a domain the actor is not on should have been refused.');
        } catch (EmailDomainMismatchException) {
        }

        self::assertSame([], $organization->pullPendingEvents());
        self::assertFalse($organization->policies()->enforceTwoFactor);
        self::assertTrue($organization->unmetPoliciesFor(self::MEMBER)->isEmpty());
    }

    public function testADomainRequirementCannotSuspendACoOwner(): void
    {
        $organization = $this->orgWithSecondOwner();

        // The acting owner is on example.org and would keep their access; the co-owner is not, and their
        // remedy would be an address on a domain they may have no way to get.
        try {
            $organization->setPolicies(
                $organization->policies()->withAllowedEmailDomains(new AllowedEmailDomains('example.org')),
                self::actor(),
                [
                    self::OWNER => new MemberPolicyFacts(self::OWNER, true, emailDomain: 'example.org'),
                    self::MEMBER => new MemberPolicyFacts(self::MEMBER, true, emailDomain: 'gmail.com'),
                ],
            );
            self::fail('Requiring a domain a co-owner is not on should have been refused.');
        } catch (EmailDomainMismatchException $e) {
            self::assertStringContainsString('Not covered: gmail.com.', $e->getMessage());
        }

        self::assertSame([], $organization->pullPendingEvents());
        self::assertTrue($organization->policies()->allowedEmailDomains->isEmpty());
    }

    public function testADomainRequirementMaySuspendAPlainMember(): void
    {
        $organization = $this->orgWithSecondMember();

        // The same set, with the off-domain member holding no ownership: suspending them is the point of
        // the policy, so only the owners team is protected.
        $organization->setPolicies(
            $organization->policies()->withAllowedEmailDomains(new AllowedEmailDomains('example.org')),
            self::actor(),
            [
                self::OWNER => new MemberPolicyFacts(self::OWNER, true, emailDomain: 'example.org'),
                self::MEMBER => new MemberPolicyFacts(self::MEMBER, true, emailDomain: 'gmail.com'),
            ],
        );

        $events = $organization->pullPendingEvents();
        self::assertCount(2, $events);
        self::assertInstanceOf(AllowedEmailDomainsEdited::class, $events[0]);
        self::assertInstanceOf(MemberPolicyComplianceFailed::class, $events[1]);
        self::assertSame(self::MEMBER, $events[1]->userId);
    }

    public function testAnActorWhoIsNotAnOwnerIsJudgedByTheOwnersInstead(): void
    {
        $organization = $this->orgWithSecondMember();

        // A packagist-admin acting on the org: they are not a member, so no domain set can suspend them and
        // their own address has no bearing on what the org may require.
        $organization->setPolicies(
            $organization->policies()->withAllowedEmailDomains(new AllowedEmailDomains('example.org')),
            new MemberPolicyFacts(99, true, emailDomain: 'packagist.com'),
            [self::OWNER => new MemberPolicyFacts(self::OWNER, true, emailDomain: 'example.org')],
        );

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(AllowedEmailDomainsEdited::class, $events[0]);
    }

    public function testOnlyTheChangedPolicyIsGuarded(): void
    {
        $organization = $this->orgWithSecondMember();
        self::enforceTwoFactor($organization, true);
        $organization->pullPendingEvents();

        // Enforcement stays on but is not re-guarded, so an actor who has since lost 2FA themselves can
        // still edit a different policy.
        $organization->setPolicies(
            $organization->policies()->withAllowedEmailDomains(new AllowedEmailDomains('example.org')),
            self::actor(hasTwoFactor: false),
            [],
        );

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(AllowedEmailDomainsEdited::class, $events[0]);
        self::assertTrue($organization->policies()->enforceTwoFactor);
    }

    /**
     * Edit two-factor enforcement on its own, the way the policy page does when nothing else changed.
     *
     * @param array<int, MemberPolicyFacts> $memberFacts
     */
    private static function enforceTwoFactor(Organization $organization, bool $enforced, array $memberFacts = [], bool $actorHasTwoFactor = true): void
    {
        $organization->setPolicies(
            $organization->policies()->withTwoFactorEnforcement($enforced),
            self::actor($actorHasTwoFactor),
            $memberFacts,
        );
    }

    /** The acting owner as the application service resolves them, on a domain the tests' policies allow. */
    private static function actor(bool $hasTwoFactor = true): MemberPolicyFacts
    {
        return new MemberPolicyFacts(self::OWNER, $hasTwoFactor, emailDomain: 'example.org');
    }

    /**
     * An org with an owner and a second plain member, at rest: the creation history is replayed rather
     * than recorded, so pullPendingEvents() only ever returns what the test itself triggered.
     */
    private function orgWithSecondMember(): Organization
    {
        return Organization::reconstitute(new Ulid(), $this->bootstrapHistory());
    }

    /** An org whose second member is an owner too, for the guards that answer for the whole owners team. */
    private function orgWithSecondOwner(): Organization
    {
        return Organization::reconstitute(new Ulid(), $this->bootstrapHistory(secondOwner: true));
    }

    /**
     * The creation sequence plus a second member who joined through an invitation.
     *
     * @return list<array{type: OrganizationEventType, payload: array<string, mixed>}>
     */
    private function bootstrapHistory(bool $secondOwner = false): array
    {
        $ownersTeamId = new Ulid();
        $allMembersTeamId = new Ulid();

        return [
            ['type' => OrganizationEventType::OrganizationCreated, 'payload' => [
                'slug' => 'acme',
                'displayName' => 'ACME Corp',
                'ownersTeamId' => $ownersTeamId->toRfc4122(),
                'allMembersTeamId' => $allMembersTeamId->toRfc4122(),
            ]],
            ['type' => OrganizationEventType::MemberJoined, 'payload' => ['userId' => self::OWNER]],
            ['type' => OrganizationEventType::TeamCreated, 'payload' => ['teamId' => $ownersTeamId->toRfc4122(), 'name' => Organization::OWNERS_TEAM_NAME, 'kind' => 'system']],
            ['type' => OrganizationEventType::TeamCreated, 'payload' => ['teamId' => $allMembersTeamId->toRfc4122(), 'name' => Organization::ALL_ORGANIZATION_MEMBERS_TEAM_NAME, 'kind' => 'system']],
            ['type' => OrganizationEventType::TeamMemberAdded, 'payload' => ['teamId' => $ownersTeamId->toRfc4122(), 'userId' => self::OWNER]],
            ['type' => OrganizationEventType::TeamMemberAdded, 'payload' => ['teamId' => $allMembersTeamId->toRfc4122(), 'userId' => self::OWNER]],
            ['type' => OrganizationEventType::MemberJoined, 'payload' => ['userId' => self::MEMBER]],
            ['type' => OrganizationEventType::TeamMemberAdded, 'payload' => ['teamId' => $allMembersTeamId->toRfc4122(), 'userId' => self::MEMBER]],
            ...($secondOwner ? [['type' => OrganizationEventType::TeamMemberAdded, 'payload' => ['teamId' => $ownersTeamId->toRfc4122(), 'userId' => self::MEMBER]]] : []),
        ];
    }
}
