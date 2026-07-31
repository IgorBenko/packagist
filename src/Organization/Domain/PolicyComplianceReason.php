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
 */
enum PolicyComplianceReason: string
{
    case TwoFactor = 'two_factor';

    /**
     * What the member has to do to comply. Shown to a suspended member and to an invitee who cannot
     * accept yet, so both read from one place.
     */
    public function remediation(): string
    {
        return match ($this) {
            self::TwoFactor => 'Enable two-factor authentication on your account.',
        };
    }
}
