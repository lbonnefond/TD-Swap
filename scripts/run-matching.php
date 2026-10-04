<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use LBonnefond\TdSwap\Database\DatabaseFactory;
use LBonnefond\TdSwap\Repository\CampaignRepository;
use LBonnefond\TdSwap\Repository\CampaignRequestRepository;
use LBonnefond\TdSwap\Repository\MatchRepository;
use LBonnefond\TdSwap\Matching\ScalableMatcher;
use LBonnefond\TdSwap\Service\MatchingService;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/run-matching.php <campaign_id>\n");
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

$matchingService = new MatchingService(
    $pdo,
    new CampaignRepository($pdo),
    new CampaignRequestRepository($pdo),
    new MatchRepository($pdo),
    new ScalableMatcher(),
);

echo "Lancement du matching pour la campagne {$campaignId}…\n";
$start = microtime(true);

$solution = $matchingService->run($campaignId, new DateTimeImmutable());

$elapsed = round(microtime(true) - $start, 1);
echo "Terminé en {$elapsed}s : " . $solution->count() . " étudiant(s) déplacé(s).\n";
echo "Campagne marquée comme matched. Vous pouvez maintenant exporter les résultats.\n";