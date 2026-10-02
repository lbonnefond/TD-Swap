<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Matching;

use DateInterval;
use DateTimeImmutable;
use LBonnefond\TdSwap\Matching\Matcher;
use LBonnefond\TdSwap\Matching\ScalableMatcher;
use LBonnefond\TdSwap\Model\MatchingSolution;
use LBonnefond\TdSwap\Model\Request;
use PHPUnit\Framework\TestCase;

/**
 * P1 — Test oracle.
 *
 * Sur de petits jeux (moins d'une douzaine de demandes), on confronte la solution
 * de ScalableMatcher (moteur polynomial, en production) à celle du Matcher
 * exhaustif (backtracking), qui sert de référence de vérité.
 *
 * Les deux moteurs visent le même objectif lexicographique :
 *   1. cardinalité maximale (le plus de demandes satisfaites) ;
 *   2. à cardinalité égale, priorité aux demandes les plus anciennes ;
 *   3. à satisfaction identique, meilleur rang de préférence.
 *
 * L'optimum est unique : même cardinalité + même ensemble satisfait + même rang
 * pour chaque étudiant => mêmes mouvements. Les deux solutions doivent donc être
 * strictement identiques au mouvement près.
 *
 * À exécuter AVANT toute optimisation (P2) pour verrouiller la correcte-éduite.
 */
final class OracleComparisonTest extends TestCase
{
    /** Échange 1 pour 1 : deux étudiants s'échangent de groupe. */
    public function testSimpleOneToOneSwap(): void
    {
        $base = $this->baseTime();
        $requests = [
            $this->request(1, 1, [2], 0, $base),  // A (G1) veut G2
            $this->request(2, 2, [1], 1, $base),  // B (G2) veut G1
        ];

        $this->assertSameSolution($requests, 'Échange 1 pour 1');
    }

    /** Cycle circulaire à trois. */
    public function testCircularThreeCycle(): void
    {
        $base = $this->baseTime();
        $requests = [
            $this->request(1, 1, [2], 0, $base),  // A (G1) -> G2
            $this->request(2, 2, [3], 1, $base),  // B (G2) -> G3
            $this->request(3, 3, [1], 2, $base),  // C (G3) -> G1
        ];

        $this->assertSameSolution($requests, 'Cycle circulaire à 3');
    }

    /** Demande isolée, non satisfiable : personne ne veut entrer dans G1. */
    public function testUnresolvableRequest(): void
    {
        $base = $this->baseTime();
        $requests = [
            $this->request(1, 1, [2], 0, $base),  // A (G1) -> G2, mais G2 ne perd personne vers G1
        ];

        $this->assertSameSolution($requests, 'Demande isolée non satisfiable');
    }

    /** Échange A<->B satisfiable + demande C non satisfiable. */
    public function testPartialSatisfaction(): void
    {
        $base = $this->baseTime();
        $requests = [
            $this->request(1, 1, [2], 0, $base),  // A (G1) -> G2
            $this->request(2, 2, [1], 1, $base),  // B (G2) -> G1
            $this->request(3, 1, [3], 2, $base),  // C (G1) -> G3 (G3 ne perd personne)
        ];

        $this->assertSameSolution($requests, 'Satisfaction partielle');
    }

    /**
     * Priorité d'ancienneté : deux étudiants du même groupe veulent le même groupe
     * cible ; seul l'un des deux peut être satisfait. Le plus ancien doit gagner.
     */
    public function testOldestRequestWinsOnTie(): void
    {
        $base = $this->baseTime();
        $requests = [
            $this->request(1, 1, [2], 0, $base),  // A (G1, le plus ancien) veut G2
            $this->request(2, 1, [2], 1, $base),  // B (G1) veut G2
            $this->request(3, 2, [1], 2, $base),  // C (G2) veut G1
        ];

        $this->assertSameSolution($requests, "Priorité d'ancienneté");

        // Vérification explicite de la sémantique d'ancienneté :
        // c'est A (le plus ancien) qui est satisfait, pas B.
        $solution = (new ScalableMatcher())->findBest($requests);
        $satisfied = array_map(
            static fn ($m) => $m->studentId,
            $solution->moves
        );

        $this->assertContains(1, $satisfied, 'A (plus ancien) doit être satisfait.');
        $this->assertNotContains(2, $satisfied, 'B (plus récent) doit rester dans son groupe.');
    }

