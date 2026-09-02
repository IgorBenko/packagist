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

namespace App\Organization\EventStore;

/**
 * Marker for events nobody triggered: they fall out of evaluating the org's own rules.
 * {@see EventStore::appendAll()} stamps them as automation with no IP, whatever request they arrive on.
 */
interface AutomationEvent extends DomainEvent
{
}
