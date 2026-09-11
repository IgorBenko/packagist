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

use App\Organization\Domain\AllowedEmailDomains;
use App\Organization\Domain\Exception\InvalidEmailDomainException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class AllowedEmailDomainsTest extends TestCase
{
    public function testNoneIsTheEmptySet(): void
    {
        $none = AllowedEmailDomains::none();

        self::assertTrue($none->isEmpty());
        self::assertSame([], $none->toValues());
        self::assertSame('', $none->toInput());
        // Nothing matches while the policy is off; callers check isEmpty() first.
        self::assertFalse($none->matches('acme.com'));
    }

    public function testDomainsAreNormalisedAndDeduplicated(): void
    {
        $domains = new AllowedEmailDomains(' ACME.com ', '@acme.com', 'brand.acme.io');

        self::assertSame(['acme.com', 'brand.acme.io'], $domains->domains);
        self::assertSame('acme.com, brand.acme.io', $domains->toInput());
    }

    public function testOrderDoesNotChangeTheSet(): void
    {
        self::assertTrue(
            new AllowedEmailDomains('b.example', 'a.example')
                ->equals(new AllowedEmailDomains('a.example', 'b.example')),
        );
        self::assertFalse(new AllowedEmailDomains('a.example')->equals(AllowedEmailDomains::none()));
    }

    public function testMatchingIsCaseInsensitiveAndRefusesAnUnknownAddress(): void
    {
        $domains = new AllowedEmailDomains('acme.com', 'acme.io');

        self::assertTrue($domains->matches('acme.com'));
        self::assertTrue($domains->matches('ACME.IO'));
        self::assertFalse($domains->matches('notacme.com'));
        self::assertFalse($domains->matches('sub.acme.com'));
        // No address on record must never pass an active policy.
        self::assertFalse($domains->matches(null));
    }

    #[TestWith(['acme.com, acme.io'])]
    #[TestWith(['acme.com acme.io'])]
    #[TestWith(["acme.com\nacme.io"])]
    #[TestWith([' acme.com , , acme.io '])]
    public function testTheFormListIsParsedHoweverItIsSeparated(string $list): void
    {
        self::assertSame(['acme.com', 'acme.io'], AllowedEmailDomains::fromList($list)->domains);
    }

    public function testAnEmptyListIsTheEmptySet(): void
    {
        self::assertTrue(AllowedEmailDomains::fromList('   ')->isEmpty());
    }

    #[TestWith(['not a domain'])]
    #[TestWith(['acme'])]
    #[TestWith(['acme.'])]
    #[TestWith(['-acme.com'])]
    #[TestWith(['acme.com/path'])]
    #[TestWith(['alice@acme.com'])]
    public function testAnEntryThatIsNotADomainIsRefused(string $domain): void
    {
        $this->expectException(InvalidEmailDomainException::class);

        new AllowedEmailDomains($domain);
    }

    public function testMoreThanTheMaximumIsRefused(): void
    {
        $domains = array_map(static fn (int $i): string => 'domain'.$i.'.example', range(1, AllowedEmailDomains::MAX + 1));

        $this->expectException(InvalidEmailDomainException::class);

        new AllowedEmailDomains(...$domains);
    }

    public function testStoredValuesRoundTrip(): void
    {
        $domains = new AllowedEmailDomains('acme.com', 'acme.io');

        self::assertTrue(AllowedEmailDomains::fromValues($domains->toValues())->equals($domains));
    }
}
