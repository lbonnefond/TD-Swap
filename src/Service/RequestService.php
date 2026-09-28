<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Service;

use DateTimeImmutable;
use DomainException;
use LBonnefond\TdSwap\Model\CampaignRequest;
use LBonnefond\TdSwap\Repository\CampaignRequestRepository;
use LBonnefond\TdSwap\Repository\CampaignRepository;
use PDO;
use RuntimeException;

final class RequestService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CampaignRepository $campaignRepository,
        private readonly CampaignRequestRepository $requestRepository,
    ) {}

    /**
     * @param list<int> $targetCampaignGroupIds
     */
    public function submit(
        int $campaignId,
        int $campaignStudentId,
        array $targetCampaignGroupIds,
        DateTimeImmutable $now,
    ): CampaignRequest {
        $campaign = $this->requireOpenCampaign($campaignId);

        if ($now < $campaign->startsAt || $now > $campaign->closesAt) {
            throw new DomainException(
                'La demande doit être déposée pendant la période d’ouverture.'
            );
        }

        if ($this->requestRepository->findByCampaignAndStudent(
            $campaignId,
            $campaignStudentId
        ) !== null) {
            throw new DomainException(
                'Cet étudiant possède déjà une demande pour cette campagne.'
            );
        }

        $student = $this->findCampaignStudent(
            $campaignId,
            $campaignStudentId
        );

        $this->validateTargets(
            $campaignId,
            $student['profile_id'],
            $student['initial_group_id'],
            $targetCampaignGroupIds,
        );

        return $this->requestRepository->create(
            new CampaignRequest(
                null,
                $campaignId,
                $campaignStudentId,
                $targetCampaignGroupIds,
                $now,
                $now,
            )
        );
    }

    /**
     * @param list<int> $targetCampaignGroupIds
     */
    public function update(
        int $campaignId,
        int $campaignStudentId,
        array $targetCampaignGroupIds,
        DateTimeImmutable $now,
    ): CampaignRequest {
        $campaign = $this->requireOpenCampaign($campaignId);

        if ($now < $campaign->startsAt || $now > $campaign->closesAt) {
            throw new DomainException(
                'La demande ne peut être modifiée que pendant la période d’ouverture.'
            );
        }

        $request = $this->requestRepository->findByCampaignAndStudent(
            $campaignId,
            $campaignStudentId
        );

        if ($request === null) {
            throw new DomainException(
                'Aucune demande existante pour cet étudiant.'
            );
        }

        $student = $this->findCampaignStudent(
            $campaignId,
            $campaignStudentId
        );

        $this->validateTargets(
            $campaignId,
            $student['profile_id'],
            $student['initial_group_id'],
            $targetCampaignGroupIds,
        );

        return $this->requestRepository->update(
            new CampaignRequest(
                $request->id,
                $request->campaignId,
                $request->campaignStudentId,
                $targetCampaignGroupIds,
                $request->firstSubmittedAt,
                $now,
                null,
            )
        );
    }

    public function withdraw(
        int $campaignId,
        int $campaignStudentId,
        DateTimeImmutable $now,
    ): CampaignRequest {
        $campaign = $this->requireOpenCampaign($campaignId);

        if ($now < $campaign->startsAt || $now > $campaign->closesAt) {
            throw new DomainException(
                'La demande ne peut être retirée que pendant la période d’ouverture.'
            );
        }

        $request = $this->requestRepository->findByCampaignAndStudent(
            $campaignId,
            $campaignStudentId
        );

        if ($request === null) {
            throw new DomainException(
                'Aucune demande existante pour cet étudiant.'
            );
        }

        if ($request->withdrawnAt !== null) {
            throw new DomainException(
                'Cette demande est déjà retirée.'
            );
        }

        return $this->requestRepository->update(
            new CampaignRequest(
                $request->id,
                $request->campaignId,
                $request->campaignStudentId,
                $request->targetCampaignGroupIds,
                $request->firstSubmittedAt,
                $now,
                $now,
            )
        );
    }

    private function requireOpenCampaign(int $campaignId): \LBonnefond\TdSwap\Model\Campaign
    {
        $campaign = $this->campaignRepository->findById($campaignId);

        if ($campaign === null) {
            throw new RuntimeException(
                sprintf('Campagne introuvable : %d', $campaignId)
            );
        }

        if ($campaign->status !== 'open') {
            throw new DomainException(
                'Les demandes ne sont possibles que pour une campagne open.'
            );
        }

        return $campaign;
    }

    /**
     * @return array{profile_id: int, initial_group_id: int}
     */
    private function findCampaignStudent(
        int $campaignId,
        int $campaignStudentId,
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT profile_id, initial_group_id
             FROM campaign_students
             WHERE campaign_id = ?
               AND id = ?'
        );

        $statement->execute([
            $campaignId,
            $campaignStudentId,
        ]);

        $student = $statement->fetch(PDO::FETCH_ASSOC);

        if ($student === false) {
            throw new DomainException(
                'Étudiant introuvable dans cette campagne.'
            );
        }

        return [
            'profile_id' => (int) $student['profile_id'],
            'initial_group_id' => (int) $student['initial_group_id'],
        ];
    }

    /**
     * @param list<int> $targetCampaignGroupIds
     */
    private function validateTargets(
        int $campaignId,
        int $profileId,
        int $initialGroupId,
        array $targetCampaignGroupIds,
    ): void {
        if ($targetCampaignGroupIds === []) {
            throw new DomainException(
                'Une demande doit contenir au moins un groupe cible.'
            );
        }

        if (count($targetCampaignGroupIds) > 3) {
            throw new DomainException(
                'Une demande ne peut contenir plus de trois groupes cibles.'
            );
        }

        if (count($targetCampaignGroupIds) !== count(array_unique($targetCampaignGroupIds))) {
            throw new DomainException(
                'Les groupes cibles doivent être distincts.'
            );
        }

        if (in_array($initialGroupId, $targetCampaignGroupIds, true)) {
            throw new DomainException(
                'Le groupe actuel ne peut pas être une destination.'
            );
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($targetCampaignGroupIds), '?')
        );

        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM campaign_profile_groups
             WHERE campaign_id = ?
               AND profile_id = ?
               AND campaign_group_id IN ($placeholders)"
        );

        $statement->execute([
            $campaignId,
            $profileId,
            ...$targetCampaignGroupIds,
        ]);

        $eligibleCount = (int) $statement->fetchColumn();

        if ($eligibleCount !== count($targetCampaignGroupIds)) {
            throw new DomainException(
                'Au moins un groupe cible n’est pas autorisé pour le profil de l’étudiant.'
            );
        }
    }
}
