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

namespace App\Security\Voter;

/**
 * Why {@see OrganizationVoter} denied an action. Attached to the vote's extra data under
 * {@see self::VOTE_KEY} so downstream code (e.g. the access-denied listener) can react to the
 * specific cause without re-deriving it, and surfaced in the vote reason for the 403 message.
 */
enum OrganizationAccessDeniedReason
{
    /** The user is not a member of the organization. */
    case NotAMember;

    /** The user is a member but not an owner, and the action is owner-only. */
    case NotAnOwner;

    /**
     * The user has not enabled the 2FA they need to act for this org: always, as an owner, or because the org
     * enforces it for every member. Preferred over {@see self::PolicySuspended}, which names no remedy.
     */
    case TwoFactorRequired;

    /** The action is reserved for Packagist administrators (e.g. restoring a hidden org). */
    case AdminOnly;

    /**
     * A member suspended for failing policies that cannot be answered with one remedy, so they are sent to
     * the organization overview, which lists all of them. A single failure reports its own reason instead.
     */
    case PolicySuspended;

    /** Extra-data key under which the reason is stored on a denied vote. */
    public const string VOTE_KEY = 'organizationAccessDeniedReason';

    public function message(): string
    {
        return match ($this) {
            self::NotAMember => 'You are not a member of this organization.',
            self::NotAnOwner => 'Only organization owners can perform this action.',
            self::TwoFactorRequired => 'Two-factor authentication is required to act for this organization.',
            self::AdminOnly => 'This action is restricted to Packagist administrators.',
            self::PolicySuspended => 'Your access to this organization is suspended until you satisfy its policies.',
        };
    }
}
