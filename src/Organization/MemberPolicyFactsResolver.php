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

use App\Entity\User;
use App\Entity\UserRepository;
use App\Organization\Domain\MemberPolicyFacts;

/**
 * Gathers the facts an organization's policies are evaluated against. The one place that knows where each
 * fact comes from: locally off the user record today, and from the GitHub API once the org-membership
 * policy lands.
 */
final readonly class MemberPolicyFactsResolver
{
    public function __construct(
        private UserRepository $userRepo,
    ) {
    }

    public function forUser(User $user): MemberPolicyFacts
    {
        return new MemberPolicyFacts($user->getId(), $user->isTotpAuthenticationEnabled());
    }

    /**
     * Facts for a whole membership, keyed by user id. A member whose user record has gone is absent from
     * the result rather than guessed at.
     *
     * @param list<int> $userIds
     *
     * @return array<int, MemberPolicyFacts>
     */
    public function forUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $facts = [];
        foreach ($this->userRepo->findBy(['id' => $userIds]) as $user) {
            $facts[$user->getId()] = $this->forUser($user);
        }

        return $facts;
    }
}
