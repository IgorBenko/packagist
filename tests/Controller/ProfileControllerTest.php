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

namespace App\Tests\Controller;

use App\Entity\AuditRecord;
use App\Entity\PackageFreezeReason;
use App\Entity\User;
use App\Log\AuditLogEventType;
use App\Tests\IntegrationTestCase;
use Symfony\Component\Mime\Email;

class ProfileControllerTest extends IntegrationTestCase
{
    public function testEditProfile(): void
    {
        $user = self::createUser();
        $oldEmail = $user->getEmail();
        $oldUsername = $user->getUsername();
        $this->store($user);

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/edit');

        $failureNotifications = $crawler->filter('.failure-notifications');
        $this->assertCount(1, $failureNotifications->filter('input[type="checkbox"].form-check-input'));
        $this->assertSame(
            'Notify me of package update failures',
            trim($failureNotifications->filter('label.form-check-label')->text()),
            'the failure-notifications checkbox must render its label (set on ProfileFormType)',
        );

        $form = $crawler->selectButton('Update')->form();
        $this->client->submit($form, [
            'packagist_user_profile[email]' => $newEmail = 'new-email@example.org',
            'packagist_user_profile[username]' => $newUsername = 'newusername',
        ]);

        $this->assertResponseStatusCodeSame(302);

        $recipients = array_map(static fn (Email $mail) => $mail->getTo()[0]->getAddress(), $this->getMailerMessages());
        $this->assertEqualsCanonicalizing([$oldEmail, $newEmail, $newEmail], $recipients, 'Notification should have been sent to both old and new email, and a confirmation link to the new one');

        $em = self::getEM();
        $em->clear();
        $user = $em->getRepository(User::class)->find($user->getId());
        $this->assertNotNull($user);
        $this->assertSame($newEmail, $user->getEmail());
        $this->assertSame($newUsername, $user->getUsername());
        $this->assertNull($user->getPasswordRequestedAt());
        $this->assertNull($user->getConfirmationToken());
        $this->assertFalse($user->isEmailVerified(), 'a new address is unconfirmed until its link is followed');

        $emailAuditRecord = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditLogEventType::EmailChanged,
            'userId' => $user->getId(),
        ]);
        $this->assertInstanceOf(AuditRecord::class, $emailAuditRecord);
        $this->assertSame($oldEmail, $emailAuditRecord->attributes['email_from'] ?? null);
        $this->assertSame($newEmail, $emailAuditRecord->attributes['email_to'] ?? null);
        $this->assertSame($user->getUsernameCanonical(), $emailAuditRecord->attributes['user']['username'] ?? null);
        $this->assertSame($user->getUsernameCanonical(), $emailAuditRecord->attributes['actor']['username'] ?? null);

        $usernameAuditRecord = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditLogEventType::UsernameChanged,
            'userId' => $user->getId(),
        ]);
        $this->assertInstanceOf(AuditRecord::class, $usernameAuditRecord);
        $this->assertSame($oldUsername, $usernameAuditRecord->attributes['username_from'] ?? null);
        $this->assertSame($user->getUsernameCanonical(), $usernameAuditRecord->attributes['username_to'] ?? null);
        $this->assertSame($user->getUsernameCanonical(), $usernameAuditRecord->attributes['actor']['username'] ?? null);
    }

    public function testAChangedEmailIsConfirmedThroughTheEmailedLink(): void
    {
        $user = self::createUser();
        $this->store($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/edit');
        $this->client->submit($crawler->selectButton('Update')->form(), [
            'packagist_user_profile[email]' => 'new-email@example.org',
        ]);
        self::assertResponseRedirects('/profile/');
        // Mails are only collected for the last request.
        $link = $this->confirmationLink('new-email@example.org');

        $crawler = $this->client->request('GET', '/profile/edit');
        self::assertSame('Not confirmed. Send a confirmation link', trim($crawler->filter('.email-confirmation-status')->text()));

        $this->client->request('GET', $link);
        self::assertResponseRedirects('/profile/edit');

        $crawler = $this->client->request('GET', '/profile/edit');
        self::assertSame('Confirmed', trim($crawler->filter('.email-confirmation-status')->text()));

        self::assertTrue($this->reloadUser($user)->isEmailVerified());
    }

    public function testAProfileSaveThatKeepsTheAddressKeepsItConfirmed(): void
    {
        $user = self::createUser();
        $this->store($user);
        $this->client->loginUser($user);

        // Only the case differs, which is the same address.
        $crawler = $this->client->request('GET', '/profile/edit');
        $this->client->submit($crawler->selectButton('Update')->form(), [
            'packagist_user_profile[email]' => 'Test@Example.org',
            'packagist_user_profile[username]' => 'renamed',
        ]);
        self::assertResponseRedirects('/profile/');

        self::assertTrue($this->reloadUser($user)->isEmailVerified());
        foreach ($this->getMailerMessages() as $mail) {
            self::assertInstanceOf(Email::class, $mail);
            self::assertNotSame('Please confirm your email', $mail->getSubject());
        }
    }

    public function testAnUnconfirmedUserCanRequestAConfirmationLink(): void
    {
        $user = self::createUser(emailVerified: false);
        $this->store($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/edit');
        $this->client->submit($crawler->filter('#send-email-confirmation')->form());
        self::assertResponseRedirects('/profile/edit');

        $this->client->request('GET', $this->confirmationLink('test@example.org'));
        self::assertTrue($this->reloadUser($user)->isEmailVerified());
    }

    public function testAConfirmationLinkIsNotSentWithoutAValidToken(): void
    {
        $user = self::createUser(emailVerified: false);
        $this->store($user);
        $this->client->loginUser($user);

        $this->client->request('POST', '/profile/email/send-confirmation', ['token' => 'invalid']);
        self::assertResponseRedirects('/profile/edit');

        self::assertEmailCount(0);
    }

    public function testAConfirmationLinkStopsWorkingOnceTheAddressChanges(): void
    {
        $user = self::createUser(emailVerified: false);
        $this->store($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/edit');
        $this->client->submit($crawler->filter('#send-email-confirmation')->form());
        $link = $this->confirmationLink('test@example.org');

        $crawler = $this->client->request('GET', '/profile/edit');
        $this->client->submit($crawler->selectButton('Update')->form(), [
            'packagist_user_profile[email]' => 'other@example.org',
        ]);

        // The link proves control of test@example.org, which is no longer the address on the account.
        $this->client->request('GET', $link);
        self::assertResponseRedirects('/profile/edit');
        self::assertFalse($this->reloadUser($user)->isEmailVerified());
    }

    public function testAConfirmationLinkOnlyWorksForTheAccountItWasSentFor(): void
    {
        $owner = self::createUser('owner', 'owner@example.org', emailVerified: false);
        $other = self::createUser('other', 'other@example.org', emailVerified: false);
        $this->store($owner, $other);

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/profile/edit');
        $this->client->submit($crawler->filter('#send-email-confirmation')->form());
        $link = $this->confirmationLink('owner@example.org');

        $this->client->loginUser($other);
        $this->client->request('GET', $link);
        self::assertResponseRedirects('/profile/edit');

        self::assertFalse($this->reloadUser($other)->isEmailVerified());
        self::assertFalse($this->reloadUser($owner)->isEmailVerified());
    }

    private function confirmationLink(string $address): string
    {
        foreach ($this->getMailerMessages() as $mail) {
            if (!$mail instanceof Email || $mail->getSubject() !== 'Please confirm your email' || $mail->getTo()[0]->getAddress() !== $address) {
                continue;
            }

            self::assertSame(1, preg_match('{/profile/email/confirm\?\S+}', $mail->getTextBody() ?? '', $match), 'The mail carries a confirmation link');

            return $match[0];
        }

        self::fail('No confirmation link was sent to '.$address);
    }

    private function reloadUser(User $user): User
    {
        $em = self::getEM();
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($user->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    public function testPublicProfileLinksToAuditLog(): void
    {
        $user = self::createUser();
        $this->store($user);

        $crawler = $this->client->request('GET', '/users/test/');
        self::assertResponseIsSuccessful();

        $auditLink = $crawler->filter('a[href*="transparency-log"]');
        self::assertCount(1, $auditLink);
        self::assertStringContainsString('user=test', (string) $auditLink->attr('href'));
        self::assertStringContainsString('noindex', (string) $auditLink->attr('rel'));
    }

    public function testAdminSeesEmailConfirmedFlagOnProfile(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN']);
        $unconfirmed = self::createUser('bob', 'bob@example.org', enabled: false);
        $this->store($admin, $unconfirmed);

        $this->client->loginUser($admin);

        // An unconfirmed (disabled) account is flagged with a red "No".
        $crawler = $this->client->request('GET', '/users/bob/');
        self::assertResponseIsSuccessful();
        $metadata = $crawler->filter('.dl-horizontal');
        self::assertStringContainsString('Email Confirmed:', $metadata->text());
        self::assertSame('No', $metadata->filter('.text-danger')->text());
        self::assertCount(0, $metadata->filter('.text-success'));
    }

    public function testEmailConfirmedFlagHiddenFromNonAdmins(): void
    {
        $this->store(self::createUser('bob', 'bob@example.org'));

        $crawler = $this->client->request('GET', '/users/bob/');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Email Confirmed:', $crawler->html());
    }

    public function testFrozenPackagesListedForPackageModeratorsOnly(): void
    {
        $mod = self::createUser('mod', 'mod@example.org', roles: ['ROLE_DISABLE_PACKAGES']);
        $bob = self::createUser('bob', 'bob@example.org');
        // Malware passes the repository's SQL filter; spam is excluded there — both must surface for
        // a moderator (macro + query relaxed) yet stay hidden from the public.
        $malware = self::createPackage('test/malwarepkg', 'https://example.org/malwarepkg', maintainers: [$bob]);
        $malware->freeze(PackageFreezeReason::Malware);
        $spam = self::createPackage('test/spampkg', 'https://example.org/spampkg', maintainers: [$bob]);
        $spam->freeze(PackageFreezeReason::Spam);
        $this->store($mod, $bob, $malware, $spam);

        // A regular visitor sees neither suppressed package.
        $crawler = $this->client->request('GET', '/users/bob/');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('test/malwarepkg', $crawler->html());
        self::assertStringNotContainsString('test/spampkg', $crawler->html());

        // A package moderator sees both, each flagged as frozen.
        $this->client->loginUser($mod);
        $crawler = $this->client->request('GET', '/users/bob/');
        self::assertResponseIsSuccessful();
        $listing = $crawler->html();
        self::assertStringContainsString('test/malwarepkg', $listing);
        self::assertStringContainsString('[FROZEN: malware]', $listing);
        self::assertStringContainsString('test/spampkg', $listing);
        self::assertStringContainsString('[FROZEN: spam]', $listing);
    }

    public function testFrozenPackagesStayHiddenOnBrowseListingsForModerators(): void
    {
        // Browse/search/vendor listings never opt into showFrozen, so a moderator does not see
        // suppressed packages there even though they would on a user profile.
        $mod = self::createUser('mod', 'mod@example.org', roles: ['ROLE_DISABLE_PACKAGES']);
        $good = self::createPackage('somevendor/good', 'https://example.org/somevendor/good');
        $spam = self::createPackage('somevendor/bad', 'https://example.org/somevendor/bad');
        $spam->freeze(PackageFreezeReason::Spam);
        $this->store($mod, $good, $spam);

        $this->client->loginUser($mod);
        $crawler = $this->client->request('GET', '/packages/somevendor/');
        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('somevendor/good', $html);
        self::assertStringNotContainsString('somevendor/bad', $html);
        self::assertStringNotContainsString('[FROZEN', $html);
    }

    public function testTokenRotate(): void
    {
        $user = self::createUser();
        $this->store($user);

        $token = $user->getApiToken();
        $safeToken = $user->getSafeApiToken();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/');
        $this->assertEquals($token, $crawler->filter('.api-token')->first()->attr('data-api-token'));
        $this->assertEquals($safeToken, $crawler->filter('.api-token')->last()->attr('data-api-token'));

        $form = $crawler->selectButton('Rotate API Tokens')->form();
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(302);

        $em = self::getEM();
        $em->clear();
        $user = $em->getRepository(User::class)->find($user->getId());
        $this->assertNotNull($user);
        $this->assertNotEquals($token, $user->getApiToken());
        $this->assertNotEquals($safeToken, $user->getSafeApiToken());
    }
}
