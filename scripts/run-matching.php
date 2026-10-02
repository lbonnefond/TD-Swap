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

$campaignId = (int) $argv[1];

$pdo = DatabaseFactory::create(__DIR__ . '/../storage/td-swap.sqlite');

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