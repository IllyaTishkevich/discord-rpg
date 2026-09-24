import type { BitFace } from "./character";

export type AbilityType = "flip" | "unblockable_damage" | "reroll" | "damage_mirror" | "destroy" | "double";

export interface AbilityChoice {
  ability: AbilityType;
  targets: number[];
}

// The ability catalog — admin-editable player-facing name/description, from
// GET /api/abilities (App\Entity\Ability::$label/$description). Not the
// same as Character.abilities (bare AbilityType[] — which ones a character
// can use), this is what to actually show for each one.
export interface AbilityCatalogEntry {
  type: AbilityType;
  label: string;
  description: string | null;
}

export interface BattleState {
  id: number;
  mode?: "pve" | "event" | "pvp";
  status: "waiting" | "in_progress" | "won" | "lost" | "abandoned";
  roundNumber: number;
  hasPendingThrow: boolean;
  // Deadline for whoever currently owes the next lead/respond move — null
  // when nothing is pending (e.g. between rounds). ISO 8601.
  roundDeadlineAt: string | null;
  opponent: {
    name: string | null;
    hp: number | null;
    maxHp: number | null;
    // PvE/event: the linked catalog Monster's icon (via getIconUrl("monsters", iconName))
    // and level, or both null (pre-catalog fallback opponent, or an Event).
    iconName: string | null;
    level: number | null;
    // PvP only: the opponent's Discord avatar (ready-to-use URL) and class name.
    avatarUrl: string | null;
    className: string | null;
    // PvP only: decorative frame overlaid on top of avatarUrl when set (getIconUrl("frames", ...)) — see CombatantBar.tsx.
    frameName: string | null;
  };
  character: { hp: number; maxHp: number };
  rewards: { xp: number; coins: number } | null;
  // PvP only:
  youReady?: boolean;
  opponentReady?: boolean;
  opponentAccepted?: boolean;
  // From GET /battles/{id} — a fresh snapshot of the pending exchange (if
  // any), so a client can see faces/used/turn update without a throw of its
  // own: for PvP, picking up the other duelist's move via polling; for
  // PvE/event, re-syncing after TurnTimer's onExpire triggers the server's
  // lazy timeout check (see BattleService::syncExchangeState()).
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
  icon: string | null;
}

export interface ThrowResult {
  playerFaces: BitFace[];
  opponentFaces: BitFace[];
  // Damage/blocking/action-points each currently-shown face is worth — same
  // index order as the faces above, 1 for an ordinary bit.
  playerMultipliers: number[];
  opponentMultipliers: number[];
  // Per-bit icon filename (App\Entity\Bit::$iconAName/$iconBName), or null
  // to fall back to the generic per-face art — same index order as the
  // faces above (getIconUrl("bits", ...)) — see BitCoin.tsx.
  playerIcons: (string | null)[];
  opponentIcons: (string | null)[];
  playerActionCount: number;
  // Interactive exchange flow (docs/COMBAT_V2_DESIGN.md §7-8) — populated
  // for PvE/event and PvP alike.
  turn: ExchangeTurn | null;
  incomingMove: IncomingMove | null;
  playerUsed: boolean[] | null;
  opponentUsed: boolean[] | null;
}

/**
 * Response from POST /battles/{id}/throw specifically — same shape as
 * ThrowResult, plus a fresh BattleState snapshot (so the client picks up
 * the just-set roundDeadlineAt). Not part of ThrowResult itself: that type
 * is reused as-is for BattleState.exchange's shape (the PvP polling
 * snapshot), which does *not* carry a nested `battle`.
 */
export interface ThrowRoundResponse extends ThrowResult {
  battle: BattleState;
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

export interface DroppedItem {
  name: string;
  iconName: string | null;
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
  // Loot rolled this round (always [] except the round that actually
  // finishes off a PvE/event monster — PvP never drops anything).
  itemsDropped: DroppedItem[];
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
  playerIcons: (string | null)[];
  opponentIcons: (string | null)[];
  round?: RoundResult;
  turn?: ExchangeTurn;
  incomingMove?: IncomingMove | null;
  battle: BattleState;
}
