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

use App\Organization\Domain\Exception\InvalidEmailDomainException;
use Composer\Pcre\Preg;

/**
 * The email-address domains an organization accepts for its members. Empty means the policy is off.
 *
 * Normalised on construction (lowercased, `@` stripped, deduplicated, sorted) so {@see equals()} can decide
 * whether an edit changes anything and matching an address is a plain lookup.
 */
final readonly class AllowedEmailDomains
{
    /** Enough for an org with a handful of brands, while the policy still reads on one line. */
    public const int MAX = 10;

    private const string PATTERN = '{^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$}';

    /** @var list<string> */
    public array $domains;

    /**
     * @throws InvalidEmailDomainException an entry is not a domain name, or there are more than {@see MAX}
     */
    public function __construct(string ...$domains)
    {
        $normalised = [];
        foreach ($domains as $domain) {
            $domain = mb_strtolower(ltrim(trim($domain), '@'));
            if ($domain === '') {
                continue;
            }

            if (!Preg::isMatch(self::PATTERN, $domain)) {
                throw new InvalidEmailDomainException(sprintf('"%s" is not a valid email address domain.', $domain));
            }

            $normalised[$domain] = true;
        }

        $normalised = array_keys($normalised);
        sort($normalised);

        if (\count($normalised) > self::MAX) {
            throw new InvalidEmailDomainException(sprintf('At most %d email address domains can be required.', self::MAX));
        }

        $this->domains = $normalised;
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * @throws InvalidEmailDomainException
     */
    public static function fromList(string $list): self
    {
        return new self(...Preg::split('{[\s,;]+}', $list, -1, PREG_SPLIT_NO_EMPTY));
    }

    /**
     * @param list<string> $values
     *
     * @throws InvalidEmailDomainException
     */
    public static function fromValues(array $values): self
    {
        return new self(...$values);
    }

    /**
     * @return list<string>
     */
    public function toValues(): array
    {
        return $this->domains;
    }

    public function isEmpty(): bool
    {
        return $this->domains === [];
    }

    /** Null (no address on record) never matches: an active policy refuses rather than waving someone through. */
    public function matches(?string $emailDomain): bool
    {
        return $emailDomain !== null && \in_array(mb_strtolower($emailDomain), $this->domains, true);
    }

    public function equals(self $other): bool
    {
        return $this->domains === $other->domains;
    }

    /** As the policy form shows and accepts it. */
    public function toInput(): string
    {
        return implode(', ', $this->domains);
    }
}
