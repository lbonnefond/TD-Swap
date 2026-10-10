<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Service;

use LBonnefond\TdSwap\Model\Campaign;
use LBonnefond\TdSwap\Repository\CampaignRepository;
use PDO;
use Throwable;

final class CampaignService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CampaignRepository $campaignRepository,
    ) {
    }

    public function createCampaign(Campaign $campaign): Campaign
    {
        $this->pdo->beginTransaction();

        try {
            $createdCampaign = $this->campaignRepository->create(
                $campaign
            );

            $this->pdo->commit();

            return $createdCampaign;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function importData(int $campaignId, string $assignmentsFile, string $correspondenceFile): array
    {
        $campaign = $this->requireCampaign($campaignId);

        if ($campaign->status !== 'draft') {
            throw new \DomainException(
                "Seule une campagne draft peut recevoir un import."
            );
        }

        $this->pdo->beginTransaction();

        try {
            $correspondence = $this->readCorrespondence($correspondenceFile);
            $studentCount = $this->importCampaign(
                $campaignId,
                $correspondence,
                $assignmentsFile
            );

            $this->pdo->commit();

            return [
                'students' => $studentCount,
                'profiles' => count($correspondence),
            ];
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @return array<string, list<string>> profil => liste de groupes
     */
    private function readCorrespondence(string $filename): array
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filename);
        $sheet = $spreadsheet->getSheetByName('Feuil2');

        if ($sheet === null) {
            throw new \RuntimeException("La feuille 'Feuil2' est introuvable.");
        }

        $rows = $sheet->toArray(null, true, true, true);

        if (
            ($rows[1]['A'] ?? null) !== 'Profil'
            || ($rows[1]['B'] ?? null) !== 'Groupes'
        ) {
            throw new \RuntimeException("En-tête inattendue dans 'Feuil2'.");
        }

        $result = [];

        foreach (array_slice($rows, 1) as $row) {
            $profile = trim((string) ($row['A'] ?? ''));
            $groupsText = trim((string) ($row['B'] ?? ''));

            if ($profile === '' && $groupsText === '') {
                continue;
            }

            if ($profile === '' || $groupsText === '') {
                throw new \RuntimeException('Ligne de correspondance incomplète.');
            }

            if (isset($result[$profile])) {
                throw new \RuntimeException("Profil dupliqué : {$profile}");
            }

            $groups = $this->parseGroupList($groupsText);

            if ($groups === []) {
                throw new \RuntimeException("Aucun groupe pour le profil : {$profile}");
            }

            $result[$profile] = $groups;
        }

        return $result;
    }

    /**
     * @return list
     */
    private function parseGroupList(string $text): array
    {
        $parts = preg_split('/\s*,\s*/', $text) ?: [];
        $groups = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            // Permissif : lettre majuscule, puis 0-28 caractères alphanumériques/espaces/underscores
            if (!preg_match('/^[A-Za-zÀ-ÿ][A-Za-z0-9À-ÿ\s_-]{0,29}$/u', $part)) {
                throw new \RuntimeException("Groupe non reconnu : {$part}");
            }
            $groups[] = $part;
        }

        return array_values(array_unique($groups));
    }

    /**
     * @param array<string, list<string>> $correspondence
     */
    private function importCampaign(
        int $campaignId,
        array $correspondence,
        string $assignmentsFile
    ): int {
        // ─── 1. Créer les campaign_groups ───
        $groupStmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO groups (name, capacity) VALUES (:name, 0)'
        );
        $groupIdStmt = $this->pdo->prepare(
            'SELECT id FROM groups WHERE name = :name'
        );
        $cgStmt = $this->pdo->prepare(
            'INSERT INTO campaign_groups (campaign_id, group_id, name, capacity)
             VALUES (?, ?, ?, 0)'
        );

        $campaignGroupId = []; // group_name => campaign_group_id

        foreach ($correspondence as $profile => $groups) {
            foreach ($groups as $groupName) {
                if (isset($campaignGroupId[$groupName])) {
                    continue;
                }

                $groupStmt->execute(['name' => $groupName]);
                $groupIdStmt->execute(['name' => $groupName]);
                $globalGroupId = (int) $groupIdStmt->fetchColumn();

                $cgStmt->execute([$campaignId, $globalGroupId, $groupName]);
                $campaignGroupId[$groupName] = (int) $this->pdo->lastInsertId();
            }
        }

        // ─── 2. Créer les campaign_profile_groups ───
        $profileStmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO profiles (name) VALUES (:name)'
        );
        $profileIdStmt = $this->pdo->prepare(
            'SELECT id FROM profiles WHERE name = :name'
        );
        $cpgStmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO campaign_profile_groups
             (campaign_id, profile_id, campaign_group_id)
             VALUES (?, ?, ?)'
        );

        foreach ($correspondence as $profile => $groups) {
            $profileStmt->execute(['name' => $profile]);
            $profileIdStmt->execute(['name' => $profile]);
            $profileId = (int) $profileIdStmt->fetchColumn();

            foreach ($groups as $groupName) {
                $cpgStmt->execute([
                    $campaignId,
                    $profileId,
                    $campaignGroupId[$groupName],
                ]);
            }
        }

        // ─── 3. Importer les étudiants depuis Feuil1 ───
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($assignmentsFile);
        $sheet = $spreadsheet->getSheetByName('Feuil1');

        if ($sheet === null) {
            throw new \RuntimeException("La feuille 'Feuil1' est introuvable.");
        }

        $rows = $sheet->toArray(null, true, true, true);

        $expectedHeader = ['N° étudiant', 'Nom', 'Prénom', 'Profil', 'Groupe'];
        $header = [
            trim((string) ($rows[1]['A'] ?? '')),
            trim((string) ($rows[1]['B'] ?? '')),
            trim((string) ($rows[1]['C'] ?? '')),
            trim((string) ($rows[1]['D'] ?? '')),
            trim((string) ($rows[1]['E'] ?? '')),
        ];

        if ($header !== $expectedHeader) {
            throw new \RuntimeException("En-tête inattendue dans 'Feuil1'.");
        }

        $studentStmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO students
             (student_number, surname, first_name, profile_id, initial_group_id)
             VALUES (:student_number, :surname, :first_name, :profile_id, :initial_group_id)'
        );

        $csStmt = $this->pdo->prepare(
            'INSERT INTO campaign_students
             (campaign_id, student_id, student_number, surname, first_name, profile_id, initial_group_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $capacityStmt = $this->pdo->prepare(
            'UPDATE campaign_groups SET capacity = capacity + 1 WHERE id = ?'
        );

        $count = 0;

        foreach (array_slice($rows, 1) as $row) {
            $number = trim((string) ($row['A'] ?? ''));
            $surname = trim((string) ($row['B'] ?? ''));
            $firstName = trim((string) ($row['C'] ?? ''));
            $profile = trim((string) ($row['D'] ?? ''));
            $group = trim((string) ($row['E'] ?? ''));

            if (
                $number === '' && $surname === '' && $firstName === ''
                && $profile === '' && $group === ''
            ) {
                continue;
            }

            if ($number === '' || $profile === '' || $group === '') {
                throw new \RuntimeException(
                    "Affectation étudiante incomplète : {$number}"
                );
            }

            // Vérifier l'éligibilité
            $eligible = $correspondence[$profile] ?? [];
            if (!in_array($group, $eligible, true)) {
                throw new \RuntimeException(
                    "Groupe {$group} non autorisé pour le profil {$profile} (étudiant {$number})"
                );
            }

            // Upsert dans le référentiel global
            $profileIdStmt->execute(['name' => $profile]);
            $profileId = (int) $profileIdStmt->fetchColumn();

            $groupIdStmt->execute(['name' => $group]);
            $globalGroupId = (int) $groupIdStmt->fetchColumn();

            $studentStmt->execute([
                'student_number' => $number,
                'surname' => $surname,
                'first_name' => $firstName,
                'profile_id' => $profileId,
                'initial_group_id' => $globalGroupId,
            ]);

            // Récupérer l'id de l'étudiant
            $findStudent = $this->pdo->prepare(
                'SELECT id FROM students WHERE student_number = :num'
            );
            $findStudent->execute(['num' => $number]);
            $studentId = (int) $findStudent->fetchColumn();

            // Insérer dans campaign_students
            $csStmt->execute([
                $campaignId,
                $studentId,
                $number,
                $surname,
                $firstName,
                $profileId,
                $campaignGroupId[$group],
            ]);

            // Incrémenter la capacité du groupe de campagne
            $capacityStmt->execute([$campaignGroupId[$group]]);

            $count++;
        }

        return $count;
    }

    public function openCampaign(
        int $campaignId,
        \DateTimeImmutable $now,
    ): Campaign {
        $campaign = $this->requireCampaign($campaignId);

        if ($campaign->status !== 'draft') {
            throw new \DomainException(
                'Seule une campagne draft peut être ouverte.'
            );
        }

        if ($now < $campaign->startsAt) {
            throw new \DomainException(
                'La campagne ne peut pas être ouverte avant sa date de début.'
            );
        }

        return $this->campaignRepository->updateStatus(
            $campaignId,
            'open',
        );
    }

    public function closeCampaign(
        int $campaignId,
        \DateTimeImmutable $now,
        bool $force = false,
    ): Campaign {
        $campaign = $this->requireCampaign($campaignId);

        if ($campaign->status !== 'open') {
            throw new \DomainException(
                'Seule une campagne open peut être fermée.'
            );
        }

        if (!$force && $now < $campaign->closesAt) {
            throw new \DomainException(
                'La campagne ne peut pas être fermée avant sa date de fin.'
            );
        }

        return $this->campaignRepository->updateStatus(
            $campaignId,
            'closed',
        );
    }

    public function markAsMatched(
        int $campaignId,
        \DateTimeImmutable $now,
    ): Campaign {
        $campaign = $this->requireCampaign($campaignId);

        if ($campaign->status !== 'closed') {
            throw new \DomainException(
                'Seule une campagne closed peut être marquée comme matched.'
            );
        }

        return $this->campaignRepository->updateStatus(
            $campaignId,
            'matched',
            $now,
        );
    }

    private function requireCampaign(int $campaignId): Campaign
    {
        $campaign = $this->campaignRepository->findById($campaignId);

        if ($campaign === null) {
            throw new \RuntimeException(
                sprintf('Campagne introuvable : %d', $campaignId)
            );
        }

        return $campaign;
    }
}
