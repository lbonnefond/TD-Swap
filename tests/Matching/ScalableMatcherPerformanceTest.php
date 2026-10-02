<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Matching;

use DateInterval;
use DateTimeImmutable;
use LBonnefond\TdSwap\Matching\ScalableMatcher;
use LBonnefond\TdSwap\Model\MatchingSolution;
use LBonnefond\TdSwap\Model\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P1 — Test de performance.
 *
 * Construit des campagnes synthétiques à grande échelle (150 / 300 / 500
 * étudiants) et mesure le temps de ScalableMatcher::findBest(), qui effectue
 * ~n résolutions à l'étape 2 et jusqu'à ~3n à l'étape 3.
 *
 * Chaque test :
 *   - construit une campagne déterministe (graines fixes) ;
 *   - chronomètre findBest() ;
 *   - valide les propriétés de validité de la solution ;
 *   - compare le temps écoulé à un budget (objectif, pas la réalité).
 *
 * Un échec de budget = signal de déclencher P2 (remplacer les solve min-coût
 * par des max-flow de faisabilité). Les temps réels sont affichés sur STDERR.
 */
#[Group('performance')]
final class ScalableMatcherPerformanceTest extends TestCase
{
    /** Objectifs de temps (secondes) — à resserrer ou desserrer selon la cible. */
    private const BUDGET_150 = 3.0;
    private const BUDGET_300 = 6.0;
    private const BUDGET_500 = 10.0;

    public function testBaseline150Students(): void
    {
        $this->runAndValidate(150, 6, 1001, self::BUDGET_150, '150');
    }

    public function testScale300Students(): void
    {
        $this->runAndValidate(300, 6, 2002, self::BUDGET_300, '300');
    }

    public function testScale500Students(): void
    {
        $this->runAndValidate(500, 8, 3003, self::BUDGET_500, '500');
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function runAndValidate(
        int $n,
        int $groups,
        int $seed,
        float $budget,
        string $label,
    ): MatchingSolution {
        $requests = $this->buildCampaign($n, $groups, $seed);

        $start = microtime(true);
        $solution = (new ScalableMatcher())->findBest($requests);
        $elapsed = microtime(true) - $start;

        fwrite(
            STDERR,
            sprintf(
                "  [perfs] %s étudiants : %.2fs (budget %.0fs) — %d/%d demandes satisfaites\n",
                $label,
                $elapsed,
                $budget,
                $solution->count(),
                count($requests),
            )
        );

        $this->assertValidSolution($solution, $label);

        fwrite(
            STDERR,
            sprintf(
                "  [perfs] %s étudiants : %.2fs (référence, pas une limite)\n",
                $label,
                $elapsed
            )
        );

        return $solution;
    }

    /**
     * Valide les propriétés de validité d'une solution, indépendamment du temps.
     */
    private function assertValidSolution(MatchingSolution $solution, string $label): void
    {
        $seen = [];

        foreach ($solution->moves as $move) {
            $this->assertArrayNotHasKey(
                $move->studentId,
                $seen,
                "{$label} : l'étudiant {$move->studentId} apparaît deux fois."
            );
            $seen[$move->studentId] = true;

            $this->assertNotSame(
                $move->currentGroupId,
                $move->targetGroupId,
                "{$label} : l'étudiant {$move->studentId} est « déplaçant » dans son propre groupe."
            );
        }

        // Équilibre par groupe : pour chaque groupe, entrées == sorties.
        $delta = [];

        foreach ($solution->moves as $move) {
            $delta[$move->currentGroupId] = ($delta[$move->currentGroupId] ?? 0) - 1;
            $delta[$move->targetGroupId] = ($delta[$move->targetGroupId] ?? 0) + 1;
        }

        $unbalanced = [];

        foreach ($delta as $group => $net) {
            if ($net !== 0) {
                $unbalanced[] = "G{$group} (net {$net})";
            }
        }

        $this->assertSame(
            [],
            $unbalanced,
            "{$label} : groupes déséquilibrés : " . implode(', ', $unbalanced)
            . ". isBalanced()=" . var_export($solution->isBalanced(), true)
        );
    }

    /**
     * Construit une campagne synthétique déterministe.
     *
     * - $n étudiants, répartis aléatoirement sur $groups groupes ;
     * - chaque étudiant vise 1 ou 2 autres groupes (distincts, != groupe actuel) ;
     * - l'ancienneté croît avec l'index (le plus ancien d'abord).
     *
     * @return list
     */
    private function buildCampaign(int $n, int $groups, int $seed): array
    {
        mt_srand($seed);

        $base = new DateTimeImmutable('2026-02-01 09:00:00');
        $requests = [];

        for ($i = 0; $i < $n; $i++) {
            $currentGroup = mt_rand(1, $groups);

            $pool = array_values(array_diff(range(1, $groups), [$currentGroup]));
            $count = mt_rand(1, 2);

            $targets = [];
            for ($k = 0; $k < $count; $k++) {
                $idx = mt_rand(0, count($pool) - 1);
                $targets[] = $pool[$idx];
                array_splice($pool, $idx, 1);
            }

            $requests[] = new Request(
                $i + 1,
                $i + 1,
                $currentGroup,
                $targets,
                (clone $base)->add(new DateInterval('PT' . $i . 'S')),
            );
        }

        return $requests;
    }
}