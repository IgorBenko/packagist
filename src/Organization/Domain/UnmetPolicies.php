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

namespace App\Organization\Domain;

/**
 * The policies someone fails, as a set, so all of them can be reported at once.
 *
 * Ordered by policy declaration and deduplicated on construction, which is what lets {@see equals()} decide
 * whether a verdict changed rather than recording a transition that did not happen.
 */
final readonly class UnmetPolicies
{
    /** @var list<PolicyComplianceReason> */
    public array $reasons;

    public function __construct(PolicyComplianceReason ...$reasons)
    {
        $canonical = [];
        foreach (PolicyComplianceReason::cases() as $case) {
            if (\in_array($case, $reasons, true)) {
                $canonical[] = $case;
            }
        }

        $this->reasons = $canonical;
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * A value no longer recognised is skipped rather than refused. These sets come out of a JSON column and
     * out of immutable event payloads, so retiring a policy must not turn every read of an affected member
     * row, or every replay of that org's stream, into a ValueError.
     *
     * @param list<string> $values
     */
    public static function fromValues(array $values): self
    {
        $reasons = [];
        foreach ($values as $value) {
            $reason = PolicyComplianceReason::tryFrom($value);
            if ($reason !== null) {
                $reasons[] = $reason;
            }
        }

        return new self(...$reasons);
    }

    /**
     * @return list<string>
     */
    public function toValues(): array
    {
        return array_map(static fn (PolicyComplianceReason $reason): string => $reason->value, $this->reasons);
    }

    public function isEmpty(): bool
    {
        return $this->reasons === [];
    }

    /** The one policy they fail, or null when they fail none or several: only one failure has one remedy. */
    public function sole(): ?PolicyComplianceReason
    {
        return \count($this->reasons) === 1 ? $this->reasons[0] : null;
    }

    public function equals(self $other): bool
    {
        return $this->reasons === $other->reasons;
    }
}
