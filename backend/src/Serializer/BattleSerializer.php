<?php

namespace App\Serializer;

use App\Battle\ThrowResult;
use App\Entity\Battle;
use App\Entity\BattleRound;
use App\Enum\BattleStatus;
use App\Service\BattleService;

/**
 * Shared JSON shape for Battle/BattleRound/ThrowResult, used by both the
 * user-facing BattleController/EventController and reused wherever else a
 * battle needs to cross the API boundary.
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
