import { apiFetch } from "./client";
import type { Tournament } from "../types/tournament";

export function fetchOpenTournament(): Promise<Tournament | null> {
  return apiFetch<Tournament | null>("/tournaments/open");
}

export function fetchLatestTournament(): Promise<Tournament | null> {
  return apiFetch<Tournament | null>("/tournaments/latest");
}

export function registerForTournament(id: number): Promise<Tournament> {
  return apiFetch<Tournament>(`/tournaments/${id}/register`, { method: "POST" });
}
