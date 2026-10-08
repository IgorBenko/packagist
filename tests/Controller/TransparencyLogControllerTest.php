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

use App\Command\ProjectTransparencyLogCommand;
use App\Entity\AuditRecord;
use App\Entity\PackageFreezeReason;
use App\Entity\PackageTransparencyLogRepository;
use App\Log\TransparencyLogEventType;
use App\QueryFilter\TransparencyLog\UserIdFilter;
use App\Tests\IntegrationTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\InputBag;

class TransparencyLogControllerTest extends IntegrationTestCase
{
    public function testAnonymousVisitorsAreSentToLogin(): void
    {
        $this->client->request('GET', '/transparency-log');

        static::assertResponseRedirects('/login/');
    }

    public function testShowsProjectedEvents(): void
    {
        $this->givenProjectedLog();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log');
        static::assertResponseIsSuccessful();

        $types = $crawler->filter('[data-test="log-type"]')->each(fn ($element) => trim($element->text()));
        static::assertContains('Package created', $types);
    }

    public function testPageIsNotIndexable(): void
    {
        $this->givenProjectedLog();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('meta[name="robots"][content="noindex"]'));
    }

    public function testUnprojectedAuditRecordsAreNotShown(): void
    {
        $user = self::createUser('unprojected', 'unprojected@example.com');
        $organization = self::createOrganization('acme', 'ACME Corp');
        $this->store($user, $organization);

        // organization_created is out of scope for the projection, so it never reaches this page.
        $this->store(AuditRecord::organizationCreated($organization->id, $organization->slug, $organization->displayName, $user));

        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log');
        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('[data-test="log-type"]'));
    }

    public function testFiltersByPackageVendorAndType(): void
    {
        $this->givenProjectedLog();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['package' => 'vendor1/package1']));
        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('[data-test="log-type"]'));

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['vendor' => 'vendor1']));
        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('[data-test="log-type"]'));

        // A vendor with nothing projected returns an empty, non-crashing result.
        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['vendor' => 'nobody']));
        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('[data-test="log-type"]'));

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query([
            'type' => [TransparencyLogEventType::VersionCreated->value],
        ]));
        static::assertResponseIsSuccessful();
        static::assertCount(0, $crawler->filter('[data-test="log-type"]'));
    }

    public function testFreezeReasonIsRenderedAsALabelNotARawEnumValue(): void
    {
        $admin = self::createUser('freezer', 'freezer@example.org', roles: ['ROLE_ADMIN']);
        $this->store($admin);
        $package = self::createPackage('acme/frozen-log', 'https://github.com/acme/frozen-log');
        $this->store($package);

        $this->getEM()->getRepository(AuditRecord::class)->insert(
            AuditRecord::packageFrozen($package, $admin, PackageFreezeReason::RemoteIdMismatch),
        );

        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['package' => 'acme/frozen-log']));

        static::assertResponseIsSuccessful();
        $details = $crawler->filter('td.audit-log-details')->text();
        static::assertStringContainsString('Repository ID mismatch', $details);
        static::assertStringNotContainsString('remote_id', $details);
    }

    public function testTwoFactorEventsAreHiddenEvenWhenRequestedExplicitly(): void
    {
        $user = self::createUser('hidden', 'hidden@example.com');
        $this->store($user);
        $package = self::createPackage('vendor1/package1', 'https://github.com/vendor1/package1', maintainers: [$user]);
        $this->store($package);

        $this->getEM()->getRepository(AuditRecord::class)->insert(AuditRecord::twoFactorAuthenticationDeactivated($user, $user, 'x'));
        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query([
            'type' => [TransparencyLogEventType::TwoFactorAuthenticationDeactivated->value],
        ]));
        static::assertResponseIsSuccessful();

        // The hidden type is dropped from the filter, so this falls back to the unfiltered list, which
        // itself excludes it. The rows are still projected, see TransparencyLogProjectorTest.
        $types = $crawler->filter('[data-test="log-type"]')->each(fn ($element) => trim($element->text()));
        static::assertNotContains('Two-factor authentication disabled', $types);
        static::assertContains('Package created', $types);
    }

    /**
     * The display templates are shared with the internal audit log, which does render the internal
     * moderation note, so make sure the public page keeps leaving it out.
     */
    public function testInternalModerationNoteNeverReachesThePublicLog(): void
    {
        $admin = self::createUser('deleter', 'deleter@example.org', roles: ['ROLE_ADMIN']);
        $this->store($admin);
        $package = self::createPackage('acme/deleted-log', 'https://github.com/acme/deleted-log');
        $this->store($package);

        $this->getEM()->getRepository(AuditRecord::class)->insert(
            AuditRecord::packageDeleted($package, $admin, 'spam', 'reporter jane, ticket #42'),
        );

        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['package' => 'acme/deleted-log']));

        static::assertResponseIsSuccessful();
        $details = $crawler->filter('td.audit-log-details')->text();
        static::assertStringContainsString('Reason: spam', $details);
        static::assertStringNotContainsString('Internal reason', $details);
        static::assertStringNotContainsString('ticket #42', $details);
    }

    /**
     * Deleting a package removes its row from the package table, so the entries are matched on the name
     * denormalised onto each of them. The entry announcing the deletion is the one a reader comes for,
     * and it would be unreachable if the filter resolved the name through the live package table.
     */
    public function testEntriesOfADeletedPackageStayFilterableByName(): void
    {
        $admin = self::createUser('purger', 'purger@example.org', roles: ['ROLE_ADMIN']);
        $this->store($admin);
        $package = self::createPackage('acme/gone-log', 'https://github.com/acme/gone-log');
        $this->store($package);

        $this->getEM()->getRepository(AuditRecord::class)->insert(
            AuditRecord::packageDeleted($package, $admin, 'spam', null),
        );
        $this->getEM()->getConnection()->executeStatement('DELETE FROM package WHERE id = :id', ['id' => $package->getId()]);

        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['package' => 'acme/gone-log']));

        static::assertResponseIsSuccessful();
        $types = $crawler->filter('[data-test="log-type"]')->each(fn ($element) => trim($element->text()));
        // Newest first: the whole history of the gone package is still readable, deletion included.
        static::assertSame(['Package deleted', 'Package created'], $types);
    }

    /**
     * A late arrival: the unfreeze is the newest leaf but has the oldest event time.
     */
    public function testEntriesAreOrderedByEventTime(): void
    {
        $admin = self::createUser('late', 'late@example.org', roles: ['ROLE_ADMIN']);
        $this->store($admin);
        $package = self::createPackage('acme/late-log', 'https://github.com/acme/late-log');
        $this->store($package);

        $records = $this->getEM()->getRepository(AuditRecord::class);
        $records->insert(AuditRecord::packageFrozen($package, $admin, PackageFreezeReason::Spam));
        $records->insert(AuditRecord::packageUnfrozen($package, $admin));
        $this->runProjector();

        $conn = $this->getEM()->getConnection();
        foreach (['package_created' => '2026-01-01 09:00:00', 'package_frozen' => '2026-01-01 10:00:05', 'package_unfrozen' => '2026-01-01 10:00:01'] as $type => $datetime) {
            $conn->executeStatement('UPDATE package_transparency_log SET datetime = ? WHERE packageName = ? AND type = ?', [$datetime, 'acme/late-log', $type]);
        }

        $this->givenLoggedInVisitor();

        foreach ([['package' => 'acme/late-log'], ['package' => 'acme/late-log', 'datetime_from' => '2026-01-01T00:00:00']] as $query) {
            $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query($query));
            $types = $crawler->filter('[data-test="log-type"]')->each(fn ($element) => trim($element->text()));
            static::assertSame(['Package frozen', 'Package unfrozen', 'Package created'], $types);
        }
    }

    public function testPageFarPastTheEndShowsTheLastPage(): void
    {
        $this->givenProjectedLog();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?page=999999999');

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('[data-test="log-type"]'));
        static::assertCount(0, $crawler->filter('[data-test="page-limit-note"]'));
    }

    public function testPageLimitNoteIsShownWhenThereAreMoreEntriesThanPages(): void
    {
        $conn = $this->getEM()->getConnection();
        $conn->executeStatement('SET SESSION cte_max_recursion_depth = 20000');
        $conn->executeStatement(<<<'SQL'
            INSERT INTO package_transparency_log (id, sourceAuditLogId, leafIndex, type, attributes, datetime, packageId, packageName, vendor)
            WITH RECURSIVE seq (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 10001)
            SELECT UNHEX(MD5(CONCAT('id', n))), UNHEX(MD5(CONCAT('source', n))), n, 'package_created',
                JSON_OBJECT('name', 'acme/many', 'repository', 'https://github.com/acme/many', 'actor', 'automation'),
                NOW(), 1, 'acme/many', 'acme'
            FROM seq
            SQL);
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?page=999999999');

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('Only the first 500 pages are shown', $crawler->filter('[data-test="page-limit-note"]')->text());
    }

    /**
     * Most entries name the person only as the actor, so the entry's userId is empty.
     */
    public function testActorFilterFindsEntriesThatOnlyNameThePersonAsTheActor(): void
    {
        $this->givenAPackageDeletedByAnAdmin();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['actor' => 'onlyactor']));
        static::assertResponseIsSuccessful();

        $types = $crawler->filter('[data-test="log-type"]')->each(fn ($element) => trim($element->text()));
        static::assertContains('Package deleted', $types);
    }

    /**
     * The User filter finds what an action was about, not what the person did.
     */
    public function testUserFilterDoesNotFindEntriesThePersonOnlyDid(): void
    {
        $this->givenAPackageDeletedByAnAdmin();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user' => 'onlyactor']));
        static::assertResponseIsSuccessful();

        $types = $crawler->filter('[data-test="log-type"]')->each(fn ($element) => trim($element->text()));
        static::assertNotContains('Package deleted', $types);
    }

    /**
     * A transfer has no subject. Its maintainers are found through the index only.
     */
    public function testOwnershipTransfersAreFoundByAMaintainerNamedOnlyInTheSnapshot(): void
    {
        $previous = self::createUser('snapshotonly', 'snapshotonly@example.org');
        $current = self::createUser('newowner', 'newowner@example.org');
        $admin = self::createUser('transfermod', 'transfermod@example.org', roles: ['ROLE_ADMIN']);
        $this->store($previous, $current, $admin);

        $package = self::createPackage('acme/transferred-log', 'https://github.com/acme/transferred-log', maintainers: [$current]);
        $this->store($package);
        $this->store(AuditRecord::packageTransferred($package, $admin, [$previous], [$current]));

        $this->runProjector();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user' => 'snapshotonly']));
        static::assertResponseIsSuccessful();

        $types = $crawler->filter('[data-test="log-type"]')->each(fn ($element) => trim($element->text()));
        static::assertSame(['Package transferred'], $types);
    }

    /**
     * A search by username finds every account that had that username.
     */
    public function testUserFilterReturnsTheHistoryOfTheNameAcrossEveryAccountThatHeldIt(): void
    {
        $this->givenARecycledUsername();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user' => 'handover']));
        static::assertResponseIsSuccessful();

        // the maintainer entries of both accounts, and the package created for the first one
        $types = $crawler->filter('[data-test="log-type"]')->each(fn ($element) => trim($element->text()));
        static::assertSame(['Maintainer added', 'Maintainer added', 'Package created'], $types);
    }

    /**
     * Each entry shows the account id, so the accounts can be told apart.
     */
    public function testEntriesRecordedUnderARecycledNameShowWhichAccountTheyAreAbout(): void
    {
        [$renamed, $successor] = $this->givenARecycledUsername();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user' => 'handover']));
        static::assertResponseIsSuccessful();

        // ->text() reads only the first row, so read all of them
        $details = implode(' ', $crawler->filter('td.audit-log-details')->each(fn ($node) => $node->text()));
        static::assertStringContainsString('(#'.$renamed->getId().')', $details);
        static::assertStringContainsString('(#'.$successor->getId().')', $details);
    }

    /**
     * The profile link searches by account, so it also finds entries from before a rename.
     */
    public function testProfileDeepLinkReturnsOneAccountsHistoryAcrossItsRename(): void
    {
        [$renamed, $successor] = $this->givenARecycledUsername();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user_id' => $renamed->getId()]));
        static::assertResponseIsSuccessful();

        // only the entries of this account, not of the account that has the username now
        static::assertCount(2, $crawler->filter('[data-test="log-type"]'));
        static::assertStringContainsString('(#'.$renamed->getId().')', $crawler->filter('td.audit-log-details')->text());

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user_id' => $successor->getId()]));
        static::assertCount(1, $crawler->filter('[data-test="log-type"]'));
        static::assertStringContainsString('(#'.$successor->getId().')', $crawler->filter('td.audit-log-details')->text());
    }

    /**
     * The account is both user and actor, so the index has two rows for the entry. The search must
     * return one. This is checked on the query, because Doctrine hides duplicates on the page, but they
     * would still make the count and the pages wrong.
     */
    public function testAccountSearchReturnsOneRowPerEntryWhenTheAccountIsBothUserAndActor(): void
    {
        $user = self::createUser('selfacting', 'selfacting@example.org');
        $this->store($user);
        $package = self::createPackage('acme/self-acting', 'https://github.com/acme/self-acting', maintainers: [$user]);
        $this->store($package);
        $this->store(AuditRecord::passwordChanged($user, $user));

        $this->runProjector();

        $qb = self::getService(PackageTransparencyLogRepository::class)->getQueryBuilderForPublicView();
        UserIdFilter::fromQuery(new InputBag(['user_id' => (string) $user->getId()]))->filter($qb);
        $ids = $qb->select('t.id')->getQuery()->getSingleColumnResult();

        static::assertNotEmpty($ids);
        static::assertSame(array_values(array_unique($ids)), $ids);
    }

    public function testAHandEditedAccountIdDegradesToAnUnfilteredList(): void
    {
        $this->givenProjectedLog();
        $this->givenLoggedInVisitor();

        $crawler = $this->client->request('GET', '/transparency-log?'.http_build_query(['user_id' => 'not-an-id']));

        static::assertResponseIsSuccessful();
        static::assertCount(1, $crawler->filter('[data-test="log-type"]'));
    }

    /**
     * Account A has the username `handover` and gets an entry. Then A is renamed, and account B takes
     * the username and gets an entry.
     *
     * @return array{\App\Entity\User, \App\Entity\User} the renamed account and the one that took its name
     */
    private function givenARecycledUsername(): array
    {
        $renamed = self::createUser('handover', 'handover-first@example.org');
        $admin = self::createUser('handoveradmin', 'handoveradmin@example.org', roles: ['ROLE_ADMIN']);
        $this->store($renamed, $admin);

        $package = self::createPackage('acme/handover', 'https://github.com/acme/handover', maintainers: [$renamed]);
        $this->store($package);
        $this->store(AuditRecord::maintainerAdded($package, $renamed, $admin));

        $renamed->setUsername('handover_old');
        $renamed->setUsernameCanonical('handover_old');
        $this->getEM()->flush();

        $successor = self::createUser('handover', 'handover-second@example.org');
        $this->store($successor);
        $this->store(AuditRecord::maintainerAdded($package, $successor, $admin));

        $this->runProjector();

        return [$renamed, $successor];
    }

    private function givenAPackageDeletedByAnAdmin(): void
    {
        $actor = self::createUser('onlyactor', 'onlyactor@example.org', roles: ['ROLE_ADMIN']);
        $this->store($actor);
        $package = self::createPackage('acme/actor-only', 'https://github.com/acme/actor-only');
        $this->store($package);
        $this->store(AuditRecord::packageDeleted($package, $actor));

        $this->runProjector();
    }

    private function givenProjectedLog(): void
    {
        $user = self::createUser('projected', 'projected@example.com');
        $this->store($user);
        $package = self::createPackage('vendor1/package1', 'https://github.com/vendor1/package1', maintainers: [$user]);
        $this->store($package);

        $this->runProjector();
    }

    /**
     * The log is only readable by logged in users, and the reader is deliberately not involved in any of
     * the events under test.
     */
    private function givenLoggedInVisitor(): void
    {
        $visitor = self::createUser('visitor', 'visitor@example.com');
        $this->store($visitor);

        $this->client->loginUser($visitor);
    }

    private function runProjector(): void
    {
        $tester = new CommandTester(self::getService(ProjectTransparencyLogCommand::class));
        $tester->execute(['--min-event-age-to-project' => '0']);
        $tester->assertCommandIsSuccessful();
    }
}
