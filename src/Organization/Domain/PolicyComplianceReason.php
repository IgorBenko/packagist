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
 * Which active organization policy a member fails. Recorded on
 * {@see Event\MemberPolicyComplianceFailed} and stored on the member read model so a cleared policy
 * restores only the members suspended for it.
 *
 * What to do about it lives on {@see OrganizationPolicies::remediationsFor()}, which knows the org's
 * configured values.
 */
enum PolicyComplianceReason: string
{
    case TwoFactor = 'two_factor';
    case EmailDomain = 'email_domain';

    /**
     * The policy named in the third person, for lines written about someone else (the audit log, the
     * members list) where a remediation addressed to them would not fit.
     */
    public function label(): string
    {
        return match ($this) {
            self::TwoFactor => 'two-factor authentication',
            self::EmailDomain => 'email address domain',
        };
    }
}
