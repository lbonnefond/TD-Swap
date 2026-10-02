<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Import;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

final class AssignmentImporter
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * Importe la correspondance profils/groupes puis les affectations
     * initiales des étudiants dans une transaction unique.
     *
     * @return array{
     *     students: int,
     *     profiles: int,
     *     groups: int,
     *     profile_groups: int
     * }
     */
    public function import(
        string $correspondenceFile,
        string $studentsFile
    ): array {
        if (!is_file($correspondenceFile)) {
            throw new RuntimeException(
                "Fichier introuvable : {$correspondenceFile}"
            );
        }

        if (!is_file($studentsFile)) {
            throw new RuntimeException(
                "Fichier introuvable : {$studentsFile}"
            );
        }

        $this->pdo->beginTransaction();

        try {
            $correspondence = $this->readCorrespondence(
                $correspondenceFile
            );

            $this->insertCorrespondence($correspondence);

            $studentCount = $this->importStudents($studentsFile);

            $this->pdo->commit();

            return [
                'students' => $studentCount,
                'profiles' => (int) $this->pdo
                    ->query('SELECT COUNT(*) FROM profiles')
                    ->fetchColumn(),
                'groups' => (int) $this->pdo
                    ->query('SELECT COUNT(*) FROM groups')
                    ->fetchColumn(),
                'profile_groups' => (int) $this->pdo
                    ->query('SELECT COUNT(*) FROM profile_groups')
                    ->fetchColumn(),
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function readCorrespondence(string $filename): array
    {
        $spreadsheet = IOFactory::load($filename);
        $sheet = $spreadsheet->getSheetByName('Feuil2');

        if ($sheet === null) {
            throw new RuntimeException(
                "La feuille 'Feuil2' est introuvable."
            );
        }

        $rows = $sheet->toArray(null, true, true, true);

        if (
            ($rows[1]['A'] ?? null) !== 'Profil'
            || ($rows[1]['B'] ?? null) !== 'Groupes'
        ) {
            throw new RuntimeException(
                "En-tête inattendue dans 'Feuil2'."
            );
        }

        $result = [];

        foreach (array_slice($rows, 1) as $row) {
            $profile = trim((string) ($row['A'] ?? ''));
            $groupsText = trim((string) ($row['B'] ?? ''));

            if ($profile === '' && $groupsText === '') {
                continue;
            }

            if ($profile === '' || $groupsText === '') {
                throw new RuntimeException(
                    'Ligne de correspondance incomplète.'
                );
            }

            if (isset($result[$profile])) {
                throw new RuntimeException(
                    "Profil dupliqué : {$profile}"
                );
            }

            $groups = $this->parseGroupList($groupsText);

            if ($groups === []) {
                throw new RuntimeException(
                    "Aucun groupe pour le profil : {$profile}"
                );
            }

            $result[$profile] = $groups;
        }

        return $result;
    }

    /**
     * Les groupes sont normalement séparés par des virgules.
     * On accepte également la coquille "A8a A8b" présente dans
     * le fichier de test.
     *
     * @return list<string>
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

            /*
             * Cas normal : un seul groupe.
             */
            if (
                preg_match(
                    '/^(?:A\d+[abc]?|B\d+[ab]?|Bind|Santé\s+\d+)$/u',
                    $part
                )
            ) {
                $groups[] = $part;
                continue;
            }

            /*
             * Cas "A8a A8b" : deux groupes accolés sans virgule.
             */
            if (
                preg_match_all(
                    '/(?:A\d+[abc]?|B\d+[ab]?|Bind|Santé\s+\d+)/u',
                    $part,
                    $matches
                )
            ) {
                $reconstructed = trim(
                    preg_replace(
                        '/\s+/u',
                        ' ',
                        implode(' ', $matches[0])
                    )
                );

                if ($reconstructed === $part) {
                    foreach ($matches[0] as $group) {
                        $groups[] = trim($group);
                    }
                    continue;
                }
            }

            throw new RuntimeException(
                "Groupe non reconnu dans la correspondance : {$part}"
            );
        }

        return array_values(array_unique($groups));
    }

    /**
     * @param array<string, list<string>> $correspondence
     */
    private function insertCorrespondence(array $correspondence): void
    {
        $profileStmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO profiles (name) VALUES (:name)'
        );

        $profileIdStmt = $this->pdo->prepare(
            'SELECT id FROM profiles WHERE name = :name'
        );

        $groupStmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO groups (name, capacity)
         VALUES (:name, 0)'
        );

        $groupIdStmt = $this->pdo->prepare(
            'SELECT id FROM groups WHERE name = :name'
        );

        $linkStmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO profile_groups (profile_id, group_id) VALUES (:profile_id, :group_id)'
        );

        foreach ($correspondence as $profile => $groups) {
            $profileStmt->execute(['name' => $profile]);

            $profileIdStmt->execute(['name' => $profile]);
            $profileIdRaw = $profileIdStmt->fetchColumn();

            if ($profileIdRaw === false) {
                throw new RuntimeException(
                    "Profil introuvable après insertion : {$profile}"
                );
            }

            $profileId = (int) $profileIdRaw;

            foreach ($groups as $group) {
                $groupStmt->execute(['name' => $group]);

                $groupIdStmt->execute(['name' => $group]);
                $groupIdRaw = $groupIdStmt->fetchColumn();

                if ($groupIdRaw === false) {
                    throw new RuntimeException(
                        "Groupe introuvable après insertion : {$group}"
                    );
                }

                $groupId = (int) $groupIdRaw;

                $linkStmt->execute([
                    'profile_id' => $profileId,
                    'group_id' => $groupId,
                ]);
            }
        }
    }

    private function importStudents(string $filename): int
    {
        $spreadsheet = IOFactory::load($filename);
        $sheet = $spreadsheet->getSheetByName('Feuil1');

        if ($sheet === null) {
            throw new RuntimeException(
                "La feuille 'Feuil1' est introuvable."
            );
        }

        $rows = $sheet->toArray(null, true, true, true);

        $expectedHeader = [
            'N° étudiant',
            'Nom',
            'Prénom',
            'Profil',
            'Groupe',
        ];

        $header = [
            trim((string) ($rows[1]['A'] ?? '')),
            trim((string) ($rows[1]['B'] ?? '')),
            trim((string) ($rows[1]['C'] ?? '')),
            trim((string) ($rows[1]['D'] ?? '')),
            trim((string) ($rows[1]['E'] ?? '')),
        ];

        if ($header !== $expectedHeader) {
            throw new RuntimeException(
                "En-tête inattendue dans 'Feuil1'."
            );
        }

        $profileStmt = $this->pdo->prepare(
            'SELECT id FROM profiles WHERE name = :name'
        );

        $groupStmt = $this->pdo->prepare(
            'SELECT id FROM groups WHERE name = :name'
        );

        $allowedStmt = $this->pdo->prepare(
            'SELECT 1
             FROM profile_groups
             WHERE profile_id = :profile_id
               AND group_id = :group_id'
        );

        $studentStmt = $this->pdo->prepare(
            'INSERT INTO students
             (student_number, surname, first_name, profile_id, initial_group_id)
             VALUES
             (:student_number, :surname, :first_name,
              :profile_id, :initial_group_id)'
        );

        $capacityStmt = $this->pdo->prepare(
            'UPDATE groups
             SET capacity = capacity + 1
             WHERE id = :group_id'
        );

        $count = 0;
        $numbers = [];

        foreach (array_slice($rows, 1) as $row) {
            $number = trim((string) ($row['A'] ?? ''));
            $surname = trim((string) ($row['B'] ?? ''));
            $firstName = trim((string) ($row['C'] ?? ''));
            $profile = trim((string) ($row['D'] ?? ''));
            $group = trim((string) ($row['E'] ?? ''));

            if (
                $number === ''
                && $surname === ''
                && $firstName === ''
                && $profile === ''
                && $group === ''
            ) {
                continue;
            }

            if ($number === '' || $profile === '' || $group === '') {
                throw new RuntimeException(
                    "Affectation étudiante incomplète pour la ligne "
                    . ($count + 2)
                );
            }

            if (isset($numbers[$number])) {
                throw new RuntimeException(
                    "Numéro étudiant dupliqué : {$number}"
                );
            }

            $numbers[$number] = true;

            $profileStmt->execute(['name' => $profile]);
            $profileId = $profileStmt->fetchColumn();

            if ($profileId === false) {
                throw new RuntimeException(
                    "Profil inconnu pour l'étudiant {$number} : {$profile}"
                );
            }

            $groupStmt->execute(['name' => $group]);
            $groupId = $groupStmt->fetchColumn();

            if ($groupId === false) {
                throw new RuntimeException(
                    "Groupe inconnu pour l'étudiant {$number} : {$group}"
                );
            }

            $allowedStmt->execute([
                'profile_id' => $profileId,
                'group_id' => $groupId,
            ]);

            if ($allowedStmt->fetchColumn() === false) {
                throw new RuntimeException(
                    "Groupe {$group} non autorisé pour le profil "
                    . "{$profile} (étudiant {$number})"
                );
            }

            $studentStmt->execute([
                'student_number' => $number,
                'surname' => $surname,
                'first_name' => $firstName,
                'profile_id' => $profileId,
                'initial_group_id' => $groupId,
            ]);

            $capacityStmt->execute(['group_id' => $groupId]);

            $count++;
        }

        return $count;
    }
}
