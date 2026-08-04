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

namespace App\Audit\Display;

use App\Audit\AuditRecordType;
use App\Organization\Domain\UnmetPolicies;

/**
 * A member's access was suspended for failing the org's policies, or restored once they satisfied them again.
 * $policies is empty on the public transparency log, where the line stays generic, and for a restoration,
 * which has nothing left to name.
 */
readonly class OrganizationMemberComplianceDisplay extends AbstractAuditLogDisplay
{
    public function __construct(
        private AuditRecordType $type,
        \DateTimeImmutable $datetime,
        public OrganizationDisplay $organization,
        public ActorDisplay $member,
        public UnmetPolicies $policies,
        ActorDisplay $actor,
        ?string $ip,
    ) {
        parent::__construct($datetime, $actor, $ip);
    }

    public function getType(): AuditRecordType
    {
        return $this->type;
    }

    public function getTemplateName(): string
    {
        return 'audit_log/display/'.$this->type->value.'.html.twig';
    }
}
