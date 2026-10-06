<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Service;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class GroupFillerService
{
    /** @var list<list<list<int|string>>> graphe résiduel : [noeud] => [ [cible, capacite, indexInverse], ... ] */
    private array $graph = [];

    /**
     * Analyse les 2 fichiers, renvoie effectif étudiants + familles détectées.
     * @return array{studentCount:int, families:list<array{name:string, subGroups:list<string>}>}
     */
    public function detectFamilies(string $assignmentsFile, string $correspondenceFile): array
    {
        $students = $this->readAssignmentsFile($assignmentsFile);
        $correspondence = $this->readCorrespondenceFile($correspondenceFile);

        $familyMap = [];
        foreach ($correspondence as $groups) {
            foreach ($groups as $g) {
                $fam = $this->familyOfGroup($g);
                if (!isset($familyMap[$fam])) {
                    $familyMap[$fam] = [];
                }
                if (!in_array($g, $familyMap[$fam], true)) {
                    $familyMap[$fam][] = $g;
                }
            }
        }

        $families = [];
        $subGroups = [];
        foreach ($familyMap as $fam => $groups) {
            $families[] = ['name' => $fam, 'subGroups' => array_values($groups)];
            foreach ($groups as $g) {
                $subGroups[] = ['group' => $g, 'family' => $fam];
            }
        }
        usort($families, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        usort($subGroups, static fn(array $a, array $b): int => strnatcasecmp($a['group'], $b['group']));

        return ['studentCount' => count($students), 'families' => $families, 'subGroups' => $subGroups];
    }

    /**
     * Affectation proportionnelle par profil.
     * @param array<string,int> $capacities sous-groupe => effectif max
     * @return array{total:int, placed:int, unplaced:list<string>,
     *               familiesUsed:array<string,int>, matrix:array<string,array<string,int>>,
     *               matrixByFamily:array<string,array<string,int>>, allFamilyNames:list<string>,
     *               students:list<array{number:string,surname:string,first_name:string,profile:string}>,
     *               assignments:array<string,string>}
     */
    public function fill(string $assignmentsFile, string $correspondenceFile, array $capacities): array
    {
        $students = $this->readAssignmentsFile($assignmentsFile);
        $correspondence = $this->readCorrespondenceFile($correspondenceFile);

        // Invariant : chaque sous-groupe doit être rattaché à un seul profil
        $groupToProfile = [];
        foreach ($correspondence as $profile => $groups) {
            foreach ($groups as $g) {
                if (isset($groupToProfile[$g]) && $groupToProfile[$g] !== $profile) {
                    throw new \DomainException(
                        "Le sous-groupe {$g} est rattaché à plusieurs profils ({$groupToProfile[$g]} et {$profile}). "
                        . "Répartissez-le en sous-groupes distincts."
                    );
                }
                $groupToProfile[$g] = $profile;
            }
        }

        // Familles (affichage uniquement)
        $familyMap = [];
        foreach ($correspondence as $groups) {
            foreach ($groups as $g) {
                $fam = $this->familyOfGroup($g);
                if (!isset($familyMap[$fam])) {
                    $familyMap[$fam] = [];
                }
                if (!in_array($g, $familyMap[$fam], true)) {
                    $familyMap[$fam][] = $g;
                }
            }
        }
        $familyNames = array_keys($familyMap);
        $allFamilyNames = $familyNames;
        sort($allFamilyNames);

        // Affectation, profil par profil
        $assignments = [];
        $unplaced = [];
        foreach ($correspondence as $profile => $groups) {
            $profileStudents = array_values(array_filter(
                $students,
                static fn(array $s): bool => $s['profile'] === $profile
            ));
            $result = $this->fillProRata($profileStudents, $groups, $capacities);
            foreach ($result['assignments'] as $num => $g) {
                $assignments[$num] = $g;
            }
            $unplaced = array_merge($unplaced, $result['unplaced']);
        }

        // Matrice profil × groupe
        $matrix = [];
        foreach ($students as $s) {
            $g = $assignments[$s['number']] ?? null;
            if ($g !== null) {
                $matrix[$s['profile']][$g] = ($matrix[$s['profile']][$g] ?? 0) + 1;
            }
        }

        // Matrice profil × famille
        $matrixByFamily = [];
        foreach ($students as $s) {
            $g = $assignments[$s['number']] ?? null;
            if ($g !== null) {
                $fam = $this->familyOfGroup($g);
                $matrixByFamily[$s['profile']][$fam] = ($matrixByFamily[$s['profile']][$fam] ?? 0) + 1;
            }
        }

        // Occupation par famille
        $familiesUsed = array_fill_keys($familyNames, 0);
        foreach ($assignments as $g) {
            $familiesUsed[$this->familyOfGroup($g)]++;
        }

        return [
            'total' => count($students),
            'placed' => count($assignments),
            'unplaced' => $unplaced,
            'familiesUsed' => $familiesUsed,
            'matrix' => $matrix,
            'matrixByFamily' => $matrixByFamily,
            'allFamilyNames' => $allFamilyNames,
            'students' => $students,
            'assignments' => $assignments,
        ];
    }

    /**
     * Répartit les étudiants d'un profil entre ses sous-groupes, pro-rata à la capacité.
     * @param list<array{number:string}> $students
     * @param list<string> $groups
     * @param array<string,int> $capacities sous-groupe => capacité
     * @return array{assignments:array<string,string>, unplaced:list<string>}
     */
    private function fillProRata(array $students, array $groups, array $capacities): array
    {
        $n = count($students);
        if ($n === 0 || $groups === []) {
            return ['assignments' => [], 'unplaced' => array_map(static fn($s) => $s['number'], $students)];
        }

        $totalCap = 0;
        foreach ($groups as $g) {
            $totalCap += (int) ($capacities[$g] ?? 0);
        }
        if ($totalCap === 0) {
            return ['assignments' => [], 'unplaced' => array_map(static fn($s) => $s['number'], $students)];
        }

        $toPlace = min($n, $totalCap);

        // Part idéale de chaque groupe (proportionnelle à sa capacité)
        $base = [];
        $remainders = [];
        foreach ($groups as $g) {
            $cap = (int) ($capacities[$g] ?? 0);
            $ideal = $toPlace * $cap / $totalCap;
            $base[$g] = (int) floor($ideal);
            $remainders[$g] = $ideal - $base[$g];
        }

        // Plus gros restes : distribuer les places restantes
        $order = array_keys($remainders);
        usort($order, static function (string $a, string $b) use ($remainders): int {
            if ($remainders[$a] === $remainders[$b]) {
                return strcmp($a, $b);
            }
            return $remainders[$a] < $remainders[$b] ? 1 : -1;
        });
        $toDistribute = $toPlace - array_sum($base);
        foreach ($order as $g) {
            if ($toDistribute <= 0) {
                break;
            }
            if ($base[$g] < (int) ($capacities[$g] ?? 0)) {
                $base[$g]++;
                $toDistribute--;
            }
        }

        // Attribuer les étudiants (dans l'ordre du fichier) aux groupes
        $assignments = [];
        $queue = array_map(static fn($s) => $s['number'], $students);
        foreach ($groups as $g) {
            for ($i = 0; $i < $base[$g]; $i++) {
                if ($queue === []) {
                    break 2;
                }
                $assignments[array_shift($queue)] = $g;
            }
        }

        return ['assignments' => $assignments, 'unplaced' => $queue];
    }

    /**
     * Construit l'Excel (Feuil1 : N° étudiant / Nom / Prénom / Profil / Groupe).
     * @param list<array{number:string, surname:string, first_name:string, profile:string}> $students
     * @param array<string,string> $assignments numéro étudiant => groupe
     * @return string chemin du fichier généré
     */
    public function buildExcel(array $students, array $assignments): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Feuil1');

        $header = ['N° étudiant', 'Nom', 'Prénom', 'Profil', 'Groupe'];
        $sheet->fromArray($header, null, 'A1');

        $rowIdx = 2;
        foreach ($students as $s) {
            $group = $assignments[$s['number']] ?? '';
            $sheet->fromArray(
                [[$s['number'], $s['surname'], $s['first_name'], $s['profile'], $group]],
                null,
                'A' . $rowIdx
            );
            $rowIdx++;
        }

        foreach (['A' => 14, 'B' => 16, 'C' => 16, 'D' => 14, 'E' => 14] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        $file = sys_get_temp_dir() . '/tdswap-fill-' . uniqid() . '.xlsx';
        (new Xlsx($spreadsheet))->save($file);

        return $file;
    }

    /**
     * Construit l'Excel du tableau croisé Profil × Famille.
     * @param array<string,array<string,int>> $matrixByFamily profil => famille => effectif
     * @param list<string> $allFamilyNames liste ordonnée de toutes les familles
     * @return string chemin du fichier
     */
    public function buildMatrixExcel(array $matrixByFamily, array $allFamilyNames): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Effectifs');

        // En-tête
        $header = ['Profil'];
        foreach ($allFamilyNames as $fam) {
            $header[] = $fam;
        }
        $header[] = 'Total';
        $sheet->fromArray($header, null, 'A1');

        // Lignes par profil
        $sortedProfiles = array_keys($matrixByFamily);
        sort($sortedProfiles);

        $rowIdx = 2;
        foreach ($sortedProfiles as $profile) {
            $row = [$profile];
            $rowTotal = 0;
            foreach ($allFamilyNames as $fam) {
                $count = $matrixByFamily[$profile][$fam] ?? 0;
                $row[] = $count;
                $rowTotal += $count;
            }
            $row[] = $rowTotal;
            $sheet->fromArray([$row], null, 'A' . $rowIdx);
            $rowIdx++;
        }

        // Ligne des totaux par famille
        $totalsRow = ['Total'];
        $grandTotal = 0;
        foreach ($allFamilyNames as $fam) {
            $colTotal = 0;
            foreach ($matrixByFamily as $profileCounts) {
                $colTotal += $profileCounts[$fam] ?? 0;
            }
            $totalsRow[] = $colTotal;
            $grandTotal += $colTotal;
        }
        $totalsRow[] = $grandTotal;
        $sheet->fromArray([$totalsRow], null, 'A' . $rowIdx);

        // Largeurs
        $colIdx = 1;
        foreach (array_merge(['Profil'], $allFamilyNames, ['Total']) as $col) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $sheet->getColumnDimension($letter)->setWidth(max(8, strlen($col) + 2));
            $colIdx++;
        }

        $file = sys_get_temp_dir() . '/tdswap-matrix-' . uniqid() . '.xlsx';
        (new Xlsx($spreadsheet))->save($file);

        return $file;
    }

    /* ── Lecture des fichiers ─────────────────────────────────────── */

    /** @return list<array{number:string, surname:string, first_name:string, profile:string}> */
    private function readAssignmentsFile(string $filename): array
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filename);
        $sheet = $spreadsheet->getSheetByName('Feuil1');
        if ($sheet === null) {
            throw new \DomainException("La feuille 'Feuil1' est introuvable.");
        }
        $rows = $sheet->toArray(null, true, true, true);

        // L'en-tête est en ligne index 1 (2e ligne physique)
        $header = [
            trim((string) ($rows[1]['A'] ?? '')),
            trim((string) ($rows[1]['B'] ?? '')),
            trim((string) ($rows[1]['C'] ?? '')),
            trim((string) ($rows[1]['D'] ?? '')),
            trim((string) ($rows[1]['E'] ?? '')),
        ];
        if ($header !== ['N° étudiant', 'Nom', 'Prénom', 'Profil', 'Groupe']) {
            throw new \DomainException("En-tête inattendue dans 'Feuil1'.");
        }

        $students = [];
        foreach (array_slice($rows, 1) as $row) {
            $number = trim((string) ($row['A'] ?? ''));
            $surname = trim((string) ($row['B'] ?? ''));
            $firstName = trim((string) ($row['C'] ?? ''));
            $profile = trim((string) ($row['D'] ?? ''));

            if ($number === '' && $surname === '' && $firstName === '' && $profile === '') {
                continue;
            }
            if ($number === '' || $profile === '') {
                throw new \DomainException("Ligne étudiante incomplète : {$number}");
            }
            $students[] = [
                'number' => $number,
                'surname' => $surname,
                'first_name' => $firstName,
                'profile' => $profile,
            ];
        }

        return $students;
    }

    /** @return array<string, list<string>> profil => liste de sous-groupes */
    private function readCorrespondenceFile(string $filename): array
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filename);
        $sheet = $spreadsheet->getSheetByName('Feuil2');
        if ($sheet === null) {
            throw new \DomainException("La feuille 'Feuil2' est introuvable.");
        }
        $rows = $sheet->toArray(null, true, true, true);

        if (($rows[1]['A'] ?? null) !== 'Profil' || ($rows[1]['B'] ?? null) !== 'Groupes') {
            throw new \DomainException("En-tête inattendue dans 'Feuil2'.");
        }

        $result = [];
        foreach (array_slice($rows, 1) as $row) {
            $profile = trim((string) ($row['A'] ?? ''));
            $groupsText = trim((string) ($row['B'] ?? ''));
            if ($profile === '' && $groupsText === '') {
                continue;
            }
            if ($profile === '' || $groupsText === '') {
                throw new \DomainException('Ligne de correspondance incomplète.');
            }
            if (isset($result[$profile])) {
                throw new \DomainException("Profil dupliqué : {$profile}");
            }
            $groups = $this->parseGroupList($groupsText);
            if ($groups === []) {
                throw new \DomainException("Aucun groupe pour le profil : {$profile}");
            }
            $result[$profile] = $groups;
        }

        return $result;
    }

    /** @return list<string> */
    private function parseGroupList(string $text): array
    {
        $parts = preg_split('/\s*,\s*/', $text) ?: [];
        $groups = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-zÀ-ÿ][A-Za-z0-9À-ÿ\s-]{0,29}$/u', $part)) {
                throw new \DomainException("Groupe non reconnu : {$part}");
            }
            $groups[] = $part;
        }
        return array_values(array_unique($groups));
    }

    /** La famille = ce qui est avant le premier tiret (sinon le nom entier). */
    private function familyOfGroup(string $g): string
    {
        $pos = strpos($g, '-');
        return $pos === false ? $g : substr($g, 0, $pos);
    }

    /* ── Flux maximal (Edmonds-Karp) ──────────────────────────────── */

    private function addEdge(int $u, int $v, int $cap): void
    {
        $fwdIndex = count($this->graph[$u]);
        $revIndex = count($this->graph[$v]);
        $this->graph[$u][] = [$v, $cap, $revIndex];
        $this->graph[$v][] = [$u, 0, $fwdIndex];
    }

    private function maxFlow(int $s, int $t): int
    {
        $n = count($this->graph);
        $flow = 0;

        while (true) {
            $parent = array_fill(0, $n, -1);
            $parentEdge = array_fill(0, $n, -1);
            $visited = array_fill(0, $n, false);
            $visited[$s] = true;
            $queue = [$s];
            $found = false;

            while ($queue !== []) {
                $u = array_shift($queue);
                foreach ($this->graph[$u] as $ei => $edge) {
                    $v = (int) $edge[0];
                    $cap = (int) $edge[1];
                    if (!$visited[$v] && $cap > 0) {
                        $visited[$v] = true;
                        $parent[$v] = $u;
                        $parentEdge[$v] = $ei;
                        if ($v === $t) {
                            $found = true;
                            break 2;
                        }
                        $queue[] = $v;
                    }
                }
            }

            if (!$found) {
                break;
            }

            $bottleneck = PHP_INT_MAX;
            $v = $t;
            while ($v !== $s) {
                $u = $parent[$v];
                $ei = $parentEdge[$v];
                $bottleneck = min($bottleneck, (int) $this->graph[$u][$ei][1]);
                $v = $u;
            }

            $v = $t;
            while ($v !== $s) {
                $u = $parent[$v];
                $ei = $parentEdge[$v];
                $this->graph[$u][$ei][1] -= $bottleneck;
                $rev = (int) $this->graph[$u][$ei][2];
                $this->graph[$v][$rev][1] += $bottleneck;
                $v = $u;
            }

            $flow += $bottleneck;
        }

        return $flow;
    }
}