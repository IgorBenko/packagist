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

use App\Organization\Domain\PolicyComplianceReason;
use App\Organization\EventStore\DomainEvent;
use App\Organization\EventStore\OrganizationEventType;
use Symfony\Component\Uid\Ulid;

/**
 * A member stopped satisfying an active organization policy, so their ability to act for the org is
 * suspended until {@see MemberPolicyComplianceRestored}. Their membership and their teams are untouched.
 *
 * Recorded either when a policy is enabled (every member is evaluated at once, since the checks are
 * local) or by the inline verification on a member's own request. Both are automation-triggered: the
 * event carries no acting user.
 */
final readonly class MemberPolicyComplianceFailed implements DomainEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::MemberPolicyComplianceFailed;

    public function __construct(
        public Ulid $organizationId,
        public int $userId,
        public PolicyComplianceReason $reason,
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
            'userId' => $this->userId,
            'reason' => $this->reason->value,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(Ulid $organizationId, array $payload): self
    {
        return new self(
            $organizationId,
            (int) $payload['userId'],
            PolicyComplianceReason::from((string) $payload['reason']),
        );
    }
}
