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
 * A member's access was suspended for failing an org policy, or restored once they satisfied it again.
 * Which policy is deliberately not part of the record, so neither direction renders one.
 */
readonly class OrganizationMemberComplianceDisplay extends AbstractLogDisplay
{
    public function __construct(
        private AuditLogEventType $type,
        \DateTimeImmutable $datetime,
        public OrganizationDisplay $organization,
        public ActorDisplay $member,
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
