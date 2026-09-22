import type { BitFace } from "./character";

export interface BattleState {
  id: number;
  status: "in_progress" | "won" | "lost";
  roundNumber: number;
  hasPendingThrow: boolean;
  opponent: { name: string; hp: number; maxHp: number };
  character: { hp: number; maxHp: number };
  rewards: { xp: number; coins: number } | null;
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
