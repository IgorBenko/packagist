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
use App\Entity\OrganizationMemberRepository;
use App\Entity\User;
use App\Organization\Domain\Organization;
use App\Organization\Domain\PolicyComplianceReason;
use App\Organization\EventStore\Actor;
use App\Organization\EventStore\ConcurrencyException;
use App\Organization\EventStore\EventStore;

/**
 * Verifies a member against the org's policies on the requests where they act for it, and records the
 * suspension or restoration that verdict produces. There is no background sweep: a member who never acts
 * is never re-checked, which is harmless because they can do nothing without a request.
 *
 * Called from {@see \App\Security\Voter\OrganizationVoter}, so it runs before any guarded action.
 */
final class OrganizationPolicyEnforcer
{
    /** @var array<string, ?PolicyComplianceReason> "orgId:userId" => verdict, memoized for the request */
    private array $verified = [];

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly OrganizationMemberRepository $members,
        private readonly MemberPolicyFactsResolver $facts,
    ) {
    }

    /**
     * The policy the member fails, or null when they comply (or are not a member at all).
     *
     * A page render consults the voter many times, so the verdict is computed once per org and user: only
     * a genuine change of state may write to the event stream.
     */
    public function enforce(OrganizationReadModel $organization, User $user): ?PolicyComplianceReason
    {
        $key = $organization->id->toRfc4122().':'.$user->getId();

        // array_key_exists, not ??=: a compliant member's verdict is null and must still count as cached.
        if (!\array_key_exists($key, $this->verified)) {
            $this->verified[$key] = $this->verify($organization, $user);
        }

        return $this->verified[$key];
    }

    private function verify(OrganizationReadModel $organization, User $user): ?PolicyComplianceReason
    {
        // Policies apply to members. Non-members are refused by the voter on membership grounds instead.
        if ($this->members->findOneByOrgAndUser($organization->id, $user->getId()) === null) {
            return null;
        }

        $aggregate = Organization::reconstitute(
            $organization->id,
            $this->eventStore->loadHistory($organization->id),
        );

        $aggregate->verifyMemberCompliance($this->facts->forUser($user));

        try {
            // A no-op when the verdict is unchanged: the aggregate recorded nothing and append returns early.
            $this->eventStore->append($aggregate, Actor::automation(), null);
        } catch (ConcurrencyException) {
            // Another request resolved this org's stream first. The verdict below is still the right answer
            // for this request; persisting the transition can wait for the next one.
        }

        return $aggregate->suspensionReasonFor($user->getId());
    }
}
