import { apiFetch } from "./client";
import type { BattleState } from "../types/battle";
import type { ActiveEvent } from "../types/event";

export function fetchActiveEvent(): Promise<ActiveEvent | null> {
  return apiFetch<ActiveEvent | null>("/events/active");
}

export function startEventBattle(): Promise<BattleState> {
  return apiFetch<BattleState>("/events/active/battle", { method: "POST" });
}
