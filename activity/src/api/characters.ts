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

export interface DuelCandidate {
  discordId: string;
  displayName: string;
  level: number;
  className: string;
}

/**
 * Which of the given Discord IDs (e.g. from a voice channel's participant
 * list — see discord/sdk.ts's fetchVoiceChannelParticipants()) have a
 * character at all — never includes the caller's own entry.
 */
export function lookupCharactersByDiscordIds(discordIds: string[]): Promise<DuelCandidate[]> {
  return apiFetch<DuelCandidate[]>("/characters/by-discord-ids", {
    method: "POST",
    body: JSON.stringify({ discordIds }),
  });
}
