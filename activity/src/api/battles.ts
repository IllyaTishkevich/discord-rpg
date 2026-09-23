import { apiFetch } from "./client";
import type { AbilityChoice, BattleState, ExchangeMoveResponse, RoundResult, SubmitActionsResponse, ThrowResult } from "../types/battle";

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

/**
 * PvE/event only, interactive step-by-step flow (docs/COMBAT_V2_DESIGN.md
 * §7-8): submit one lead-or-respond decision — the server figures out
 * which from the battle's own state. Empty `indices` means "pass" and is
 * only valid when responding to an incoming attack.
 */
export function submitExchangeMove(battleId: number, indices: number[], ability?: AbilityChoice): Promise<ExchangeMoveResponse> {
  return apiFetch<ExchangeMoveResponse>(`/battles/${battleId}/exchanges/move`, {
    method: "POST",
    body: JSON.stringify({ indices, ...(ability ?? {}) }),
  });
}

export function submitActions(battleId: number, choice: AbilityChoice): Promise<SubmitActionsResponse> {
  return apiFetch<SubmitActionsResponse>(`/battles/${battleId}/submit-actions`, {
    method: "POST",
    body: JSON.stringify(choice),
  });
}

export function fetchLatestRound(battleId: number): Promise<RoundResult | null> {
  return apiFetch<RoundResult | null>(`/battles/${battleId}/rounds/latest`);
}
