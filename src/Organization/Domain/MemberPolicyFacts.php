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

namespace App\Organization\Domain;

/**
 * Everything about one member that the org's policies are evaluated against. The aggregate is pure, so
 * the application service resolves these facts (from the user record today, from the GitHub API once the
 * org-membership policy lands) and passes them in on the command.
 */
final readonly class MemberPolicyFacts
{
    public function __construct(
        public int $userId,
        public bool $hasTwoFactor,
        /** Whether they are in the `owners` team, which carries requirements of its own. */
        public bool $isOwner = false,
        /** Lowercased domain of their account email. Null is treated as unmet, never as a pass. */
        public ?string $emailDomain = null,
    ) {
    }

    /**
     * Ownership comes from a different source than the rest: the org's own state rather than the user
     * record, so whoever knows it fills it in. {@see Organization::verifyMemberCompliance()} overrides it
     * from the aggregate, which is authoritative.
     */
    public function withOwnership(bool $isOwner): self
    {
        return new self($this->userId, $this->hasTwoFactor, $isOwner, $this->emailDomain);
    }
}
