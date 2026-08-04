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
        public AllowedEmailDomains $allowedEmailDomains = new AllowedEmailDomains(),
    ) {
    }

    /**
     * Every requirement these facts fail, so the caller can report the full set rather than making someone
     * fix one to discover the next.
     *
     * Deliberately more than the fields above: 2FA for owners is a standing platform rule, not one of this
     * org's policies, so it holds whether or not `enforce_2fa` is on and turning that off does not restore an
     * owner who dropped 2FA.
     */
    public function unmetBy(MemberPolicyFacts $facts): UnmetPolicies
    {
        $unmet = [];

        if (($this->enforceTwoFactor || $facts->isOwner) && !$facts->hasTwoFactor) {
            $unmet[] = PolicyComplianceReason::TwoFactor;
        }

        if (!$this->allowedEmailDomains->isEmpty() && !$this->allowedEmailDomains->matches($facts->emailDomain)) {
            $unmet[] = PolicyComplianceReason::EmailDomain;
        }

        return new UnmetPolicies(...$unmet);
    }

    /**
     * What the person has to do about each unmet policy. Lives here rather than on the reason because the
     * wording names this org's configured values.
     *
     * @return list<PolicyRemediation>
     */
    public function remediationsFor(UnmetPolicies $unmet): array
    {
        $remediations = [];
        foreach ($unmet->reasons as $reason) {
            $remediations[] = new PolicyRemediation($reason, match ($reason) {
                PolicyComplianceReason::TwoFactor => 'Enable two-factor authentication on your account.',
                PolicyComplianceReason::EmailDomain => $this->emailDomainRemediation(),
            });
        }

        return $remediations;
    }

    /**
     * Every field has to be threaded through here: a later policy that is not would be silently reset
     * whenever two-factor enforcement changes.
     */
    public function withTwoFactorEnforcement(bool $enforced): self
    {
        return new self(enforceTwoFactor: $enforced, allowedEmailDomains: $this->allowedEmailDomains);
    }

    public function withAllowedEmailDomains(AllowedEmailDomains $domains): self
    {
        return new self(enforceTwoFactor: $this->enforceTwoFactor, allowedEmailDomains: $domains);
    }

    private function emailDomainRemediation(): string
    {
        // No domains configured means a member is still suspended for a policy since cleared, which their
        // next request repairs.
        if ($this->allowedEmailDomains->isEmpty()) {
            return 'Use an account email address on a domain this organization allows.';
        }

        if (\count($this->allowedEmailDomains->domains) === 1) {
            return sprintf('Use an account email address on %s.', $this->allowedEmailDomains->domains[0]);
        }

        return sprintf('Use an account email address on one of: %s.', implode(', ', $this->allowedEmailDomains->domains));
    }
}
