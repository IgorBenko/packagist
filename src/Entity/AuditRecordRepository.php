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

namespace App\Entity;

use App\Audit\VersionDeletionReason;
use App\Log\AuditLogEventType;
use App\Service\AuditRecordsManager;
use App\Util\IpAddress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<AuditRecord>
 */
class AuditRecordRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly AuditRecordsManager $auditRecordsManager,
        private readonly PackageTransparencyLogQueueRepository $transparencyLogQueue,
    ) {
        parent::__construct($registry, AuditRecord::class);
    }

    /**
     * @return list<AuditRecord>
     */
    public function findForFilterListEntry(string $publicId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.type IN (:types)')
            ->andWhere("JSON_EXTRACT(a.attributes, '$.entry.public_id') = :publicId")
            ->setParameter('types', [
                AuditLogEventType::FilterListEntryAdded->value,
                AuditLogEventType::FilterListEntryDeleted->value,
                AuditLogEventType::FilterListEntryDisabled->value,
                AuditLogEventType::FilterListEntryEnabled->value,
                AuditLogEventType::FilterListEntryEdited->value,
            ])
            ->setParameter('publicId', $publicId)
            ->orderBy('a.datetime', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The most recent manual admin moderation actions for the admin dashboard. Account/package
     * freezes and user deletions are always admin-initiated; version soft-deletes and recoveries are
     * only included when the (previous) deletion reason is an admin one (Hidden / DeletedByAdmin) —
     * maintainer version pulls and the Updater's automatic missing-version handling are excluded.
     *
     * @return list<AuditRecord>
     */
    public function getRecentAdminModeration(int $limit): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.type IN (:alwaysTypes)')
            ->orWhere("(a.type = :softDeleted AND JSON_EXTRACT(a.attributes, '$.reason') IN (:adminVersionReasons))")
            ->orWhere("(a.type = :recovered AND JSON_EXTRACT(a.attributes, '$.previousReason') IN (:adminVersionReasons))")
            ->setParameter('alwaysTypes', [
                AuditLogEventType::UserFrozen->value,
                AuditLogEventType::UserUnfrozen->value,
                AuditLogEventType::UserDeleted->value,
                AuditLogEventType::PackageFrozen->value,
                AuditLogEventType::PackageUnfrozen->value,
            ])
            ->setParameter('softDeleted', AuditLogEventType::VersionSoftDeleted->value)
            ->setParameter('recovered', AuditLogEventType::VersionRecovered->value)
            ->setParameter('adminVersionReasons', [
                VersionDeletionReason::DeletedByAdmin->value,
                VersionDeletionReason::Hidden->value,
            ])
            ->orderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<Ulid> $ids
     *
     * @return array<string, AuditRecord> map of ULID string => record (only ids that exist)
     */
    public function getRecordsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Ulid $id): string => $id->toBinary(), $ids), ArrayParameterType::BINARY);

        $records = [];
        /** @var AuditRecord $record */
        foreach ($qb->getQuery()->getResult() as $record) {
            $records[(string) $record->id] = $record;
        }

        return $records;
    }

    /**
     * Performs a direct insert not requiring usage of the ORM so it can be used within ORM lifecycle listeners
     *
     * The transparency-log queue row is this record's outbox entry: it has to commit together with
     * the audit_log row, or the record exists and can never be projected. Callers inside an ORM
     * flush are already in a transaction (DBAL turns this one into a savepoint), but some, like
     * {@see \App\Security\TwoFactorAuthManager}, call this in autocommit, so own the transaction here.
     */
    public function insert(AuditRecord $record): void
    {
        $this->auditRecordsManager->enrichWithClientIP($record);

        $connection = $this->getEntityManager()->getConnection();
        $connection->beginTransaction();

        try {
            $this->insertRecord($record);
            $this->indexSearchTerms($record);
            $this->transparencyLogQueue->enqueue($record);
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    private function insertRecord(AuditRecord $record): void
    {
        $this->getEntityManager()->getConnection()->insert('audit_log', [
            'id' => $record->id,
            'datetime' => $record->datetime,
            'type' => $record->type->value,
            'attributes' => $record->attributes,
            'actorId' => $record->actorId,
            'vendor' => $record->vendor,
            'packageId' => $record->packageId,
            'userId' => $record->userId,
            'ip' => IpAddress::stringToBinary($record->ip),
            'organizationId' => $record->organizationId,
        ], [
            'id' => UlidType::NAME,
            'datetime' => Types::DATETIME_IMMUTABLE,
            'attributes' => Types::JSON,
            'organizationId' => UlidType::NAME,
        ]);
    }

    /**
     * Denormalizes the record's searchable names into audit_log_search so the transparency-log
     * user/actor/package filters can do an indexed lookup instead of scanning the JSON attributes.
     *
     * Called from {@see insert()} for the direct-insert path and from the postPersist listener for
     * the ORM path (the two paths are disjoint). Idempotent via INSERT IGNORE on the primary key.
     */
    public function indexSearchTerms(AuditRecord $record): void
    {
        $terms = $record->getSearchTerms();
        if (\count($terms) === 0) {
            return;
        }

        $idBinary = $record->id->toBinary();
        $placeholders = [];
        $params = [];
        foreach ($terms as $term) {
            $placeholders[] = '(?, ?, ?)';
            $params[] = $idBinary;
            $params[] = $term['type'];
            $params[] = $term['name'];
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT IGNORE INTO audit_log_search (auditLogId, type, name) VALUES '.implode(', ', $placeholders),
            $params,
        );
    }
}
