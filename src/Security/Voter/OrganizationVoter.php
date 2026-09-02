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

use App\Entity\Organization;
use App\Entity\OrganizationMemberRepository;
use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\User;
use App\Organization\Domain\PolicyComplianceReason;
use App\Organization\OrganizationPolicyEnforcer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<value-of<OrganizationActions>, Organization>
 */
class OrganizationVoter extends Voter
{
    public function __construct(
        private Security $security,
        private OrganizationTeamMemberRepository $organizationTeamMemberRepo,
        private OrganizationMemberRepository $organizationMemberRepo,
        private OrganizationPolicyEnforcer $policyEnforcer,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Organization && OrganizationActions::tryFrom($attribute) instanceof OrganizationActions;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var Organization $organization */
        $organization = $subject;

        $action = OrganizationActions::from($attribute);

        // A packagist-admin may perform any owner action on any org for moderation, and is
        // the only actor who can restore. Their authority derives from admin status.
        if ($this->security->isGranted('ROLE_ADMIN_ORGS')) {
            return true;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($organization->isDeleted()) {
            return false;
        }

        $reason = $this->denialReason($action, $organization, $user);
        if ($reason !== null) {
            return $this->deny($vote, $reason);
        }

        return true;
    }

    /**
     * Standing in the org first, then compliance with its policies: a non-owner is told that, rather than
     * being sent to enable 2FA for an action that would stay out of reach once they had.
     */
    private function denialReason(OrganizationActions $action, Organization $organization, User $user): ?OrganizationAccessDeniedReason
    {
        $standing = match ($action) {
            // Owners have no visibility into a hidden org, so restore is packagist-admin only.
            OrganizationActions::Restore => OrganizationAccessDeniedReason::AdminOnly,
            OrganizationActions::View,
            OrganizationActions::Visible,
            OrganizationActions::ViewMembers,
            OrganizationActions::ViewTeams,
            OrganizationActions::Leave => $this->memberDenialReason($organization, $user),
            OrganizationActions::Edit,
            OrganizationActions::ViewAuditLog,
            OrganizationActions::SoftDelete,
            OrganizationActions::CreateTeam,
            OrganizationActions::RenameTeam,
            OrganizationActions::DeleteTeam,
            OrganizationActions::AddTeamMember,
            OrganizationActions::RemoveTeamMember,
            OrganizationActions::RemoveMember,
            OrganizationActions::ViewPolicies,
            OrganizationActions::EditPolicies,
            OrganizationActions::ViewInvitations,
            OrganizationActions::InviteMember,
            OrganizationActions::ResendInvitation,
            OrganizationActions::RevokeInvitation => $this->manageDenialReason($organization, $user),
        };

        if ($standing !== null) {
            return $standing;
        }

        // Leave is exempt so a suspended member is never trapped, Visible is standing only by definition.
        // Everything else, viewing the org included, requires compliance.
        if (\in_array($action, [OrganizationActions::Visible, OrganizationActions::Leave], true)) {
            return null;
        }

        return $this->complianceDenialReason($organization, $user);
    }

    /**
     * Reached only once their standing is established, so a member is re-verified on every org page they
     * load and nobody else pays for the lookups.
     */
    private function complianceDenialReason(Organization $organization, User $user): ?OrganizationAccessDeniedReason
    {
        $unmet = $this->policyEnforcer->enforce($organization, $user);
        if ($unmet->isEmpty()) {
            return null;
        }

        // No single remedy answers several failures, so the listener renders the notice listing them all.
        $sole = $unmet->sole();
        if ($sole === null) {
            return OrganizationAccessDeniedReason::PolicySuspended;
        }

        // A match, so a new policy forces a decision here about whether it can name a remedy of its own.
        return match ($sole) {
            PolicyComplianceReason::TwoFactor => OrganizationAccessDeniedReason::TwoFactorRequired,
            // Changing an account email has no single page worth redirecting to, so the notice explains it.
            PolicyComplianceReason::EmailDomain => OrganizationAccessDeniedReason::PolicySuspended,
        };
    }

    private function memberDenialReason(Organization $organization, User $user): ?OrganizationAccessDeniedReason
    {
        if (!$this->isMember($organization, $user)) {
            return OrganizationAccessDeniedReason::NotAMember;
        }

        return null;
    }

    private function manageDenialReason(Organization $organization, User $user): ?OrganizationAccessDeniedReason
    {
        if (!$this->isOwner($organization, $user)) {
            return OrganizationAccessDeniedReason::NotAnOwner;
        }

        return null;
    }

    private function deny(?Vote $vote, OrganizationAccessDeniedReason $reason): bool
    {
        if ($vote !== null) {
            $vote->addReason($reason->message());
            $vote->extraData[OrganizationAccessDeniedReason::VOTE_KEY] = $reason;
        }

        return false;
    }

    private function isOwner(Organization $organization, User $user): bool
    {
        return $this->organizationTeamMemberRepo->isOwner($organization->ownersTeamId, $user->getId());
    }

    private function isMember(Organization $organization, User $user): bool
    {
        return $this->organizationMemberRepo->findOneByOrgAndUser($organization->id, $user->getId()) !== null;
    }
}
