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

namespace App\Organization\Domain\Event;

use App\Organization\Domain\AllowedEmailDomains;
use App\Organization\EventStore\DomainEvent;
use App\Organization\EventStore\OrganizationEventType;
use Symfony\Component\Uid\Ulid;

/**
 * The organization accepts member account emails only on these domains, or on any domain when the set is
 * empty. Members whose address is elsewhere are suspended; clearing restores the ones that failed nothing
 * else.
 *
 * Carries the resulting set rather than a change, so the log renders without replaying what came before.
 */
final readonly class AllowedEmailDomainsEdited implements DomainEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::AllowedEmailDomainsEdited;

    public function __construct(
        public Ulid $organizationId,
        public AllowedEmailDomains $allowedEmailDomains,
    ) {
    }

    public function aggregateId(): Ulid
    {
        return $this->organizationId;
    }

    public function eventType(): OrganizationEventType
    {
        return self::TYPE;
    }

    public function toPayload(): array
    {
        return [
            'allowedEmailDomains' => $this->allowedEmailDomains->toValues(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(Ulid $organizationId, array $payload): self
    {
        return new self(
            $organizationId,
            AllowedEmailDomains::fromValues(array_values(array_map(strval(...), (array) ($payload['allowedEmailDomains'] ?? [])))),
        );
    }
}
