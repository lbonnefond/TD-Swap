<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Service;

use LBonnefond\TdSwap\Repository\CampaignRepository;
use PDO;
use Throwable;

final class CampaignSnapshotService
{
    private const EXPECTED_TABLES = [
        'campaigns',
        'profiles',
        'groups',
        'students',
        'profile_groups',
        'campaign_groups',
        'campaign_students',
        'campaign_profile_groups',
        'requests',
        'matches',
        'swap_proposals',
    ];

    private string $workDir;

    public function __construct(
        private PDO $pdo,
        private CampaignRepository $campaignRepository,
        ?string $workDir = null,
    ) {
        $this->workDir = $workDir ?? dirname(__DIR__, 2) . '/data/campaignes';
    }

    /* ── EXPORT : VACUUM INTO d'un snapshot de campagne fermée ── */

    public function exportSnapshot(int $campaignId): array
    {
        $campaign = $this->campaignRepository->findById($campaignId);

        if ($campaign === null) {
            throw new \DomainException('Campagne introuvable.');
        }

        if (!in_array($campaign->status, ['closed', 'matched'], true)) {
            throw new \DomainException(
                'Seule une campagne fermée (closed/matched) peut être exportée en snapshot.'
            );
        }

        $tmpFile = sys_get_temp_dir()
            . '/tdswap-snapshot-' . $campaignId . '-' . uniqid() . '.db';

        $this->pdo->exec('VACUUM INTO ' . $this->pdo->quote($tmpFile));

        $check = new PDO('sqlite:' . $tmpFile);
        $result = $check->query('PRAGMA integrity_check')->fetchColumn();
        $check = null;

        if ($result !== 'ok') {
            unlink($tmpFile);
            throw new \RuntimeException('Snapshot corrompu (integrity_check).');
        }

        return [
            'file' => $tmpFile,
            'filename' => 'td-swap-camp-' . $campaignId . '-' . date('Y-m-d') . '.db',
            'name' => $campaign->name,
            'students' => $this->countCampaignRows($campaignId, 'campaign_students'),
        ];
    }

    /* ── IMPORT : snapshot → BDD de travail séparée ── */

    public function importSnapshot(
        string $tmpName,
        ?int $targetCampaignId = null,
        bool $overwrite = false,
    ): array {
        if (!is_dir($this->workDir) && !mkdir($this->workDir, 0755, true)) {
            throw new \RuntimeException('Impossible de créer le dossier ' . $this->workDir);
        }

        // 1. En-tête SQLite (16 premiers octets)
        $fh = fopen($tmpName, 'rb');
        $header = $fh !== false ? fread($fh, 16) : false;
        if ($fh !== false) {
            fclose($fh);
        }

        if (substr((string) $header, 0, 15) !== 'SQLite format 3') {
            throw new \DomainException('Ce n\'est pas un fichier SQLite valide.');
        }

        // 2. Copie temporaire + vérification d'intégrité
        $tmpCopy = $this->workDir . '/import-tmp-' . uniqid() . '.db';

        if (!copy($tmpName, $tmpCopy)) {
            throw new \RuntimeException('Copie du fichier impossible.');
        }

        $check = new PDO('sqlite:' . $tmpCopy);

        if ($check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            $check = null;
            unlink($tmpCopy);
            throw new \DomainException('Snapshot corrompu (integrity_check).');
        }

        // 3. Check de schéma : toutes les tables attendues doivent exister
        $missing = [];
        $stmt = $check->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?"
        );
        foreach (self::EXPECTED_TABLES as $table) {
            $stmt->execute([$table]);
            if ($stmt->fetchColumn() === false) {
                $missing[] = $table;
            }
        }

        if ($missing !== []) {
            $check = null;
            unlink($tmpCopy);
            throw new \DomainException(
                'Schéma incomplet, table(s) manquante(s) : ' . implode(', ', $missing)
            );
        }

        // 4. Campagne cible (celle demandée, ou la seule dans le snapshot)
        $camRow = null;

