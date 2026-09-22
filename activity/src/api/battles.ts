import { apiFetch } from "./client";
import type { BattleState, ResolveResponse, RoundResult, SubmitActionsResponse, ThrowResult } from "../types/battle";

export function startPveBattle(): Promise<BattleState> {
  return apiFetch<BattleState>("/battles/pve", { method: "POST" });
}

export function fetchBattle(id: number): Promise<BattleState> {
  return apiFetch<BattleState>(`/battles/${id}`);
}

export function fetchMyActivePvp(): Promise<BattleState | null> {
  return apiFetch<BattleState | null>("/battles/my-active-pvp");
}

export function joinPvpBattle(battleId: number): Promise<BattleState> {
  return apiFetch<BattleState>(`/battles/${battleId}/join`, { method: "POST" });
}

export function declinePvpBattle(battleId: number): Promise<BattleState> {
  return apiFetch<BattleState>(`/battles/${battleId}/decline`, { method: "POST" });
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

export function submitActions(battleId: number, actionTargets: number[]): Promise<SubmitActionsResponse> {
  return apiFetch<SubmitActionsResponse>(`/battles/${battleId}/submit-actions`, {
    method: "POST",
    body: JSON.stringify({ actionTargets }),
  });
}

export function fetchLatestRound(battleId: number): Promise<RoundResult | null> {
  return apiFetch<RoundResult | null>(`/battles/${battleId}/rounds/latest`);
}
