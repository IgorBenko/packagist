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

use App\Organization\Domain\UnmetPolicies;
use App\Organization\EventStore\DomainEvent;
use App\Organization\EventStore\OrganizationEventType;
use Symfony\Component\Uid\Ulid;

/**
 * A member stopped satisfying the active organization policies, so their ability to act for the org is
 * suspended until {@see MemberPolicyComplianceRestored}. Their membership and their teams are untouched.
 *
 * Carries every policy they fail, so falling behind on a second one produces a new event with both.
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
        public UnmetPolicies $unmetPolicies,
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
            'reasons' => $this->unmetPolicies->toValues(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(Ulid $organizationId, array $payload): self
    {
        // A single `reason` is the shape this event had before it carried a set. Only dev and staging
        // streams written mid-branch have it, never production, so this fallback can go once those are gone.
        $reasons = $payload['reasons'] ?? (isset($payload['reason']) ? [$payload['reason']] : []);

        return new self(
            $organizationId,
            (int) $payload['userId'],
            UnmetPolicies::fromValues(array_values(array_map(strval(...), (array) $reasons))),
        );
    }
}
