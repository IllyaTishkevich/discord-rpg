import { apiFetch } from "./client";
import type { BattleState, ResolveResponse, ThrowResult } from "../types/battle";

export function startPveBattle(): Promise<BattleState> {
  return apiFetch<BattleState>("/battles/pve", { method: "POST" });
}

export function fetchBattle(id: number): Promise<BattleState> {
  return apiFetch<BattleState>(`/battles/${id}`);
}

export function throwRound(battleId: number): Promise<ThrowResult> {
  return apiFetch<ThrowResult>(`/battles/${battleId}/throw`, { method: "POST" });
}

export function resolveRound(battleId: number, actionTargets: number[]): Promise<ResolveResponse> {
  return apiFetch<ResolveResponse>(`/battles/${battleId}/resolve`, {
    method: "POST",
    body: JSON.stringify({ actionTargets }),
  });
}
