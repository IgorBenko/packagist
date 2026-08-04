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
 * One unmet policy and what the person has to do about it, built by
 * {@see OrganizationPolicies::remediationsFor()} because the text can name the org's configured values.
 *
 * The reason travels with the text so a renderer can key on it, e.g. to link to 2FA setup.
 */
final readonly class PolicyRemediation
{
    public function __construct(
        public PolicyComplianceReason $reason,
        public string $text,
    ) {
    }
}
