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

use App\Organization\Domain\Event\MemberPolicyComplianceFailed;
use App\Organization\Domain\Event\MemberPolicyComplianceRestored;
use App\Organization\Domain\Event\TwoFactorEnforcementEdited;
use App\Organization\Domain\Exception\TwoFactorRequiredException;
use App\Organization\Domain\MemberPolicyFacts;
use App\Organization\Domain\Organization;
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

        $organization->setTwoFactorEnforcement(true, true, [
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

        $organization->setTwoFactorEnforcement(true, true, $facts);
        $organization->pullPendingEvents();

        $organization->setTwoFactorEnforcement(false, true, $facts);

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

        $organization->setTwoFactorEnforcement(false, true, []);

        self::assertSame([], $organization->pullPendingEvents());
    }

    public function testEnablingRequiresTheActorToHaveTwoFactor(): void
    {
        $organization = $this->orgWithSecondMember();

        // An owner cannot impose a policy that would immediately lock them out.
        $this->expectException(TwoFactorRequiredException::class);
        $organization->setTwoFactorEnforcement(true, false, []);
    }

    public function testDisablingDoesNotRequireTheActorToHaveTwoFactor(): void
    {
        $organization = $this->orgWithSecondMember();
        $organization->setTwoFactorEnforcement(true, true, []);
        $organization->pullPendingEvents();

        $organization->setTwoFactorEnforcement(false, false, []);

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TwoFactorEnforcementEdited::class, $events[0]);
    }

    public function testMemberWithoutFactsIsLeftAlone(): void
    {
        $organization = $this->orgWithSecondMember();

        // No facts at all, e.g. the user records have gone: the policy still changes, nobody is judged.
        $organization->setTwoFactorEnforcement(true, true, []);

        $events = $organization->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertTrue($organization->unmetPoliciesFor(self::MEMBER)->isEmpty());
    }

    public function testVerifyMemberComplianceSuspendsAndRestores(): void
    {
        $organization = $this->orgWithSecondMember();
        $organization->setTwoFactorEnforcement(true, true, []);
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
        $organization->setTwoFactorEnforcement(true, true, []);
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
        $organization->setTwoFactorEnforcement(true, true, $facts);
        $organization->pullPendingEvents();
        self::assertSame([PolicyComplianceReason::TwoFactor], $organization->unmetPoliciesFor(self::OWNER)->reasons);

        $organization->setTwoFactorEnforcement(false, true, $facts);

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
        $organization->setTwoFactorEnforcement(true, true, []);
        $organization->pullPendingEvents();

        $organization->verifyMemberCompliance(new MemberPolicyFacts(99, false));

        self::assertSame([], $organization->pullPendingEvents());
    }

    public function testLeavingClearsTheSuspension(): void
    {
        $organization = $this->orgWithSecondMember();
        $organization->setTwoFactorEnforcement(true, true, [
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
            ['type' => OrganizationEventType::MemberPolicyComplianceFailed, 'payload' => ['userId' => self::MEMBER, 'reason' => 'two_factor']],
        ]);

        self::assertTrue($organization->policies()->enforceTwoFactor);
        self::assertSame([PolicyComplianceReason::TwoFactor], $organization->unmetPoliciesFor(self::MEMBER)->reasons);

        // A restore later in the stream clears it again.
        $restored = Organization::reconstitute(new Ulid(), [
            ...$this->bootstrapHistory(),
            ['type' => OrganizationEventType::TwoFactorEnforcementEdited, 'payload' => ['enforced' => true]],
            ['type' => OrganizationEventType::MemberPolicyComplianceFailed, 'payload' => ['userId' => self::MEMBER, 'reason' => 'two_factor']],
            ['type' => OrganizationEventType::MemberPolicyComplianceRestored, 'payload' => ['userId' => self::MEMBER]],
        ]);

        self::assertTrue($restored->unmetPoliciesFor(self::MEMBER)->isEmpty());
    }

    /**
     * An org with an owner and a second plain member, at rest: the creation history is replayed rather
     * than recorded, so pullPendingEvents() only ever returns what the test itself triggered.
     */
    private function orgWithSecondMember(): Organization
    {
        return Organization::reconstitute(new Ulid(), $this->bootstrapHistory());
    }

    /**
     * The creation sequence plus a second member who joined through an invitation.
     *
     * @return list<array{type: OrganizationEventType, payload: array<string, mixed>}>
     */
    private function bootstrapHistory(): array
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
        ];
    }
}
