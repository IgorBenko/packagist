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
 * The org changed which email-address domains it accepts. Both directions carry the same attributes, so one
 * display covers them and the type drives the wording; the cleared direction has no domains to list.
 */
readonly class OrganizationAllowedEmailDomainsDisplay extends AbstractLogDisplay
{
    /**
     * @param list<string> $domains
     */
    public function __construct(
        private AuditLogEventType $type,
        \DateTimeImmutable $datetime,
        public OrganizationDisplay $organization,
        public array $domains,
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
