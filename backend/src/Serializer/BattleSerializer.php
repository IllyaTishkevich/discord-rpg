<?php

namespace App\Serializer;

use App\Battle\ThrowResult;
use App\Entity\Battle;
use App\Entity\BattleRound;
use App\Enum\BattleStatus;
use App\Enum\BitFace;
use App\Service\BattleService;

/**
 * Shared JSON shape for Battle/BattleRound/ThrowResult.
 *
 * PvE/event battles have exactly one real viewer (`battle.character`), so
 * the plain battle()/round()/throwResult() methods below are unchanged and
 * still used as-is. PvP battles have two real viewers, and each must see
 * "you" vs "opponent" from their own side — the *ForViewer() methods do
 * that by swapping perspective when the caller is `battle.opponentCharacter`
 * rather than `battle.character`. See docs/BATTLE_ROOM_DESIGN.md §2.
 */
class BattleSerializer
{
    public function battle(Battle $battle): array
    {
        return [
            'id' => $battle->getId(),
            'status' => $battle->getStatus()->value,
            'roundNumber' => $battle->getRoundNumber(),
            'hasPendingThrow' => $battle->hasPendingThrow(),
            'opponent' => [
                'name' => $battle->getOpponentName(),
                'hp' => $battle->getOpponentHp(),
                'maxHp' => $battle->getOpponentMaxHp(),
            ],
            'character' => [
                'hp' => $battle->getCharacter()->getHp(),
                'maxHp' => $battle->getCharacter()->getMaxHp(),
            ],
            'rewards' => $this->rewardsFor($battle),
        ];
    }

    public function throwResult(ThrowResult $result): array
    {
        return [
            'playerFaces' => array_map(static fn ($t) => $t->thrownFace->value, $result->playerThrows),
            'opponentFaces' => array_map(static fn ($t) => $t->thrownFace->value, $result->opponentThrows),
            'playerActionCount' => $result->playerActionCount,
        ];
    }

    public function round(BattleRound $round): array
    {
        return [
            'roundNumber' => $round->getRoundNumber(),
            'playerFaces' => $round->getPlayerFaces(),
            'opponentFaces' => $round->getOpponentFaces(),
            'damageToOpponent' => $round->getDamageToOpponent(),
            'damageToPlayer' => $round->getDamageToPlayer(),
        ];
    }

    // -----------------------------------------------------------------
    // PvP: perspective-aware variants
    // -----------------------------------------------------------------

    public function battleForViewer(Battle $battle, bool $viewerIsOpponentSide): array
    {
        $you = $viewerIsOpponentSide ? $battle->getOpponentCharacter() : $battle->getCharacter();
        $opponent = $viewerIsOpponentSide ? $battle->getCharacter() : $battle->getOpponentCharacter();
        $status = $this->statusForViewer($battle->getStatus(), $viewerIsOpponentSide);

        return [
            'id' => $battle->getId(),
            'mode' => $battle->getMode()->value,
            'status' => $status->value,
            'roundNumber' => $battle->getRoundNumber(),
            'hasPendingThrow' => $battle->hasPendingThrow(),
            'opponent' => [
                'name' => $opponent?->getUser()->getDisplayName(),
                'hp' => $opponent?->getHp(),
                'maxHp' => $opponent?->getMaxHp(),
            ],
            'character' => [
                'hp' => $you->getHp(),
                'maxHp' => $you->getMaxHp(),
            ],
            'rewards' => BattleStatus::Won === $status
                ? ['xp' => BattleService::PVP_XP_REWARD, 'coins' => BattleService::PVP_COIN_REWARD]
                : null,
            'youReady' => $viewerIsOpponentSide ? $battle->isOpponentReady() : $battle->isCharacterReady(),
            'opponentReady' => $viewerIsOpponentSide ? $battle->isCharacterReady() : $battle->isOpponentReady(),
            'opponentAccepted' => $battle->isOpponentAccepted(),
            'youSubmitted' => null !== ($viewerIsOpponentSide ? $battle->getPendingOpponentActionTargets() : $battle->getPendingCharacterActionTargets()),
            'opponentSubmitted' => null !== ($viewerIsOpponentSide ? $battle->getPendingCharacterActionTargets() : $battle->getPendingOpponentActionTargets()),
        ];
    }

    public function throwResultForViewer(ThrowResult $result, bool $viewerIsOpponentSide): array
    {
        $yourThrows = $viewerIsOpponentSide ? $result->opponentThrows : $result->playerThrows;
        $theirThrows = $viewerIsOpponentSide ? $result->playerThrows : $result->opponentThrows;

        return [
            'playerFaces' => array_map(static fn ($t) => $t->thrownFace->value, $yourThrows),
            'opponentFaces' => array_map(static fn ($t) => $t->thrownFace->value, $theirThrows),
            'playerActionCount' => \count(array_filter($yourThrows, static fn ($t) => BitFace::Action === $t->thrownFace)),
        ];
    }

    public function roundForViewer(BattleRound $round, bool $viewerIsOpponentSide): array
    {
        return [
            'roundNumber' => $round->getRoundNumber(),
            'playerFaces' => $viewerIsOpponentSide ? $round->getOpponentFaces() : $round->getPlayerFaces(),
            'opponentFaces' => $viewerIsOpponentSide ? $round->getPlayerFaces() : $round->getOpponentFaces(),
            'damageToOpponent' => $viewerIsOpponentSide ? $round->getDamageToPlayer() : $round->getDamageToOpponent(),
            'damageToPlayer' => $viewerIsOpponentSide ? $round->getDamageToOpponent() : $round->getDamageToPlayer(),
        ];
    }

    private function statusForViewer(BattleStatus $status, bool $viewerIsOpponentSide): BattleStatus
    {
        if (!$viewerIsOpponentSide) {
            return $status;
        }

        return match ($status) {
            BattleStatus::Won => BattleStatus::Lost,
            BattleStatus::Lost => BattleStatus::Won,
            default => $status,
        };
    }

    private function rewardsFor(Battle $battle): ?array
    {
        if (BattleStatus::Won !== $battle->getStatus()) {
            return null;
        }

        $isEvent = null !== $battle->getEvent();

        return [
            'xp' => $isEvent ? BattleService::EVENT_XP_REWARD : BattleService::XP_REWARD,
            'coins' => $isEvent ? BattleService::EVENT_COIN_REWARD : BattleService::COIN_REWARD,
        ];
    }
}
