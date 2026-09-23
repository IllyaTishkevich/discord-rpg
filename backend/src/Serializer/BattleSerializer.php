<?php

namespace App\Serializer;

use App\Battle\ExchangeMoveResult;
use App\Battle\ThrowResult;
use App\Entity\Battle;
use App\Entity\BattleRound;
use App\Enum\BattleStatus;
use App\Enum\BitFace;
use App\Service\BattleService;

/**
 * Shared JSON shape for Battle/BattleRound/ThrowResult/ExchangeMoveResult.
 *
 * PvE/event battles have exactly one real viewer (`battle.character`), so
 * the plain battle()/round()/throwResult() methods below are unchanged and
 * still used as-is. PvP battles have two real viewers, and each must see
 * "you" vs "opponent" from their own side — the *ForViewer() methods do
 * that by swapping perspective when the caller is `battle.opponentCharacter`
 * rather than `battle.character`. See docs/BATTLE_ROOM_DESIGN.md §2.
 *
 * BattleService always builds these DTOs in a *fixed* frame — "player" is
 * always battle.character, "opponent" is always battle.opponentCharacter
 * (or the bot, for PvE/event) — regardless of who's actually asking. Only
 * the `turn`/`incomingMove`/`exchanges` fields need special handling beyond
 * a simple swap: see turnForViewer()'s docblock for why.
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
            // Interactive exchange flow (docs/COMBAT_V2_DESIGN.md §7-8) —
            // this method is only ever called for PvE/event (single real
            // viewer, so "player" already means "you"); PvP always goes
            // through throwResultForViewer() instead.
            'turn' => $result->turn,
            'incomingMove' => $result->incomingMove,
            'playerUsed' => $result->playerUsed,
            'opponentUsed' => $result->opponentUsed,
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
            'exchanges' => $round->getExchanges(),
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
        ];
    }

    public function throwResultForViewer(ThrowResult $result, bool $viewerIsOpponentSide): array
    {
        $yourThrows = $viewerIsOpponentSide ? $result->opponentThrows : $result->playerThrows;
        $theirThrows = $viewerIsOpponentSide ? $result->playerThrows : $result->opponentThrows;
        $yourUsed = $viewerIsOpponentSide ? $result->opponentUsed : $result->playerUsed;
        $theirUsed = $viewerIsOpponentSide ? $result->playerUsed : $result->opponentUsed;
        $hasPendingLeaderMove = null !== $result->incomingMove;

        return [
            'playerFaces' => array_map(static fn ($t) => $t->thrownFace->value, $yourThrows),
            'opponentFaces' => array_map(static fn ($t) => $t->thrownFace->value, $theirThrows),
            'playerActionCount' => \count(array_filter($yourThrows, static fn ($t) => BitFace::Action === $t->thrownFace)),
            'turn' => $this->turnForViewer($result->turn, $hasPendingLeaderMove, $viewerIsOpponentSide),
            // Symmetric — both sides see the same pending move description,
            // it's who currently owes the response that differs (see `turn`).
            'incomingMove' => $result->incomingMove,
            'playerUsed' => $yourUsed,
            'opponentUsed' => $theirUsed,
        ];
    }

    public function exchangeMoveResultForViewer(ExchangeMoveResult $result, bool $viewerIsOpponentSide): array
    {
        $yourFaces = $viewerIsOpponentSide ? $result->opponentFaces : $result->playerFaces;
        $theirFaces = $viewerIsOpponentSide ? $result->playerFaces : $result->opponentFaces;
        $yourUsed = $viewerIsOpponentSide ? $result->opponentUsed : $result->playerUsed;
        $theirUsed = $viewerIsOpponentSide ? $result->playerUsed : $result->opponentUsed;
        $hasPendingLeaderMove = null !== $result->incomingMove;

        return [
            'roundComplete' => $result->roundComplete,
            'newExchanges' => array_map(fn (array $e) => $this->exchangeForViewer($e, $viewerIsOpponentSide), $result->newExchanges),
            'playerFaces' => $yourFaces,
            'opponentFaces' => $theirFaces,
            'playerUsed' => $yourUsed,
            'opponentUsed' => $theirUsed,
            'turn' => $this->turnForViewer($result->turn, $hasPendingLeaderMove, $viewerIsOpponentSide),
            'incomingMove' => $result->incomingMove,
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
            'exchanges' => array_map(fn (array $e) => $this->exchangeForViewer($e, $viewerIsOpponentSide), $round->getExchanges()),
        ];
    }

    /**
     * $characterTurn is always fixed-frame ("battle.character"'s status —
     * see BattleService::throwResultFromThrows()/submitPvpExchangeMove()).
     * For a `battle.character`-side viewer that's already correct as-is.
     * For an opponent-side viewer, 'lead'/'respond' simply invert to 'wait'
     * (the character being active means the opponent, by construction,
     * never is at the same time) — but inverting 'wait' back is genuinely
     * ambiguous (it could mean the opponent must lead fresh, or respond to
     * a lead the character just committed) without knowing whether a lead
     * move is currently pending, which $hasPendingLeaderMove resolves.
     *
     * @param 'lead'|'respond'|'wait'|'over'|null $characterTurn
     *
     * @return 'lead'|'respond'|'wait'|'over'|null
     */
    private function turnForViewer(?string $characterTurn, bool $hasPendingLeaderMove, bool $viewerIsOpponentSide): ?string
    {
        if (null === $characterTurn || !$viewerIsOpponentSide) {
            return $characterTurn;
        }

        return match ($characterTurn) {
            'over' => 'over',
            'wait' => $hasPendingLeaderMove ? 'respond' : 'lead',
            default => 'wait',
        };
    }

    /**
     * @param array{leaderIsPlayer: bool, leaderFace: string, leaderCount: int, responderFace: ?string, responderCount: int, damageToPlayer: int, damageToOpponent: int} $exchange
     *
     * @return array{leaderIsPlayer: bool, leaderFace: string, leaderCount: int, responderFace: ?string, responderCount: int, damageToPlayer: int, damageToOpponent: int}
     */
    private function exchangeForViewer(array $exchange, bool $viewerIsOpponentSide): array
    {
        if (!$viewerIsOpponentSide) {
            return $exchange;
        }

        return [
            'leaderIsPlayer' => !$exchange['leaderIsPlayer'],
            'leaderFace' => $exchange['leaderFace'],
            'leaderCount' => $exchange['leaderCount'],
            'responderFace' => $exchange['responderFace'],
            'responderCount' => $exchange['responderCount'],
            'damageToPlayer' => $exchange['damageToOpponent'],
            'damageToOpponent' => $exchange['damageToPlayer'],
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
