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
    ) {
    }
}
