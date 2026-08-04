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

namespace App\Form\Model;

class OrganizationPolicyRequest
{
    public bool $enforceTwoFactor = false;

    /**
     * As typed: comma or whitespace separated. {@see \App\Organization\Domain\AllowedEmailDomains} does the
     * validating, so an invalid entry comes back as a form error rather than a constraint duplicated here.
     */
    public string $allowedEmailDomains = '';
}
