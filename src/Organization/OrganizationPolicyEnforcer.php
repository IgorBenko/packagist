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
use App\Organization\Domain\PolicyRemediation;
use App\Organization\Domain\UnmetPolicies;
use App\Organization\EventStore\Actor;
use App\Organization\EventStore\ConcurrencyException;
use App\Organization\EventStore\EventStore;
use Predis\Client;
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
    /** More than any honest run of policy changes; what it bounds is a member flipping their own facts. */
    private const int MAX_TRANSITIONS = 10;

    private const int TRANSITION_WINDOW = 3600; // in seconds

    /** @var array<string, UnmetPolicies> "orgId:userId" => verdict, memoized for the request */
    private array $verified = [];

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly OrganizationMemberRepository $organizationMemberRepo,
        private readonly OrganizationTeamMemberRepository $organizationTeamMemberRepo,
        private readonly OrganizationPolicyRepository $organizationPolicyRepo,
        private readonly MemberPolicyFactsResolver $facts,
        private readonly Client $redisCache,
    ) {
    }

    /**
     * Every policy the member fails, empty when they comply or are not a member at all. Memoized because a
     * page render consults the voter many times and only a genuine change may write to the stream.
     *
     * @throws ConcurrencyException another request recorded this member's transition first, see
     *                              {@see recordTransition()} for why that ends the request
     */
    public function enforce(OrganizationReadModel $organization, User $user): UnmetPolicies
    {
        $key = $organization->id->toRfc4122().':'.$user->getId();

        return $this->verified[$key] ??= $this->verify($organization, $user);
    }

    /**
     * What the member has to do about each policy they fail, empty when they comply. Off the memoized
     * verdict, so explaining a denial costs a policy lookup and no second evaluation.
     *
     * @return list<PolicyRemediation>
     */
    public function remediationsFor(OrganizationReadModel $organization, User $user): array
    {
        $unmet = $this->enforce($organization, $user);
        if ($unmet->isEmpty()) {
            return [];
        }

        return $this->organizationPolicyRepo->policiesFor($organization->id)->remediationsFor($unmet);
    }

    private function verify(OrganizationReadModel $organization, User $user): UnmetPolicies
    {
        // Read model only: indexed lookups, where replaying the stream would load the org's whole history
        // for a page view.
        $member = $this->organizationMemberRepo->findOneByOrgAndUser($organization->id, $user->getId());
        $isOwner = $this->organizationTeamMemberRepo->isOwner($organization->ownersTeamId, $user->getId());

        // Policies apply to members, and the voter refuses a non-member on membership grounds. Ownership is
        // the exception: it is decided from a different table, written in the same transaction, so an owner
        // the membership does not know means a projection fell behind or rows arrived from outside the
        // stream. The standing 2FA rule for owners has to answer that case rather than wave them through,
        // since the voter grants management off the same ownership row.
        if ($member === null && !$isOwner) {
            return UnmetPolicies::none();
        }

        $unmet = $this->organizationPolicyRepo->policiesFor($organization->id)->unmetBy(
            $this->facts->forUser($user)->withOwnership($isOwner),
        );

        // No row to compare against or correct, and the aggregate cannot speak for a membership it does not
        // know either, so the verdict simply stands for this request.
        if ($member === null) {
            return $unmet;
        }

        if ($unmet->equals($member->suspendedPolicies)) {
            return $unmet;
        }

        // A flip writes to the org's stream and audit log from a plain page view, so a member turning their
        // own second factor off and on can produce a pair of records per cycle. Past the cap the verdict
        // below still governs access; only the bookkeeping waits, and once the window passes it records
        // whichever state the member actually settled on.
        if (!$this->mayRecordTransition($organization, $user)) {
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
     *
     * A lost race is deliberately not caught here. The failing flush closes the EntityManager, so the append
     * has to reset it, which detaches everything this request holds, including the security token's user:
     * carrying on would fail later, somewhere unrelated, on the first persist() that touches one of them.
     * Failing here instead costs the loser its request, and its retry finds the winner's transition already
     * recorded and writes nothing.
     *
     * @throws ConcurrencyException another request recorded this member's transition first
     */
    private function recordTransition(OrganizationReadModel $organization, User $user): UnmetPolicies
    {
        $aggregate = Organization::reconstitute(
            $organization->id,
            $this->eventStore->loadHistory($organization->id),
        );

        $aggregate->verifyMemberCompliance($this->facts->forUser($user));
        $this->eventStore->append($aggregate, Actor::automation(), null);

        return $aggregate->unmetPoliciesFor($user->getId());
    }

    /**
     * Bounded per member per org, in the shape {@see \App\Security\TwoFactorAuthRateLimiter} uses. The
     * window starts with the first transition rather than sliding, so a member who reaches the cap honestly
     * is recorded again once it passes instead of staying stale for as long as they keep browsing.
     */
    private function mayRecordTransition(OrganizationReadModel $organization, User $user): bool
    {
        $key = 'org-policy-transitions:'.$organization->id->toRfc4122().':'.$user->getId();

        $count = (int) $this->redisCache->incr($key);
        if ($count === 1) {
            $this->redisCache->expire($key, self::TRANSITION_WINDOW);
        }

        return $count <= self::MAX_TRANSITIONS;
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
