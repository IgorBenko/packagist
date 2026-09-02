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

namespace App\EventListener;

use App\Entity\Organization;
use App\Entity\User;
use App\Organization\OrganizationPolicyEnforcer;
use App\Security\Voter\OrganizationAccessDeniedReason;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

/**
 * Turns an organization access denial (flagged by {@see \App\Security\Voter\OrganizationVoter}) into
 * something the user can act on: an owner blocked only by missing 2FA is sent to enable it, a suspended
 * member gets the notice listing what they owe. Anything else keeps the bare 403.
 */
class OrganizationPolicyAccessDeniedListener
{
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $router,
        private readonly OrganizationPolicyEnforcer $policyEnforcer,
        private readonly Environment $twig,
    ) {
    }

    // Runs ahead of the security firewall's exception listener (priority 1), which otherwise rewraps
    // the AccessDeniedException into an AccessDeniedHttpException before we could read its decision.
    #[AsEventListener(priority: 64)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if (!$throwable instanceof AccessDeniedException || !($reason = $this->getOrganizationAccessDeniedReason($throwable))) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        // The notice states the reason itself, so it is the one response not to also flash it.
        $notice = $this->suspensionNotice($reason, $throwable->getSubject(), $user);
        if ($notice !== null) {
            $event->setResponse($notice);
            $event->stopPropagation();

            return;
        }

        $session = $event->getRequest()->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $reason->message());
        }

        if ($reason === OrganizationAccessDeniedReason::TwoFactorRequired) {
            $event->setResponse(new RedirectResponse(
                $this->router->generate('user_2fa_configure', ['name' => $user->getUsername()]),
                Response::HTTP_FOUND,
            ));

            $event->stopPropagation();
        }
    }

    /**
     * Rendered in place of the page the member asked for rather than as a page of its own, which would
     * have to be exempt from the very check it explains.
     */
    private function suspensionNotice(OrganizationAccessDeniedReason $reason, mixed $organization, User $user): ?Response
    {
        if ($reason !== OrganizationAccessDeniedReason::PolicySuspended || !$organization instanceof Organization) {
            return null;
        }

        // The verdict changed under us, and a notice with no remedies explains nothing.
        $remediations = $this->policyEnforcer->remediationsFor($organization, $user);
        if ($remediations === []) {
            return null;
        }

        return new Response(
            $this->twig->render('organization/suspended.html.twig', [
                'organization' => $organization,
                'remediations' => $remediations,
            ]),
            Response::HTTP_FORBIDDEN,
        );
    }

    private function getOrganizationAccessDeniedReason(AccessDeniedException $exception): ?OrganizationAccessDeniedReason
    {
        $decision = $exception->getAccessDecision();
        if ($decision === null) {
            return null;
        }

        foreach ($decision->votes as $vote) {
            if (isset($vote->extraData[OrganizationAccessDeniedReason::VOTE_KEY]) && $vote->extraData[OrganizationAccessDeniedReason::VOTE_KEY] instanceof OrganizationAccessDeniedReason) {
                return $vote->extraData[OrganizationAccessDeniedReason::VOTE_KEY];
            }
        }

        return null;
    }
}
