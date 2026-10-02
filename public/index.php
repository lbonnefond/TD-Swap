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

try {
    if ($method === 'GET' && $path === '/') {
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
