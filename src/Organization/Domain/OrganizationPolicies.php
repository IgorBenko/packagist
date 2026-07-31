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
 * The policies an organization has active, and the single place that decides whether someone satisfies
 * them. Both paths to that question go through here: the aggregate holds an instance and uses it for the
 * suspension decisions, and {@see \App\Entity\OrganizationPolicyRepository::policiesFor()} rebuilds one
 * from the read model for the display-only checks (an invitee's compliance checklist).
 *
 * Every policy added later gets a field and a branch in {@see unmetBy()}, so a cleared policy can never
 * leave someone blocked for a requirement that no longer applies.
 */
final readonly class OrganizationPolicies
{
    public function __construct(
        public bool $enforceTwoFactor = false,
    ) {
    }

    /**
     * The first requirement these facts fail, or null when they satisfy all of them.
     *
     * Deliberately more than the fields above: 2FA for owners is a standing platform rule, not one of this
     * org's policies, so it holds whether or not `enforce_2fa` is on and turning that off does not restore an
     * owner who dropped 2FA.
     */
    public function unmetBy(MemberPolicyFacts $facts): ?PolicyComplianceReason
    {
        if (($this->enforceTwoFactor || $facts->isOwner) && !$facts->hasTwoFactor) {
            return PolicyComplianceReason::TwoFactor;
        }

        return null;
    }

    /**
     * Every field has to be threaded through here: a later policy that is not would be silently reset
     * whenever two-factor enforcement changes.
     */
    public function withTwoFactorEnforcement(bool $enforced): self
    {
        return new self(enforceTwoFactor: $enforced);
    }
}
