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

namespace App\Organization\Domain\Exception;

/**
 * The owner setting an email-domain policy is not on one of the submitted domains, which would suspend them
 * the moment they saved. Same guard as 2FA enforcement: a policy may not lock its author out.
 */
final class EmailDomainMismatchException extends OrganizationException
{
}
