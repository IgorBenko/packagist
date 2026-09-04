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

use App\Organization\EventStore\DomainEvent;
use App\Organization\EventStore\OrganizationEventType;
use Symfony\Component\Uid\Ulid;

/**
 * The organization starts or stops requiring two-factor authentication from every member. Enabling it
 * suspends the members who do not have 2FA, recorded as {@see MemberPolicyComplianceFailed} events in
 * the same batch; disabling it restores them.
 *
 * The payload is flat resulting state rather than a from/to diff: there is no previous field value to
 * render, only the new setting.
 */
final readonly class TwoFactorEnforcementEdited implements DomainEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::TwoFactorEnforcementEdited;

    public function __construct(
        public Ulid $organizationId,
        public bool $enforced,
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
            'enforced' => $this->enforced,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(Ulid $organizationId, array $payload): self
    {
        return new self(
            $organizationId,
            (bool) $payload['enforced'],
        );
    }
}
