<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Service;

use LBonnefond\TdSwap\Database\Schema;
use LBonnefond\TdSwap\Model\Campaign;
use LBonnefond\TdSwap\Repository\CampaignRepository;
use LBonnefond\TdSwap\Service\CampaignSnapshotService;
use LBonnefond\TdSwap\Service\ConflictException;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class CampaignSnapshotServiceTest extends TestCase
{
    private PDO $pdo;
    private CampaignSnapshotService $service;
    private string $workDir;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::create($this->pdo);

        $this->workDir = sys_get_temp_dir() . '/tdswap-test-' . uniqid();
        mkdir($this->workDir);

        $this->service = new CampaignSnapshotService(
            $this->pdo,
            new CampaignRepository($this->pdo),
            $this->workDir,
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->workDir);
    }

    /**
     * Crée une campagne closed avec 1 groupe + 1 étudiant, puis l'exporte.
     * Retourne ['id' => int, 'file' => chemin du snapshot].
     */
    private function makeClosedCampaignSnapshot(): array
    {
        $this->pdo->exec("INSERT INTO profiles (id, name) VALUES (1, 'Biologie')");
        $this->pdo->exec("INSERT INTO groups (id, name, capacity) VALUES (1, 'A1', 20)");
        $this->pdo->exec("INSERT INTO profile_groups (profile_id, group_id) VALUES (1, 1)");
        $this->pdo->exec("INSERT INTO students
            (id, student_number, surname, first_name, profile_id, initial_group_id)
            VALUES (1, '100001', 'Dupont', 'Jean', 1, 1)");

        $repo = new CampaignRepository($this->pdo);

        $campaign = $repo->create(new Campaign(
            null,
            'Campagne test',
            new DateTimeImmutable('2026-10-01 08:00:00'),
            new DateTimeImmutable('2026-10-01 09:00:00'),
            'closed',
            null,
            new DateTimeImmutable('2026-09-20 10:00:00'),
        ));

        $this->pdo->exec(
            'INSERT INTO campaign_groups (campaign_id, group_id, name, capacity)
             VALUES (' . $campaign->id . ', 1, \'A1\', 20)'
        );

        $this->pdo->prepare(
            'INSERT INTO campaign_students
                (campaign_id, student_id, student_number, surname, first_name,
                 profile_id, initial_group_id)
             VALUES (?, 1, \'100001\', \'Dupont\', \'Jean\', 1,
                 (SELECT id FROM campaign_groups WHERE campaign_id = ? AND group_id = 1))'
        )->execute([$campaign->id, $campaign->id]);

        $snap = $this->service->exportSnapshot($campaign->id);

        return ['id' => $campaign->id, 'file' => $snap['file']];
    }

    public function testExportProducesValidSnapshot(): void
    {
        $ctx = $this->makeClosedCampaignSnapshot();

        self::assertFileExists($ctx['file']);

        $check = new PDO('sqlite:' . $ctx['file']);

        self::assertSame(
            'ok',
            (string) $check->query('PRAGMA integrity_check')->fetchColumn()
        );

        $tables = $check
            ->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);

        foreach (
            ['campaigns', 'campaign_groups', 'campaign_students', 'requests', 'matches', 'swap_proposals']
            as $table
        ) {
            self::assertContains($table, $tables, 'Table absente du snapshot : ' . $table);
        }

        self::assertSame(
            1,
            (int) $check->query('SELECT COUNT(*) FROM campaign_students')->fetchColumn()
        );

        $check = null;
        unlink($ctx['file']);
    }

    public function testExportRefusesOpenCampaign(): void
    {
        $repo = new CampaignRepository($this->pdo);
        $campaign = $repo->create(new Campaign(
            null,
            'Campagne ouverte',
            new DateTimeImmutable('2026-10-01 08:00:00'),
            new DateTimeImmutable('2026-10-10 08:00:00'),
            'open',
            null,
            new DateTimeImmutable('2026-09-20 10:00:00'),
        ));

        $this->expectException(\DomainException::class);
        $this->service->exportSnapshot($campaign->id);
    }

    public function testExportRefusesUnknownCampaign(): void
    {
        $this->expectException(\DomainException::class);
        $this->service->exportSnapshot(999);
    }

    public function testImportValidSnapshotCreatesWorkingDb(): void
    {
        $ctx = $this->makeClosedCampaignSnapshot();

        $result = $this->service->importSnapshot($ctx['file']);

        self::assertFileExists($this->workDir . '/' . $result['file']);
        self::assertSame('Campagne test', $result['name']);
        self::assertSame(1, $result['students']);

        // La BDD « principale » (en mémoire) n'a pas été touchée :
        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn()
        );

        // La BDD de travail s'ouvre via createForCampaign :
        $pdo = \LBonnefond\TdSwap\Database\DatabaseFactory::createForCampaign(
            $this->workDir . '/' . $result['file']
        );
        self::assertSame(
            1,
            (int) $pdo->query('SELECT COUNT(*) FROM campaign_students')->fetchColumn()
        );
        $pdo = null;

        unlink($ctx['file']);
    }

    public function testImportRejectsNonSqliteFile(): void
    {
        $fake = $this->workDir . '/fake.db';
        file_put_contents($fake, 'ceci n est pas un fichier sqlite');

        $this->expectException(\DomainException::class);
        $this->service->importSnapshot($fake);
    }

    public function testImportRejectsIncompleteSchema(): void
    {
        $ctx = $this->makeClosedCampaignSnapshot();

        // On casse le snapshot en supprimant une table attendue
        $pdo = new PDO('sqlite:' . $ctx['file']);
        $pdo->exec('DROP TABLE matches');
        $pdo = null;

        $this->expectException(\DomainException::class);
        $this->service->importSnapshot($ctx['file']);
    }

    public function testImportDuplicateConflicts(): void
    {
        $ctx = $this->makeClosedCampaignSnapshot();
        $this->service->importSnapshot($ctx['file']);

        $this->expectException(ConflictException::class);
        $this->service->importSnapshot($ctx['file']);
    }

    public function testImportOverwriteReplacesExistingSnapshot(): void
    {
        $ctx = $this->makeClosedCampaignSnapshot();

        $first = $this->service->importSnapshot($ctx['file']);
        $second = $this->service->importSnapshot($ctx['file'], null, true);

        self::assertSame($first['file'], $second['file']);
        self::assertFileExists($this->workDir . '/' . $second['file']);
    }

    public function testDeleteCampaignPurgesAllTables(): void
    {
        $ctx = $this->makeClosedCampaignSnapshot();

        $result = $this->service->deleteCampaign($ctx['id'], 'Campagne test');

        self::assertSame(1, $result['students']);

        foreach (
            [
                'campaign_groups',
                'campaign_students',
                'campaign_profile_groups',
                'requests',
                'matches',
                'swap_proposals',
                'campaigns',
            ] as $table
        ) {
            self::assertSame(
                0,
                (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(),
                'Lignes restantes dans ' . $table
            );
        }
    }

    public function testDeleteRefusesOpenCampaign(): void
    {
        $repo = new CampaignRepository($this->pdo);
        $campaign = $repo->create(new Campaign(
            null,
            'A supprimer',
            new DateTimeImmutable('2026-10-01 08:00:00'),
            new DateTimeImmutable('2026-10-10 08:00:00'),
            'open',
            null,
            new DateTimeImmutable('2026-09-20 10:00:00'),
        ));

        $this->expectException(\DomainException::class);
        $this->service->deleteCampaign($campaign->id, 'A supprimer');
    }

    public function testDeleteRefusesWrongName(): void
    {
        $ctx = $this->makeClosedCampaignSnapshot();

        $this->expectException(\DomainException::class);
        $this->service->deleteCampaign($ctx['id'], 'nom différent');
    }

    public function testWorkingDbPathRejectsInvalidNames(): void
    {
        $this->expectException(\DomainException::class);
        $this->service->workingDbPath('../inexistant.db');
    }

    public function testWorkingDbPathRejectsMissingFile(): void
    {
        $this->expectException(\DomainException::class);
        $this->service->workingDbPath('inexistant.db');
    }
}