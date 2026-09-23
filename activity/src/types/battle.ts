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
  // PvP only, from GET /battles/{id} — a fresh snapshot of the pending
  // exchange (if any), so a polling client can see faces/used/turn update
  // as the other duelist acts, without a throw of its own. Always present
  // (possibly null) on a PvP BattleState; absent for PvE/event.
  exchange?: ThrowResult | null;
}

// "wait" (PvP only): the other real duelist currently owns this decision —
// see BattleService::submitPvpExchangeMove() on the backend. "over" never
// actually appears in a response body (the server 409s instead) but is
// included for completeness with the backend's own type.
export type ExchangeTurn = "lead" | "respond" | "wait" | "over";

export interface IncomingMove {
  face: BitFace;
  count: number;
}

export interface ThrowResult {
  playerFaces: BitFace[];
  opponentFaces: BitFace[];
  // Damage/blocking/action-points each currently-shown face is worth — same
  // index order as the faces above, 1 for an ordinary bit.
  playerMultipliers: number[];
  opponentMultipliers: number[];
  playerActionCount: number;
  // Interactive exchange flow (docs/COMBAT_V2_DESIGN.md §7-8) — populated
  // for PvE/event and PvP alike.
  turn: ExchangeTurn | null;
  incomingMove: IncomingMove | null;
  playerUsed: boolean[] | null;
  opponentUsed: boolean[] | null;
}

export interface Exchange {
  leaderIsPlayer: boolean;
  leaderFace: BitFace;
  // Despite the name, this is the effective amount (sum of the activated
  // bits' multipliers) that drove the damage below, not a literal bit tally.
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
  // Step-by-step log from the combat v2 exchange engine — populated for
  // every mode now, including PvP.
  exchanges: Exchange[];
}

/**
 * Response from POST /battles/{id}/exchanges/move — the interactive flow
 * (docs/COMBAT_V2_DESIGN.md §7-8), for PvE/event and PvP alike.
 * `newExchanges` holds only what was resolved by *this* call: for PvE/event
 * it can be more than one (the bot may play several solo exchanges in a row
 * once the player's hand is spent); for PvP it's always at most one (the
 * other duelist's move comes back through a separate call of their own).
 */
export interface ExchangeMoveResponse {
  roundComplete: boolean;
  newExchanges: Exchange[];
  playerFaces: BitFace[];
  opponentFaces: BitFace[];
  playerUsed: boolean[];
  opponentUsed: boolean[];
  playerMultipliers: number[];
  opponentMultipliers: number[];
  round?: RoundResult;
  turn?: ExchangeTurn;
  incomingMove?: IncomingMove | null;
  battle: BattleState;
}
