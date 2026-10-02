<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use LBonnefond\TdSwap\Database\DatabaseFactory;
use LBonnefond\TdSwap\Model\Campaign;
use LBonnefond\TdSwap\Repository\CampaignRepository;
use LBonnefond\TdSwap\Service\CampaignService;
use LBonnefond\TdSwap\Repository\CampaignRequestRepository;
use LBonnefond\TdSwap\Service\RequestService;
use LBonnefond\TdSwap\Matching\ScalableMatcher;
use LBonnefond\TdSwap\Repository\MatchRepository;
use LBonnefond\TdSwap\Service\MatchingService;

$pdo = DatabaseFactory::create(
    __DIR__ . '/../storage/td-swap.sqlite'
);

$campaignRepository = new CampaignRepository($pdo);
$campaignService = new CampaignService(
    $pdo,
    $campaignRepository
);

$requestRepository = new CampaignRequestRepository($pdo);

$requestService = new RequestService(
    $pdo,
    $campaignRepository,
    $requestRepository
);

$matchRepository = new MatchRepository($pdo);

$matchingService = new MatchingService(
    $pdo,
    $campaignRepository,
    $requestRepository,
    $matchRepository,
    new ScalableMatcher(),
);

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

// Redirect racine vers l'interface
if ($method === 'GET' && $path === '/') {
    header('Location: /app.html');
    http_response_code(302);
    exit;
}

function requireCampusAccess(\PDO $pdo, int $campaignId, array $input): void
{
    $stmt = $pdo->prepare('SELECT access_code FROM campaigns WHERE id = ?');
    $stmt->execute([$campaignId]);
    $expected = $stmt->fetchColumn();

    if ($expected === false) {
        throw new \DomainException('Campagne introuvable.');
    }

    if ($expected === null || $expected === '') {
        // Pas de code défini : on laisse passer (rétro-compat)
        return;
    }

    $provided = trim((string) ($input['access_code'] ?? ''));

    if ($provided === '' || !hash_equals((string) $expected, $provided)) {
        throw new \DomainException('Code campagne incorrect.');
    }
}

