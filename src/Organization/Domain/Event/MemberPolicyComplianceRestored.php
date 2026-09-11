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

use App\Organization\EventStore\AutomationEvent;
use App\Organization\EventStore\OrganizationEventType;
use Symfony\Component\Uid\Ulid;

/**
 * A suspended member satisfies every active policy again, so their access is restored. Recorded either
 * when the failing policy is disabled or by the inline verification on the member's own request, both
 * automation-triggered with no acting user.
 */
final readonly class MemberPolicyComplianceRestored implements AutomationEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::MemberPolicyComplianceRestored;

    public function __construct(
        public Ulid $organizationId,
        public int $userId,
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
        );
    }
}
