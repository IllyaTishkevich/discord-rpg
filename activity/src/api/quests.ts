import { apiFetch } from "./client";
import type { CurrentQuest } from "../types/quest";

export function fetchCurrentQuest(): Promise<CurrentQuest | null> {
  return apiFetch<CurrentQuest | null>("/quests/current");
}

export function claimCurrentQuest(): Promise<{ xp: number; coins: number }> {
  return apiFetch<{ xp: number; coins: number }>("/quests/current/claim", { method: "POST" });
}