try {
    if ($method === 'GET' && $path === '/campaigns') {
        echo json_encode(
            [
                'campaigns' => $campaignRepository->findAll(),
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );
        exit;
    }

    if ($method === 'POST' && $path === '/campaigns') {
        $input = json_decode(
            file_get_contents('php://input'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $campaign = new Campaign(
            id: null,
            name: $input['name'] ?? '',
            startsAt: new DateTimeImmutable($input['starts_at'] ?? ''),
            closesAt: new DateTimeImmutable($input['closes_at'] ?? ''),
            accessCode: $input['access_code'] ?? null,
        );

        $created = $campaignService->createCampaign($campaign);

        http_response_code(201);

        echo json_encode(
            [
                'id' => $created->id,
                'name' => $created->name,
                'starts_at' => $created->startsAt->format(DATE_ATOM),
                'closes_at' => $created->closesAt->format(DATE_ATOM),
                'status' => $created->status,
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );
        exit;
    }

    if (
        $method === 'POST'
        && preg_match('#^/campaigns/(\d+)/open$#', $path, $matches)
    ) {
        $campaignId = (int) $matches[1];

        $campaign = $campaignService->openCampaign(
            $campaignId,
            new DateTimeImmutable()
        );

        echo json_encode(
            [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'starts_at' => $campaign->startsAt->format(DATE_ATOM),
                'closes_at' => $campaign->closesAt->format(DATE_ATOM),
                'status' => $campaign->status,
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );
        exit;
    }

    if (
        $method === 'POST'
        && preg_match('#^/campaigns/(\d+)/swap-proposals$#', $path, $matches)
    ) {
        $campaignId = (int) $matches[1];

        $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);

        requireCampusAccess($pdo, $campaignId, $input);

        $studentNumber = trim((string) ($input['student_number'] ?? ''));
        $targetNumber = trim((string) ($input['target_student_number'] ?? ''));

        if ($studentNumber === '' || $targetNumber === '') {
            throw new InvalidArgumentException(
                'Les deux numéros étudiants sont requis.'
            );
        }

        // Vérifier que la campagne est open
        $campaign = $campaignRepository->findById($campaignId);
        if ($campaign === null || $campaign->status !== 'open') {
            throw new \DomainException('La campagne n\'est pas ouverte.');
        }

        // Résoudre les deux étudiants
        $stmt = $pdo->prepare(
            'SELECT id, initial_group_id, profile_id
             FROM campaign_students
             WHERE campaign_id = ? AND student_number = ?'
        );
        $stmt->execute([$campaignId, $studentNumber]);
        $student = $stmt->fetch();

        $stmt->execute([$campaignId, $targetNumber]);
        $target = $stmt->fetch();

        if ($student === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Étudiant introuvable.']);
            exit;
        }
        if ($target === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Étudiant cible introuvable dans cette campagne.']);
            exit;
        }

        // Même groupe ?
        if ($student['initial_group_id'] === $target['initial_group_id']) {
            throw new \DomainException(
                'Les deux étudiants sont déjà dans le même groupe.'
            );
        }

        // Vérifier l'éligibilité réciproque
        $stmt = $pdo->prepare(
            'SELECT 1 FROM campaign_profile_groups
             WHERE campaign_id = ? AND profile_id = ? AND campaign_group_id = ?'
        );
        $stmt->execute([$campaignId, $student['profile_id'], $target['initial_group_id']]);
        if ($stmt->fetchColumn() === false) {
            throw new \DomainException(
                'Le groupe de l\'étudiant cible n\'est pas éligible pour ton profil.'
            );
        }
        $stmt->execute([$campaignId, $target['profile_id'], $student['initial_group_id']]);
        if ($stmt->fetchColumn() === false) {
            throw new \DomainException(
                'Ton groupe n\'est pas éligible pour le profil de l\'étudiant cible.'
            );
        }

        // Pas de demande classique existante pour ce student
        $stmt = $pdo->prepare(
            'SELECT 1 FROM requests
             WHERE campaign_id = ? AND campaign_student_id = ? AND withdrawn_at IS NULL'
        );
        $stmt->execute([$campaignId, $student['id']]);
        if ($stmt->fetchColumn() !== false) {
            throw new \DomainException(
                'Tu as déjà une demande de changement. Retire-la avant de proposer un échange.'
            );
        }

        // Retirer l'ancienne proposition si elle existe (update)
        $stmt = $pdo->prepare(
            'DELETE FROM swap_proposals WHERE campaign_id = ? AND student_id = ?'
        );
        $stmt->execute([$campaignId, $student['id']]);

        // Insérer la nouvelle
        $stmt = $pdo->prepare(
            'INSERT INTO swap_proposals (campaign_id, student_id, target_student_id, created_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$campaignId, $student['id'], $target['id'], (new DateTimeImmutable())->format('Y-m-d H:i:s')]);

        // Vérifier si l'échange est maintenant confirmé
        $stmt = $pdo->prepare(
            'SELECT 1 FROM swap_proposals
             WHERE campaign_id = ? AND student_id = ? AND target_student_id = ?'
        );
        $stmt->execute([$campaignId, $target['id'], $student['id']]);
        $confirmed = $stmt->fetchColumn() !== false;

        echo json_encode([
            'proposed' => true,
            'target_student_number' => $targetNumber,
            'confirmed' => $confirmed,
            'message' => $confirmed
                ? 'Les deux étudiants ont proposé l\'échange. Il est confirmé.'
                : 'Proposition envoyée. En attente de confirmation de l\'autre étudiant.',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        exit;
    }

    if (
        $method === 'GET'
        && preg_match('#^/campaigns/(\d+)$#', $path, $matches)
    ) {
        $campaignId = (int) $matches[1];
        $campaign = $campaignRepository->findById($campaignId);

        if ($campaign === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Campagne introuvable.']);
            exit;
        }

        $statement = $pdo->prepare(
            'SELECT id, name, capacity
         FROM campaign_groups
         WHERE campaign_id = ?
         ORDER BY name'
        );
        $statement->execute([$campaignId]);

        echo json_encode(
            [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'starts_at' => $campaign->startsAt->format(DATE_ATOM),
                'closes_at' => $campaign->closesAt->format(DATE_ATOM),
                'status' => $campaign->status,
                'groups' => $statement->fetchAll(),
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );
        exit;
    }

    if (
        $method === 'GET'
        && preg_match(
            '#^/campaigns/(\d+)/students/([^/]+)$#',
            $path,
            $matches
        )
    ) {
        $campaignId = (int) $matches[1];
        $studentNumber = rawurldecode($matches[2]);

        requireCampusAccess($pdo, $campaignId, $_GET);

        $statement = $pdo->prepare(
            'SELECT
            cs.id,
            cs.student_number,
            cs.surname,
            cs.first_name,
            cg.name AS current_group
         FROM campaign_students cs
         JOIN campaign_groups cg
           ON cg.id = cs.initial_group_id
         WHERE cs.campaign_id = ?
           AND cs.student_number = ?'
        );

        $statement->execute([
            $campaignId,
            $studentNumber,
        ]);

        $student = $statement->fetch();

        if ($student === false) {
            http_response_code(404);
            echo json_encode([
                'error' => 'Étudiant introuvable dans cette campagne.',
            ]);
            exit;
        }

        $statement = $pdo->prepare(
            'SELECT cg.id, cg.name
         FROM campaign_profile_groups cpg
         JOIN campaign_groups cg
           ON cg.id = cpg.campaign_group_id
         JOIN campaign_students cs
           ON cs.profile_id = cpg.profile_id
          AND cs.campaign_id = cpg.campaign_id
         WHERE cs.id = ?
           AND cpg.campaign_id = ?
         ORDER BY cg.name'
        );

        $statement->execute([
            $student['id'],
            $campaignId,
        ]);

        echo json_encode(
            [
                'student_number' => $student['student_number'],
                'surname' => $student['surname'],
                'first_name' => $student['first_name'],
                'current_group' => $student['current_group'],
                'eligible_groups' => $statement->fetchAll(),
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );
        exit;
    }

    if (
        $method === 'POST'
        && preg_match('#^/campaigns/(\d+)/requests$#', $path, $matches)
    ) {
        $campaignId = (int) $matches[1];

        $input = json_decode(
            file_get_contents('php://input'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        requireCampusAccess($pdo, $campaignId, $input);

        $studentNumber = trim((string) ($input['student_number'] ?? ''));
        $targetNames = $input['targets'] ?? null;

        if (
            $studentNumber === ''
            || !is_array($targetNames)
            || !array_is_list($targetNames)
        ) {
            throw new InvalidArgumentException(
                'Le numéro étudiant et une liste de groupes cibles sont requis.'
            );
        }

        $statement = $pdo->prepare(
            'SELECT id
         FROM campaign_students
         WHERE campaign_id = ?
           AND student_number = ?'
        );
        $statement->execute([$campaignId, $studentNumber]);

        $campaignStudentId = $statement->fetchColumn();

        if ($campaignStudentId === false) {
            http_response_code(404);
            echo json_encode([
                'error' => 'Étudiant introuvable dans cette campagne.',
            ]);
            exit;
        }

        $targetIds = [];

        foreach ($targetNames as $targetName) {
            if (!is_string($targetName)) {
                throw new InvalidArgumentException(
                    'Chaque groupe cible doit être un nom de groupe.'
                );
            }

            $statement = $pdo->prepare(
                'SELECT id
             FROM campaign_groups
             WHERE campaign_id = ?
               AND name = ?'
            );
            $statement->execute([$campaignId, $targetName]);

            $targetId = $statement->fetchColumn();

            if ($targetId === false) {
                throw new InvalidArgumentException(
                    sprintf('Groupe cible inconnu : %s', $targetName)
                );
            }

            $targetIds[] = (int) $targetId;
        }

        $request = $requestService->submit(
            $campaignId,
            (int) $campaignStudentId,
            $targetIds,
            new DateTimeImmutable()
        );

        http_response_code(201);

        echo json_encode(
            [
                'id' => $request->id,
                'campaign_id' => $request->campaignId,
                'student_number' => $studentNumber,
                'targets' => $targetNames,
                'first_submitted_at' => $request->firstSubmittedAt->format(DATE_ATOM),
                'status' => 'active',
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );
        exit;
    }

    if (
        $method === 'PUT'
        && preg_match(
            '#^/campaigns/(\d+)/requests/([^/]+)$#',
            $path,
            $matches
        )
    ) {
        $campaignId = (int) $matches[1];
        $studentNumber = rawurldecode($matches[2]);

        $input = json_decode(
            file_get_contents('php://input'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        requireCampusAccess($pdo, $campaignId, $input);

        $targetNames = $input['targets'] ?? null;

        if (!is_array($targetNames) || !array_is_list($targetNames)) {
            throw new InvalidArgumentException(
                'Une liste de groupes cibles est requise.'
            );
        }

        $statement = $pdo->prepare(
            'SELECT id
         FROM campaign_students
         WHERE campaign_id = ?
           AND student_number = ?'
        );
        $statement->execute([$campaignId, $studentNumber]);
        $campaignStudentId = $statement->fetchColumn();

        if ($campaignStudentId === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Étudiant introuvable.']);
            exit;
        }

        $targetIds = [];

        foreach ($targetNames as $targetName) {
            if (!is_string($targetName)) {
                throw new InvalidArgumentException(
                    'Chaque groupe cible doit être un nom de groupe.'
                );
            }

            $statement = $pdo->prepare(
                'SELECT id
             FROM campaign_groups
             WHERE campaign_id = ?
               AND name = ?'
            );
            $statement->execute([$campaignId, $targetName]);
            $targetId = $statement->fetchColumn();

            if ($targetId === false) {
                throw new InvalidArgumentException(
                    sprintf('Groupe cible inconnu : %s', $targetName)
                );
            }

            $targetIds[] = (int) $targetId;
        }

        $request = $requestService->update(
            $campaignId,
            (int) $campaignStudentId,
            $targetIds,
            new DateTimeImmutable()
        );

        echo json_encode(
            [
                'id' => $request->id,
                'campaign_id' => $request->campaignId,
                'student_number' => $studentNumber,
                'targets' => $targetNames,
                'first_submitted_at' => $request->firstSubmittedAt->format(DATE_ATOM),
                'updated_at' => $request->updatedAt->format(DATE_ATOM),
                'status' => 'active',
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );
        exit;
    }

    if ($method === 'DELETE' && preg_match('#^/campaigns/(\d+)/requests/(\d+)$#', $path, $matches)) {
        $campaignId = (int) $matches[1];
        $studentNumber = $matches[2];

        requireCampusAccess($pdo, $campaignId, $_GET);

        try {
            $result = $requestService->withdrawRequest(
                $campaignId,
                $studentNumber,
                new DateTimeImmutable()
            );

            echo json_encode(
                [
                    'id' => $result->id,
                    'campaign_id' => $result->campaignId,
                    'campaign_student_id' => $result->campaignStudentId,
                    'targets' => $result->targetCampaignGroupIds,
                    'first_submitted_at' => $result->firstSubmittedAt->format(DATE_ATOM),
                    'updated_at' => $result->updatedAt->format(DATE_ATOM),
                    'status' => 'withdrawn',
                ],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
            );
        } catch (\Throwable $exception) {
            http_response_code(400);

            echo json_encode(
                ['error' => $exception->getMessage()],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
            );
        }

        exit;
    }

    if (
        $method === 'DELETE'
        && preg_match('#^/campaigns/(\d+)/swap-proposals$#', $path, $matches)
    ) {
        $campaignId = (int) $matches[1];

        $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);

        requireCampusAccess($pdo, $campaignId, $input);

        $studentNumber = trim((string) ($input['student_number'] ?? ''));

        $stmt = $pdo->prepare(
            'DELETE FROM swap_proposals
             WHERE campaign_id = ? AND student_id = (
                 SELECT id FROM campaign_students
                 WHERE campaign_id = ? AND student_number = ?
             )'
        );
        $stmt->execute([$campaignId, $campaignId, $studentNumber]);

        echo json_encode(['removed' => true]);
        exit;
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'GET'
        && preg_match('#^/campaigns/(\d+)/requests$#', $path, $matches)
    ) {
        $campaignId = (int) $matches[1];

        $requests = $requestService->findActiveRequests($campaignId);

        echo json_encode(
            [
                'requests' => array_map(
                    static function ($request): array {
                        return [
                            'id' => $request->id,
                            'campaign_student_id' => $request->campaignStudentId,
                            'targets' => $request->targetCampaignGroupIds,
                            'first_submitted_at' =>
                                $request->firstSubmittedAt->format(DATE_ATOM),
                            'updated_at' =>
                                $request->updatedAt->format(DATE_ATOM),
                        ];
                    },
                    $requests
                ),
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );

        exit;
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'GET'
        && preg_match('#^/campaigns/(\d+)/requests/all$#', $path, $matches)
    ) {
        $campaignId = (int) $matches[1];

        $requests = $requestService->findRequests($campaignId);

        echo json_encode(
            [
                'requests' => array_map(
                    static function ($request): array {
                        return [
                            'id' => $request->id,
                            'campaign_student_id' => $request->campaignStudentId,
                            'targets' => $request->targetCampaignGroupIds,
                            'withdrawn_at' => $request->withdrawnAt?->format(DATE_ATOM),
                        ];
                    },
                    $requests
                ),
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );

        exit;
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && preg_match('#^/campaigns/(\d+)/close$#', $path, $matches)
    ) {
        $campaign = $campaignService->closeCampaign(
            (int) $matches[1],
            new DateTimeImmutable(),
        );

        echo json_encode(
            [
                'id' => $campaign->id,
                'status' => $campaign->status,
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );

        exit;
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && preg_match('#^/campaigns/(\d+)/match$#', $path, $matches)
    ) {
        $campaignId = (int) $matches[1];

        $solution = $matchingService->run(
            $campaignId,
            new DateTimeImmutable(),
        );

        echo json_encode(
            [
                'matches' => array_map(
                    static function ($move): array {
                        return [
                            'student_id' => $move->studentId,
                            'from_group_id' => $move->currentGroupId,
                            'to_group_id' => $move->targetGroupId,
                            'preference_rank' => $move->preferenceRank,
                        ];
                    },
                    $solution->moves
                ),
                'count' => $solution->count(),
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );

        exit;
    }

    if (
        $method === 'GET'
        && preg_match('#^/campaigns/(\d+)/requests/joined$#', $path, $matches)
    ) {
        $campaignId = (int) $matches[1];

        $statement = $pdo->prepare(
            'SELECT r.id, r.first_submitted_at, r.updated_at, r.withdrawn_at,
                    cs.student_number, cs.surname, cs.first_name,
                    cg1.name AS target_1,
                    cg2.name AS target_2,
                    cg3.name AS target_3
             FROM requests r
             JOIN campaign_students cs
               ON cs.id = r.campaign_student_id
              AND cs.campaign_id = r.campaign_id
             LEFT JOIN campaign_groups cg1
               ON cg1.id = r.target_1_campaign_group_id
              AND cg1.campaign_id = r.campaign_id
             LEFT JOIN campaign_groups cg2
               ON cg2.id = r.target_2_campaign_group_id
              AND cg2.campaign_id = r.campaign_id
             LEFT JOIN campaign_groups cg3
               ON cg3.id = r.target_3_campaign_group_id
              AND cg3.campaign_id = r.campaign_id
             WHERE r.campaign_id = ?
               AND r.withdrawn_at IS NULL
             ORDER BY r.first_submitted_at, r.id'
        );
        $statement->execute([$campaignId]);

        $rows = $statement->fetchAll();

        echo json_encode(
            [
                'requests' => array_map(
                    static function (array $row): array {
                        $targets = array_values(
                            array_filter(
                                [
                                    $row['target_1'],
                                    $row['target_2'],
                                    $row['target_3'],
                                ],
                                static fn($v) => $v !== null
                            )
                        );

                        return [
                            'id' => $row['id'],
                            'student_number' => $row['student_number'],
                            'surname' => $row['surname'],
                            'first_name' => $row['first_name'],
                            'targets' => $targets,
                            'first_submitted_at' => $row['first_submitted_at'],
                        ];
                    },
                    $rows
                ),
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );

        exit;
    }

    if (
        $method === 'GET'
        && preg_match('#^/campaigns/(\d+)/matches$#', $path, $matches)
    ) {
        $campaignId = (int) $matches[1];

        $statement = $pdo->prepare(
            'SELECT m.student_id, m.from_group_id, m.to_group_id, m.preference_rank,
                    cs.student_number, cs.surname, cs.first_name,
                    cgf.name AS from_group,
                    cgt.name AS to_group
             FROM matches m
             JOIN campaign_students cs
               ON cs.id = m.student_id
              AND cs.campaign_id = m.campaign_id
             JOIN campaign_groups cgf
               ON cgf.id = m.from_group_id
              AND cgf.campaign_id = m.campaign_id
             JOIN campaign_groups cgt
               ON cgt.id = m.to_group_id
              AND cgt.campaign_id = m.campaign_id
             WHERE m.campaign_id = ?
             ORDER BY cs.student_number'
        );
        $statement->execute([$campaignId]);

        echo json_encode(
            ['matches' => $statement->fetchAll()],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );

        exit;
    }

    if (
        $method === 'GET'
        && preg_match('#^/campaigns/(\d+)/confirmed-swaps$#', $path, $matches)
    ) {
        $campaignId = (int) $matches[1];

        $stmt = $pdo->prepare(
            'SELECT
                sa.student_number AS student_a_number,
                sa.surname AS student_a_surname,
                sa.first_name AS student_a_first_name,
                ga.name AS student_a_group,
                sb.student_number AS student_b_number,
                sb.surname AS student_b_surname,
                sb.first_name AS student_b_first_name,
                gb.name AS student_b_group
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
             ORDER BY sa.student_number'
        );
        $stmt->execute([$campaignId]);

        echo json_encode(
            ['swaps' => $stmt->fetchAll()],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        );
        exit;
    }

    http_response_code(404);

    echo json_encode(
        ['error' => 'Route introuvable.'],
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
    );
} catch (Throwable $exception) {
    http_response_code(400);

    echo json_encode(
        [
            'error' => $exception->getMessage(),
        ],
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
    );
}
