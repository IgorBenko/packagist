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

namespace App\Controller;

use App\Attribute\VarName;
use App\Entity\AuditRecord;
use App\Entity\Job;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\User;
use App\Form\Model\AdminUpdateEmailRequest;
use App\Form\Model\FreezeRequest;
use App\Form\Model\UnfreezeRequest;
use App\Form\Type\AdminUpdateEmailType;
use App\Form\Type\FreezeType;
use App\Form\Type\ProfileFormType;
use App\Form\Type\UnfreezeType;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;
use App\Security\EmailVerifier;
use App\Security\UserNotifier;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class ProfileController extends Controller
{
    #[Route(path: '/profile/', name: 'my_profile')]
    public function myProfile(Request $req, FavoriteManager $favMgr, DownloadManager $dlMgr, #[CurrentUser] User $user, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $packages = $this->getUserPackages($req, $user);
        $lastGithubSync = $this->doctrine->getRepository(Job::class)->getLastGitHubSyncJob($user->getId());

        $data = [
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($favMgr, $dlMgr, $packages),
            'user' => $user,
            'githubSync' => $lastGithubSync,
        ];

        if (!\count($packages)) {
            $data['deleteForm'] = $this->createFormBuilder([])->getForm()->createView();
        }
        $data['rotateApiCsrfToken'] = $csrfTokenManager->getToken('rotate_api');

        return $this->render(
            'user/my_profile.html.twig',
            $data
        );
    }

    #[Route(path: '/users/{name}/', name: 'user_profile')]
    public function publicProfile(Request $req, #[VarName('name')] User $user, FavoriteManager $favMgr, DownloadManager $dlMgr, UserNotifier $userNotifier, LoggerInterface $logger, HttpClientInterface $httpClient, #[CurrentUser] ?User $loggedUser = null): Response
    {
        if ($req->attributes->getString('name') !== $user->getUsername()) {
            return $this->redirectToRoute('user_profile', ['name' => $user->getUsername()]);
        }

        // Admin email update form
        $adminEmailForm = null;
        if ($this->isGranted('ROLE_ADMIN')) {
            \assert($loggedUser !== null);
            $adminUpdateEmailRequest = new AdminUpdateEmailRequest($user->getEmail());
            $adminEmailForm = $this->createForm(AdminUpdateEmailType::class, $adminUpdateEmailRequest);
            $adminEmailForm->handleRequest($req);

            if ($adminEmailForm->isSubmitted() && $adminEmailForm->isValid()) {
                $newEmail = $adminUpdateEmailRequest->email;
                $oldEmail = $user->getEmail();

                if ($newEmail === $oldEmail) {
                    $this->addFlash('warning', 'Email address unchanged.');
                } else {
                    // Check if email is already in use
                    $existingUser = $this->getEM()->getRepository(User::class)->findOneBy(['emailCanonical' => strtolower($newEmail)]);
                    if ($existingUser && $existingUser->getId() !== $user->getId()) {
                        $this->addFlash('error', 'Email address is already in use by another account.');
                    } else {
                        try {
                            $user->setEmail($newEmail);
                            $user->resetPasswordRequest();

                            // Create audit record
                            $auditRecord = AuditRecord::emailChanged($user, $loggedUser, $oldEmail);
                            $this->getEM()->persist($auditRecord);
                            $this->getEM()->persist($user);
                            $this->getEM()->flush();

                            // Send notifications
                            $reason = 'Your email has been changed by an administrator from '.$oldEmail.' to '.$newEmail;
                            $userNotifier->notifyChange($oldEmail, $reason);
                            $userNotifier->notifyChange($newEmail, $reason);

                            $this->addFlash('success', 'Email address updated successfully.');

                            return $this->redirectToRoute('user_profile', ['name' => $user->getUsername()]);
                        } catch (\Exception $e) {
                            $logger->error('Failed to update user email', [
                                'user_id' => $user->getId(),
                                'old_email' => $oldEmail,
                                'new_email' => $newEmail,
                                'error' => $e->getMessage(),
                            ]);
                            $this->addFlash('error', 'Failed to update email address. Please try again.');
                        }
                    }
                }
            }
        }

        $packages = $this->getUserPackages($req, $user);

        $data = [
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($favMgr, $dlMgr, $packages),
            'user' => $user,
        ];

        if ($this->isGranted('ROLE_DISABLE_USERS')) {
            $data['freezeForm'] = $this->createForm(FreezeType::class, new FreezeRequest(), [
                'package_reasons' => PackageFreezeReason::casesForRole($this->isGranted('ROLE_DISABLE_PACKAGES')),
            ])->createView();
            $data['unfreezeForm'] = $this->createForm(UnfreezeType::class, new UnfreezeRequest())->createView();
        }
        if (!\count($packages) && ($this->isGranted('ROLE_ADMIN') || $loggedUser?->getId() === $user->getId())) {
            $data['deleteForm'] = $this->createFormBuilder([])->getForm()->createView();
        }
        if ($adminEmailForm !== null) {
            $data['adminEmailForm'] = $adminEmailForm->createView();
        }
        if ($this->isGranted('ROLE_ADMIN') && $user->getGithubId()) {
            $githubUsername = null;
            try {
                $response = $httpClient->request('GET', 'https://api.github.com/user/'.$user->getGithubId());
                if ($response->getStatusCode() === 200) {
                    $githubUsername = $response->toArray()['login'] ?? null;
                }
            } catch (\Throwable) {
            }
            $data['githubUsername'] = $githubUsername;
        }

        return $this->render(
            'user/public_profile.html.twig',
            $data
        );
    }

    #[Route(path: '/users/{name}/packages/', name: 'user_packages')]
    #[Route(path: '/users/{name}/packages.json', name: 'user_packages_json', defaults: ['_format' => 'json'])]
    public function packagesAction(Request $req, #[VarName('name')] User $user, FavoriteManager $favMgr, DownloadManager $dlMgr): Response
    {
        $packages = $this->getUserPackages($req, $user);

        if ($req->getRequestFormat() === 'json') {
            $packages->setMaxPerPage(50);
            $result = ['packages' => []];
            /** @var Package $pkg */
            foreach ($packages as $pkg) {
                $result['packages'][] = [
                    'name' => $pkg->getName(),
                    'description' => $pkg->getDescription(),
                    'repository' => $pkg->getRepository(),
                ];
            }

            if ($packages->hasNextPage()) {
                $result['next'] = $this->generateUrl('user_packages_json', ['name' => $user->getUsername(), 'page' => $packages->getCurrentPage() + 1], UrlGeneratorInterface::ABSOLUTE_URL);
            }

            return $this->json($result);
        }

        return $this->render(
            'user/packages.html.twig',
            [
                'packages' => $packages,
                'meta' => $this->getPackagesMetadata($favMgr, $dlMgr, $packages),
                'user' => $user,
            ]
        );
    }

    #[Route(path: '/profile/edit', name: 'edit_profile')]
    public function editAction(Request $request, UserNotifier $userNotifier, EmailVerifier $emailVerifier, string $mailFromEmail, string $mailFromName): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('This user does not have access to this section.');
        }

        $oldEmail = $user->getEmail();
        $oldUsername = $user->getUsername();
        $form = $this->createForm(ProfileFormType::class, $user);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $diffs = array_filter([
                $oldEmail !== $user->getEmail() ? 'email ('.$oldEmail.' => '.$user->getEmail().')' : null,
                $oldUsername !== $user->getUsername() ? 'username ('.$oldUsername.' => '.$user->getUsername().')' : null,
            ]);

            if (!empty($diffs)) {
                $reason = \sprintf('Your %s has been changed', implode(' and ', $diffs));

                if ($oldEmail !== $user->getEmail()) {
                    $userNotifier->notifyChange($oldEmail, $reason);
                    $user->resetPasswordRequest();
                    $this->getEM()->persist(AuditRecord::emailChanged($user, $user, $oldEmail));
                }

                if ($oldUsername !== $user->getUsername()) {
                    $this->getEM()->persist(AuditRecord::usernameChanged($user, $user, $oldUsername));
                }

                $userNotifier->notifyChange($user->getEmail(), $reason);
            }

            $this->getEM()->persist($user);
            $this->getEM()->flush();

            // A changed address loses its confirmation in User::setEmailCanonical().
            if (!$user->isEmailVerified()) {
                $this->sendEmailConfirmation($emailVerifier, $user, $mailFromEmail, $mailFromName);
                $this->addFlash('warning', 'We sent a confirmation link to '.$user->getEmail().'. Organizations that require an email domain accept the address once you confirm it.');
            }

            return $this->redirectToRoute('my_profile');
        }

        return $this->render('user/edit.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/profile/email/send-confirmation', name: 'profile_send_email_confirmation', methods: ['POST'])]
    public function sendEmailConfirmationAction(Request $request, #[CurrentUser] User $user, EmailVerifier $emailVerifier, string $mailFromEmail, string $mailFromName): Response
    {
        if (!$this->isCsrfTokenValid('send_email_confirmation', $request->request->getString('token'))) {
            $this->addFlash('error', 'Invalid form submission, please try again.');

            return $this->redirectToRoute('edit_profile');
        }

        if ($user->isEmailVerified()) {
            $this->addFlash('success', 'Your email address is already confirmed.');

            return $this->redirectToRoute('edit_profile');
        }

        $this->sendEmailConfirmation($emailVerifier, $user, $mailFromEmail, $mailFromName);
        $this->addFlash('success', 'We sent a confirmation link to '.$user->getEmail().'.');

        return $this->redirectToRoute('edit_profile');
    }

    /**
     * Target of the link sent above. The signature covers the user id and the address it was sent to, so a
     * link stops working once the address changes, and only the account it was sent for can use it.
     */
    #[Route(path: '/profile/email/confirm', name: 'profile_confirm_email', methods: ['GET'])]
    public function confirmEmailAction(Request $request, #[CurrentUser] User $user, EmailVerifier $emailVerifier): Response
    {
        try {
            $emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $e) {
            $this->addFlash('error', $e->getReason());

            return $this->redirectToRoute('edit_profile');
        }

        $this->addFlash('success', 'Your email address '.$user->getEmail().' is confirmed.');

        return $this->redirectToRoute('edit_profile');
    }

    private function sendEmailConfirmation(EmailVerifier $emailVerifier, User $user, string $mailFromEmail, string $mailFromName): void
    {
        $emailVerifier->sendEmailConfirmation(
            'profile_confirm_email',
            $user,
            new TemplatedEmail()
                ->from(new Address($mailFromEmail, $mailFromName))
                ->to($user->getEmail())
                ->subject('Please confirm your email')
                ->htmlTemplate('registration/confirmation_email.html.twig')
                ->textTemplate('registration/confirmation_email.txt.twig'),
        );
    }

    #[Route(path: '/profile/token/rotate', name: 'rotate_token', methods: ['POST'])]
    public function tokenRotateAction(Request $request, #[CurrentUser] User $user, UserNotifier $userNotifier): Response
    {
        if (!$this->isCsrfTokenValid('rotate_api', $request->request->getString('token'))) {
            $this->addFlash('error', 'Invalid csrf token, try again.');

            return $this->redirectToRoute('my_profile');
        }

        $user->initializeApiToken();
        $user->initializeSafeApiToken();
        $userNotifier->notifyChange($user->getEmail(), 'Your API tokens have been rotated');
        $this->addFlash('success', 'Your API tokens have been rotated');

        $this->getEM()->persist($user);
        $this->getEM()->flush();

        return $this->redirectToRoute('my_profile');
    }

    /**
     * @return Pagerfanta<Package>
     */
    protected function getUserPackages(Request $req, User $user): Pagerfanta
    {
        $packages = $this->getEM()
            ->getRepository(Package::class)
            ->getFilteredQueryBuilder(['maintainer' => $user->getId()], true, includeFrozen: $this->isGranted('ROLE_DISABLE_PACKAGES'));

        $paginator = new Pagerfanta(new QueryAdapter($packages, true));
        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage(max(1, $req->query->getInt('page', 1)));

        return $paginator;
    }
}
