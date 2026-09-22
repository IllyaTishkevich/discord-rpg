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

export interface ThrowResult {
  playerFaces: BitFace[];
  opponentFaces: BitFace[];
  playerActionCount: number;
}

export interface RoundResult {
  roundNumber: number;
  playerFaces: BitFace[];
  opponentFaces: BitFace[];
  damageToOpponent: number;
  damageToPlayer: number;
}

export interface ResolveResponse {
  round: RoundResult;
  battle: BattleState;
}

export interface SubmitActionsResponse {
  waitingForOpponent?: true;
  round?: RoundResult;
  battle?: BattleState;
}