        if ($targetCampaignId !== null) {
            $stmt = $check->prepare('SELECT id, name FROM campaigns WHERE id = ?');
            $stmt->execute([$targetCampaignId]);
            $camRow = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $camRow = $check->query(
                'SELECT id, name FROM campaigns ORDER BY id LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
        }

        if ($camRow === false) {
            $check = null;
            unlink($tmpCopy);
            throw new \DomainException('Aucune campagne valide dans ce snapshot.');
        }

        $cid = (int) $camRow['id'];

        $count = $check->prepare(
            'SELECT COUNT(*) FROM campaign_students WHERE campaign_id = ?'
        );
        $count->execute([$cid]);
        $nStudents = (int) $count->fetchColumn();

        $count = $check->prepare(
            'SELECT COUNT(*) FROM campaign_groups WHERE campaign_id = ?'
        );
        $count->execute([$cid]);
        $nGroups = (int) $count->fetchColumn();

        if ($nStudents === 0 || $nGroups === 0) {
            $check = null;
            unlink($tmpCopy);
            throw new \DomainException(
                'Campagne ' . $cid . ' vide (pas d\'étudiants ou de groupes).'
            );
        }

        $count = $check->prepare('SELECT COUNT(*) FROM requests WHERE campaign_id = ?');
        $count->execute([$cid]);
        $nRequests = (int) $count->fetchColumn();
        $check = null;

        // 5. Conflit de nom
        $slug = strtolower(
            trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $camRow['name']))
        );
        $slug = substr($slug, 0, 40);
        if ($slug === '') {
            $slug = 'camp';
        }

        $targetFile = $this->workDir . '/' . $cid . '-' . $slug . '-' . date('Y-m-d') . '.db';

        if (file_exists($targetFile)) {
            if (!$overwrite) {
                unlink($tmpCopy);
                throw new ConflictException(
                    'Un snapshot existe déjà : ' . basename($targetFile)
                );
            }
            unlink($targetFile);
        }

        if (!rename($tmpCopy, $targetFile)) {
            unlink($tmpCopy);
            throw new \RuntimeException('Enregistrement de la BDD de travail impossible.');
        }

        return [
            'file' => basename($targetFile),
            'name' => (string) $camRow['name'],
            'students' => $nStudents,
            'requests' => $nRequests,
        ];
    }

    /* ── SUPPRESSION en cascade d'une campagne ── */

    public function deleteCampaign(int $campaignId, string $confirmName): array
    {
        $campaign = $this->campaignRepository->findById($campaignId);

        if ($campaign === null) {
            throw new \DomainException('Campagne introuvable.');
        }

        if (!in_array($campaign->status, ['closed', 'matched'], true)) {
            throw new \DomainException('Seule une campagne fermée peut être supprimée.');
        }

        if (!hash_equals((string) $campaign->name, $confirmName)) {
            throw new \DomainException('Nom de campagne non confirmé.');
        }

        $this->pdo->beginTransaction();

        try {
            $nStudents = $this->countCampaignRows($campaignId, 'campaign_students');
            $nRequests = $this->countCampaignRows($campaignId, 'requests');

            $this->pdo->exec('DELETE FROM matches WHERE campaign_id = ' . $campaignId);
            $this->pdo->exec('DELETE FROM swap_proposals WHERE campaign_id = ' . $campaignId);
            $this->pdo->exec('DELETE FROM requests WHERE campaign_id = ' . $campaignId);
            $this->pdo->exec(
                'DELETE FROM campaign_profile_groups WHERE campaign_id = ' . $campaignId
            );
            $this->pdo->exec('DELETE FROM campaign_students WHERE campaign_id = ' . $campaignId);
            $this->pdo->exec('DELETE FROM campaign_groups WHERE campaign_id = ' . $campaignId);
            $this->pdo->exec('DELETE FROM campaigns WHERE id = ' . $campaignId);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['students' => $nStudents, 'requests' => $nRequests];
    }

    /* ── BDD de travail : listing / chemin / suppression ── */

    public function listWorkingDbs(): array
    {
        $files = [];

        if (is_dir($this->workDir)) {
            foreach (glob($this->workDir . '/*.db') ?: [] as $f) {
                if (str_starts_with(basename($f), 'import-tmp-')) {
                    continue;
                }
                $files[] = [
                    'file' => basename($f),
                    'size' => filesize($f),
                    'modified' => date(DATE_ATOM, (int) filemtime($f)),
                ];
            }

            usort(
                $files,
                static fn(array $a, array $b): int => strcasecmp($b['file'], $a['file'])
            );
        }

        return $files;
    }

    /** Retourne le chemin absolu d'une BDD de travail, ou lève une exception. */
    public function workingDbPath(string $file): string
    {
        if ($file === '' || strpbrk($file, '/\\') !== false || str_contains($file, '..')) {
            throw new \DomainException('Nom de fichier invalide.');
        }

        $path = $this->workDir . '/' . $file;

        if (!is_file($path)) {
            throw new \DomainException('BDD de travail introuvable : ' . $file);
        }

        return $path;
    }

    public function removeWorkingDb(string $file): void
    {
        $path = $this->workingDbPath($file);
        if (!unlink($path)) {
            throw new \RuntimeException('Suppression impossible.');
        }
    }

    private function countCampaignRows(int $campaignId, string $table): int
    {
        // $table est toujours une constante interne, jamais un paramètre utilisateur
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE campaign_id = ' . $campaignId
        )->fetchColumn();
    }
}