    /**
     * Minimisation du rang de préférence : A est satisfiable soit à son 1er choix
     * (G2) soit à son 2e (G3). Il doit obtenir le meilleur rang.
     */
    public function testBestPreferenceRank(): void
    {
        $base = $this->baseTime();
        $requests = [
            $this->request(1, 1, [2, 3], 0, $base),  // A (G1) : rang1=G2, rang2=G3
            $this->request(2, 2, [1], 1, $base),     // B (G2) -> G1
            $this->request(3, 3, [1], 2, $base),     // C (G3) -> G1
        ];

        $this->assertSameSolution($requests, 'Meilleur rang de préférence');

        $solution = (new ScalableMatcher())->findBest($requests);

        foreach ($solution->moves as $move) {
            if ($move->studentId === 1) {
                $this->assertSame(
                    2,
                    $move->targetGroupId,
                    'A doit obtenir son 1er choix (G2), pas son 2e (G3).'
                );
                $this->assertSame(1, $move->preferenceRank, "Le rang d'A doit être 1.");
            }
        }
    }

    /**
     * Petit jeu aléatoire déterministe, pour couvrir des combinaisons non manuelles.
     * Restreint à 10 demandes pour que l'exhaustif reste rapide.
     */
    public function testRandomSmallInstanceMatchesExhaustive(): void
    {
        mt_srand(123456);
        $base = $this->baseTime();
        $requests = [];

        for ($i = 1; $i <= 10; $i++) {
            $currentGroup = ($i % 3) + 1;  // groupes 1, 2, 3 en roue
            $others = array_values(array_diff([1, 2, 3], [$currentGroup]));
            $count = mt_rand(1, count($others));

            $targets = [];
            $pool = $others;
            for ($x = 0; $x < $count; $x++) {
                $idx = mt_rand(0, count($pool) - 1);
                $targets[] = $pool[$idx];
                array_splice($pool, $idx, 1);
            }

            $requests[] = $this->request($i, $currentGroup, $targets, $i, $base);
        }

        $this->assertSameSolution($requests, 'Jeu aléatoire déterministe (10 demandes)');
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    /**
     * Confronte les deux moteurs sur un même jeu et exige des solutions identiques.
     *
     * @param list $requests
     */
    private function assertSameSolution(array $requests, string $context): void
    {
        $exhaustive = (new Matcher())->findBest($requests);
        $scalable = (new ScalableMatcher())->findBest($requests);

        $exp = $this->normalize($exhaustive, 'Matcher exhaustif');
        $got = $this->normalize($scalable, 'ScalableMatcher');

        $this->assertSame(
            $exp['count'],
            $got['count'],
            "{$context} : cardinalité divergente (exhaustif={$exp['count']}, scalable={$got['count']})."
        );

        if ($exp['byStudent'] === $got['byStudent']) {
            return;
        }

        // Message diagnostique : on identifie les étudiants en divergence.
        $expStudents = array_keys($exp['byStudent']);
        $gotStudents = array_keys($got['byStudent']);
        $diffLines = [];

        foreach ($got['byStudent'] as $studentId => $move) {
            if (!isset($exp['byStudent'][$studentId])) {
                continue;
            }
            if ($exp['byStudent'][$studentId] !== $move) {
                $diffLines[] = sprintf(
                    'étudiant %d : exhaustif=(%d -> %d, rang %d) scalable=(%d -> %d, rang %d)',
                    $studentId,
                    $exp['byStudent'][$studentId]['from'],
                    $exp['byStudent'][$studentId]['to'],
                    $exp['byStudent'][$studentId]['rank'],
                    $move['from'],
                    $move['to'],
                    $move['rank'],
                );
            }
        }

        $this->fail(implode("\n", [
            "{$context} : les deux moteurs divergent.",
            'Satisfaits seulement dans exhaustif : '
                . implode(', ', array_map('strval', array_diff($expStudents, $gotStudents))),
            'Satisfaits seulement dans scalable   : '
                . implode(', ', array_map('strval', array_diff($gotStudents, $expStudents))),
            'Détail        : ' . implode(' | ', $diffLines),
        ]));
    }

    /**
     * Normalise une solution en [count, byStudent] et vérifie qu'aucun étudiant
     * n'y apparaît deux fois.
     *
     * @return array{count: int, byStudent: array<int, array{from: int, to: int, rank: int}>}
     */
    private function normalize(MatchingSolution $solution, string $label): array
    {
        $byStudent = [];

        foreach ($solution->moves as $move) {
            $this->assertArrayNotHasKey(
                $move->studentId,
                $byStudent,
                "{$label} : l'étudiant {$move->studentId} apparaît deux fois dans la solution."
            );

            $byStudent[$move->studentId] = [
                'from' => $move->currentGroupId,
                'to'   => $move->targetGroupId,
                'rank' => $move->preferenceRank,
            ];
        }

        return ['count' => $solution->count(), 'byStudent' => $byStudent];
    }

    /**
     * @param list $targets
     */
    private function request(
        int $id,
        int $currentGroup,
        array $targets,
        int $ageSeconds,
        DateTimeImmutable $base,
    ): Request {
        return new Request(
            $id,
            $id,
            $currentGroup,
            $targets,
            (clone $base)->add(new DateInterval('PT' . $ageSeconds . 'S')),
        );
    }

    private function baseTime(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-02-01 09:00:00');
    }
}