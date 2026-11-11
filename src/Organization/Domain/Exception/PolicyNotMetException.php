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
 * An org policy required to join or to become an owner is not satisfied. On invitation acceptance the
 * invitation stays pending until the invitee complies; on promotion the target stays a plain member.
 */
final class PolicyNotMetException extends OrganizationException
{
}
