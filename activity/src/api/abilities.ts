import { apiFetch } from "./client";
import type { AbilityCatalogEntry } from "../types/battle";

export function fetchAbilities(): Promise<AbilityCatalogEntry[]> {
  return apiFetch<AbilityCatalogEntry[]>("/abilities");
}
