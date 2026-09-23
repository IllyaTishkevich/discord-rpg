import { apiFetch } from "./client";
import type { AbilityChoice, BattleState, ExchangeMoveResponse, RoundResult, ThrowRoundResponse } from "../types/battle";

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

export function throwRound(battleId: number): Promise<ThrowRoundResponse> {
  return apiFetch<ThrowRoundResponse>(`/battles/${battleId}/throw`, { method: "POST" });
}

/**
 * Interactive step-by-step flow (docs/COMBAT_V2_DESIGN.md §7-8), for
 * PvE/event and PvP alike: submit one lead-or-respond decision — the server
 * figures out which from the battle's own state. Empty `indices` means
 * "pass" and is only valid when responding to an incoming attack (or,
 * PvP-only, declining to lead this exchange).
 */
export function submitExchangeMove(battleId: number, indices: number[], ability?: AbilityChoice): Promise<ExchangeMoveResponse> {
  return apiFetch<ExchangeMoveResponse>(`/battles/${battleId}/exchanges/move`, {
    method: "POST",
    body: JSON.stringify({ indices, ...(ability ?? {}) }),
  });
}

export function fetchLatestRound(battleId: number): Promise<RoundResult | null> {
  return apiFetch<RoundResult | null>(`/battles/${battleId}/rounds/latest`);
}
