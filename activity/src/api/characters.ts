import { apiFetch } from "./client";
import type { Character, CharacterClassSummary } from "../types/character";

export function fetchCharacterClasses(): Promise<CharacterClassSummary[]> {
  return apiFetch<CharacterClassSummary[]>("/character-classes");
}

export function fetchMyCharacter(): Promise<Character> {
  return apiFetch<Character>("/characters/me");
}

export function createCharacter(classCode: string): Promise<Character> {
  return apiFetch<Character>("/characters", {
    method: "POST",
    body: JSON.stringify({ classCode }),
  });
}
