import type { BitFace } from "./character";

export type AbilityType = "flip" | "unblockable_damage" | "reroll" | "damage_mirror";

export interface AbilityChoice {
  ability: AbilityType;
  targets: number[];
}

export interface BattleState {
  id: number;
  mode?: "pve" | "event" | "pvp";
  status: "waiting" | "in_progress" | "won" | "lost" | "abandoned";
  roundNumber: number;
  hasPendingThrow: boolean;
  opponent: { name: string | null; hp: number | null; maxHp: number | null };
  character: { hp: number; maxHp: number };
  rewards: { xp: number; coins: number } | null;
  // PvP only:
  youReady?: boolean;
  opponentReady?: boolean;
  opponentAccepted?: boolean;
  youSubmitted?: boolean;
  opponentSubmitted?: boolean;
}

export type ExchangeTurn = "lead" | "respond";

export interface IncomingMove {
  face: BitFace;
  count: number;
}

export interface ThrowResult {
  playerFaces: BitFace[];
  opponentFaces: BitFace[];
  playerActionCount: number;
  // PvE/event interactive flow only (docs/COMBAT_V2_DESIGN.md §7-8) — null for PvP.
  turn: ExchangeTurn | null;
  incomingMove: IncomingMove | null;
  playerUsed: boolean[] | null;
  opponentUsed: boolean[] | null;
}

export interface Exchange {
  leaderIsPlayer: boolean;
  leaderFace: BitFace;
  leaderCount: number;
  responderFace: BitFace | null;
  responderCount: number;
  damageToPlayer: number;
  damageToOpponent: number;
}

export interface RoundResult {
  roundNumber: number;
  playerFaces: BitFace[];
  opponentFaces: BitFace[];
  damageToOpponent: number;
  damageToPlayer: number;
  // Step-by-step log from the combat v2 exchange engine (PvE/event) — empty
  // for PvP, which still resolves the whole round in one simultaneous tally.
  exchanges: Exchange[];
}

export interface SubmitActionsResponse {
  waitingForOpponent?: true;
  round?: RoundResult;
  battle?: BattleState;
}

/**
 * Response from POST /battles/{id}/exchanges/move — the interactive
 * PvE/event flow (docs/COMBAT_V2_DESIGN.md §7-8). `newExchanges` holds only
 * what was resolved by *this* call (it can be more than one — the bot may
 * play several solo exchanges in a row once the player's hand is spent).
 */
export interface ExchangeMoveResponse {
  roundComplete: boolean;
  newExchanges: Exchange[];
  playerFaces: BitFace[];
  opponentFaces: BitFace[];
  playerUsed: boolean[];
  opponentUsed: boolean[];
  round?: RoundResult;
  turn?: ExchangeTurn;
  incomingMove?: IncomingMove | null;
  battle: BattleState;
}
