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

namespace App\Doctrine\Type;

use App\Organization\Domain\UnmetPolicies as UnmetPoliciesValue;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

/**
 * Stores the policies a member fails as a JSON array of policy values, e.g. `["two_factor"]`, so the set can
 * be queried with JSON_CONTAINS rather than by matching substrings. The empty array is the empty set; a JSON
 * `null` (what MySQL writes into existing rows when the column is added) reads back as the empty set too.
 */
class UnmetPolicies extends JsonType
{
    private const string NAME = 'unmet_policies';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): UnmetPoliciesValue
    {
        $decoded = parent::convertToPHPValue($value, $platform);
        if (!\is_array($decoded)) {
            return UnmetPoliciesValue::none();
        }

        // strval rather than a filter: a value that is not a policy has to fail loudly here, since silently
        // dropping it would hand back access the column says the member has lost.
        return UnmetPoliciesValue::fromValues(array_values(array_map(strval(...), $decoded)));
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): string
    {
        return parent::convertToDatabaseValue(
            $value instanceof UnmetPoliciesValue ? $value->toValues() : [],
            $platform,
        );
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
