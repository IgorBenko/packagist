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
use App\Entity\OrganizationPolicyRepository;
use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\User;
use App\Organization\Domain\Organization;
use App\Organization\Domain\UnmetPolicies;
use App\Organization\EventStore\Actor;
use App\Organization\EventStore\ConcurrencyException;
use App\Organization\EventStore\EventStore;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Verifies a member against the org's requirements on the requests where they act for it, and records the
 * suspension or restoration that verdict produces. There is no background sweep: a member who never acts
 * is never re-checked, which is harmless because they can do nothing without a request.
 *
 * Called from {@see \App\Security\Voter\OrganizationVoter}, so it runs on every page view of an org: the
 * verdict comes from the read model and the event stream is only touched when it changed.
 */
final class OrganizationPolicyEnforcer implements ResetInterface
{
    /** @var array<string, UnmetPolicies> "orgId:userId" => verdict, memoized for the request */
    private array $verified = [];

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly OrganizationMemberRepository $organizationMemberRepo,
        private readonly OrganizationTeamMemberRepository $organizationTeamMemberRepo,
        private readonly OrganizationPolicyRepository $organizationPolicyRepo,
        private readonly MemberPolicyFactsResolver $facts,
    ) {
    }

    /**
     * Every policy the member fails, empty when they comply or are not a member at all. Memoized because a
     * page render consults the voter many times and only a genuine change may write to the stream.
     */
    public function enforce(OrganizationReadModel $organization, User $user): UnmetPolicies
    {
        $key = $organization->id->toRfc4122().':'.$user->getId();

        return $this->verified[$key] ??= $this->verify($organization, $user);
    }

    private function verify(OrganizationReadModel $organization, User $user): UnmetPolicies
    {
        // Policies apply to members. Non-members are refused by the voter on membership grounds instead.
        $member = $this->organizationMemberRepo->findOneByOrgAndUser($organization->id, $user->getId());
        if ($member === null) {
            return UnmetPolicies::none();
        }

        // Read model only: three indexed lookups, where replaying the stream would load the org's whole
        // history for a page view.
        $unmet = $this->organizationPolicyRepo->policiesFor($organization->id)->unmetBy(
            $this->facts->forUser($user)->withOwnership(
                $this->organizationTeamMemberRepo->isOwner($organization->ownersTeamId, $user->getId()),
            ),
        );

        if ($unmet->equals($member->suspendedPolicies)) {
            return $unmet;
        }

        // The aggregate's view of ownership is authoritative, but it can only speak for a member it knows:
        // a stream that cannot confirm the membership must not hand back access the read model refused.
        $recorded = $this->recordTransition($organization, $user);

        return $recorded->isEmpty() ? $unmet : $recorded;
    }

    /**
     * Only this path pays for the aggregate, which re-derives the verdict from its own view of ownership and
     * decides what gets recorded. Empty when it sees nothing to fix, or does not know the member.
     */
    private function recordTransition(OrganizationReadModel $organization, User $user): UnmetPolicies
    {
        $aggregate = Organization::reconstitute(
            $organization->id,
            $this->eventStore->loadHistory($organization->id),
        );

        $aggregate->verifyMemberCompliance($this->facts->forUser($user));

        try {
            $this->eventStore->append($aggregate, Actor::automation(), null);
        } catch (ConcurrencyException) {
            // Another request resolved this org's stream first. The verdict below is still the right answer
            // for this request; persisting the transition can wait for the next one.
        }

        return $aggregate->unmetPoliciesFor($user->getId());
    }

    /**
     * The memo holds for one request only. Under php-fpm the whole container goes with it, but a worker
     * runtime keeps this instance alive, and the next request must not inherit a verdict decided from
     * facts that have since changed.
     */
    public function reset(): void
    {
        $this->verified = [];
    }
}
