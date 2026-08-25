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

namespace App\Tests\Organization;

use App\Organization\Domain\Event\MemberPolicyComplianceFailed;
use App\Organization\Domain\PolicyComplianceReason;
use App\Organization\Domain\UnmetPolicies;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

class UnmetPoliciesTest extends TestCase
{
    public function testNoneIsEmptyAndNamesNoPolicy(): void
    {
        $none = UnmetPolicies::none();

        self::assertTrue($none->isEmpty());
        self::assertSame([], $none->reasons);
        self::assertSame([], $none->toValues());
        self::assertNull($none->sole());
    }

    public function testASinglePolicyIsItsOwnSoleReason(): void
    {
        $unmet = new UnmetPolicies(PolicyComplianceReason::TwoFactor);

        self::assertFalse($unmet->isEmpty());
        self::assertSame(PolicyComplianceReason::TwoFactor, $unmet->sole());
        self::assertSame(['two_factor'], $unmet->toValues());
    }

    public function testTheSamePolicyTwiceIsOnePolicy(): void
    {
        $unmet = new UnmetPolicies(PolicyComplianceReason::TwoFactor, PolicyComplianceReason::TwoFactor);

        self::assertSame([PolicyComplianceReason::TwoFactor], $unmet->reasons);
    }

    public function testEqualsComparesTheSetSoAnUnchangedVerdictIsRecognised(): void
    {
        $unmet = new UnmetPolicies(PolicyComplianceReason::TwoFactor);

        self::assertTrue($unmet->equals(new UnmetPolicies(PolicyComplianceReason::TwoFactor)));
        self::assertFalse($unmet->equals(UnmetPolicies::none()));
        self::assertTrue(UnmetPolicies::none()->equals(UnmetPolicies::none()));
    }

    public function testStoredValuesRoundTrip(): void
    {
        self::assertSame(
            [PolicyComplianceReason::TwoFactor],
            UnmetPolicies::fromValues(['two_factor'])->reasons,
        );
        self::assertTrue(UnmetPolicies::fromValues([])->isEmpty());
    }

    public function testStoredValuesSurviveARetiredPolicy(): void
    {
        // A member row or an event payload written while a since-retired policy existed: the rest of the set
        // still has to load, or reading that member would fail outright.
        self::assertSame(
            [PolicyComplianceReason::TwoFactor],
            UnmetPolicies::fromValues(['two_factor', 'github_org'])->reasons,
        );
        self::assertTrue(UnmetPolicies::fromValues(['github_org'])->isEmpty());
    }

    /**
     * The payload written while a member could only fail one policy at a time. It exists in dev and staging
     * streams written mid-branch, so both shapes have to replay into the same event until those are gone.
     */
    public function testComplianceFailedReplaysTheSingleReasonPayload(): void
    {
        $organizationId = new Ulid();

        $legacy = MemberPolicyComplianceFailed::fromPayload($organizationId, ['userId' => 7, 'reason' => 'two_factor']);
        $current = MemberPolicyComplianceFailed::fromPayload($organizationId, ['userId' => 7, 'reasons' => ['two_factor']]);

        self::assertSame([PolicyComplianceReason::TwoFactor], $legacy->unmetPolicies->reasons);
        self::assertTrue($legacy->unmetPolicies->equals($current->unmetPolicies));
        self::assertSame(7, $legacy->userId);
    }

    public function testComplianceFailedPayloadRoundTrips(): void
    {
        $event = new MemberPolicyComplianceFailed(new Ulid(), 7, new UnmetPolicies(PolicyComplianceReason::TwoFactor));

        self::assertSame(['userId' => 7, 'reasons' => ['two_factor']], $event->toPayload());
        self::assertTrue(
            MemberPolicyComplianceFailed::fromPayload($event->organizationId, $event->toPayload())
                ->unmetPolicies->equals($event->unmetPolicies),
        );
    }
}
