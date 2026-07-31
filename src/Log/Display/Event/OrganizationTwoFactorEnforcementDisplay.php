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

namespace App\Log\Display\Event;

use App\Log\AuditLogEventType;
use App\Log\Display\AbstractLogDisplay;
use App\Log\Display\ActorDisplay;
use App\Log\Display\OrganizationDisplay;

/**
 * The org started or stopped requiring two-factor authentication from its members. Both directions carry
 * the same attributes, so one display covers them; the concrete type drives the wording via its own
 * template and translation key.
 */
readonly class OrganizationTwoFactorEnforcementDisplay extends AbstractLogDisplay
{
    public function __construct(
        private AuditLogEventType $type,
        \DateTimeImmutable $datetime,
        public OrganizationDisplay $organization,
        ActorDisplay $actor,
        ?string $ip,
    ) {
        parent::__construct($datetime, $actor, $ip);
    }

    public function getType(): AuditLogEventType
    {
        return $this->type;
    }

    public function getTemplateName(): string
    {
        return 'log/display/'.$this->type->value.'.html.twig';
    }
}
