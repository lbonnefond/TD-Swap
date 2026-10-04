<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use LBonnefond\TdSwap\Database\DatabaseFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/export-results.php <campaign_id>\n");
    exit(1);
}

// Parser --db
$dbPath = null;
$campaignId = null;
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--db' && isset($argv[$i + 1])) {
        $dbPath = $argv[++$i];
    } elseif ($argv[$i] !== '') {
        $campaignId = $argv[$i];
    }
}
if ($campaignId === null) {
    fwrite(STDERR, "Usage : php run-matching.php <campaignId> [--db <chemin>]\n");
    exit(1);
}
$campaignId = (int) $campaignId;

// Ouvre la BDD : principale par défaut, ou la BDD de travail si --db
if ($dbPath !== null) {
    $pdo = DatabaseFactory::createForCampaign($dbPath);
    echo "BDD de travail : {$dbPath}\n";
} else {
    $pdo = DatabaseFactory::create(__DIR__ . '/../storage/td-swap.sqlite');
}

// ─── 1. Récupérer tous les étudiants de la campagne ───────────────
$stmt = $pdo->prepare(
    'SELECT cs.student_number, cs.surname, cs.first_name,
            p.name AS profile,
            cg.name AS current_group
     FROM campaign_students cs
     JOIN profiles p ON p.id = cs.profile_id
     JOIN campaign_groups cg ON cg.id = cs.initial_group_id AND cg.campaign_id = ?
     WHERE cs.campaign_id = ?
     ORDER BY cs.student_number'
);
$stmt->execute([$campaignId, $campaignId]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($students === []) {
    fwrite(STDERR, "Aucun étudiant dans la campagne {$campaignId}.\n");
    exit(1);
}

// ─── 2. Résultat du matching ─────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT cs.student_number, cgt.name AS new_group, m.preference_rank
     FROM matches m
     JOIN campaign_students cs ON cs.id = m.student_id AND cs.campaign_id = m.campaign_id
     JOIN campaign_groups cgt ON cgt.id = m.to_group_id AND cgt.campaign_id = m.campaign_id
     WHERE m.campaign_id = ?'
);
$stmt->execute([$campaignId]);

$matchByNumber = [];
$matchRankByNumber = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $matchByNumber[$row['student_number']] = $row['new_group'];
    $matchRankByNumber[$row['student_number']] = (int) $row['preference_rank'];
}

// ─── 3. Swaps confirmés (dédupliqués) ────────────────────────────
$stmt = $pdo->prepare(
    'SELECT sa.student_number AS num_a, sa.surname AS surname_a, sa.first_name AS first_name_a,
            ga.name AS group_a,
            sb.student_number AS num_b, sb.surname AS surname_b, sb.first_name AS first_name_b,
            gb.name AS group_b
     FROM swap_proposals sp_a
     JOIN campaign_students sa ON sa.id = sp_a.student_id AND sa.campaign_id = sp_a.campaign_id
     JOIN campaign_students sb ON sb.id = sp_a.target_student_id AND sb.campaign_id = sp_a.campaign_id
     JOIN campaign_groups ga ON ga.id = sa.initial_group_id AND ga.campaign_id = sp_a.campaign_id
     JOIN campaign_groups gb ON gb.id = sb.initial_group_id AND gb.campaign_id = sp_a.campaign_id
     JOIN swap_proposals sp_b
       ON sp_b.campaign_id = sp_a.campaign_id
      AND sp_b.student_id = sp_a.target_student_id
      AND sp_b.target_student_id = sp_a.student_id
     WHERE sp_a.campaign_id = ?
       AND sp_a.student_id < sp_a.target_student_id'
);
$stmt->execute([$campaignId]);

$swapByNumber = [];
$swapPartnerByNumber = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $swapByNumber[$row['num_a']] = $row['group_b'];
    $swapByNumber[$row['num_b']] = $row['group_a'];

    $swapPartnerByNumber[$row['num_a']] =
        $row['num_b'] . ' ' . $row['surname_b'] . ' ' . $row['first_name_b'];
    $swapPartnerByNumber[$row['num_b']] =
        $row['num_a'] . ' ' . $row['surname_a'] . ' ' . $row['first_name_a'];
}

// ─── 4. Attribuer le nouveau groupe à chaque étudiant ────────────
$rows = [];

foreach ($students as $student) {
    $number = $student['student_number'];
    $newGroup = $student['current_group'];
    $source = '';
    $partner = '';

    if (isset($swapByNumber[$number])) {
        $newGroup = $swapByNumber[$number];
        $source = 'Swap 1-1';
        $partner = $swapPartnerByNumber[$number] ?? '';
    } elseif (isset($matchByNumber[$number])) {
        $newGroup = $matchByNumber[$number];
        $rank = $matchRankByNumber[$number] ?? 1;
        $suffixes = [1 => '1er choix', 2 => '2e choix', 3 => '3e choix'];
        $source = 'Matching (' . ($suffixes[$rank] ?? $rank . 'e choix') . ')';
    }

    $rows[] = [
        'student_number' => $number,
        'surname' => $student['surname'],
        'first_name' => $student['first_name'],
        'profile' => $student['profile'],
        'current_group' => $student['current_group'],
        'new_group' => $newGroup,
        'source' => $source,
        'partner' => $partner,
    ];
}

// ─── 5. Générer l'Excel ─────────────────────────────────────────
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Résultats');

$headers = ['N° étudiant', 'Nom', 'Prénom', 'Profil', 'Groupe actuel', 'Nouveau groupe', 'Origine', 'Avec qui'];
$sheet->fromArray($headers, null, 'A1');

// Styler l'en-tête
$sheet->getStyle('A1:G1')->getFont()->setBold(true);

$rowIdx = 2;
foreach ($rows as $row) {
    $sheet->fromArray(
        [
            $row['student_number'],
            $row['surname'],
            $row['first_name'],
            $row['profile'],
            $row['current_group'],
            $row['new_group'],
            $row['source'],
            $row['partner']
        ],
        null,
        'A' . $rowIdx
    );

    if ($row['source'] !== '') {
        $sheet->getStyle('A' . $rowIdx . ':H' . $rowIdx)
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('DBEAFE');
    }

    $rowIdx++;
}

// Ajuster la largeur des colonnes
foreach (['A' => 14, 'B' => 16, 'C' => 16, 'D' => 14, 'E' => 14, 'F' => 14, 'G' => 22, 'H' => 28] as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}

// ─── 6. Écrire le fichier ───────────────────────────────────────
$outputFile = __DIR__ . "/../storage/resultats-campagne-{$campaignId}.xlsx";
$writer = new Xlsx($spreadsheet);
$writer->save($outputFile);

// Résumé
$swaps = count($swapByNumber) / 2;
$matches = count($matchByNumber);

echo "Export terminé : {$outputFile}\n";
echo "  " . count($rows) . " étudiants\n";
echo "  {$swaps} swap(s) 1-1 confirmé(s)\n";
echo "  {$matches} mouvement(s) de matching\n";
echo "  " . (count($rows) - $swaps * 2 - $matches) . " inchangé(s)\n";