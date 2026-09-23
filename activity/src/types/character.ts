import type { AbilityType } from "./battle";

// "empty" never activates — a bit showing it takes no part in the round at
// all (docs/COMBAT_V2_DESIGN.md §1).
export type BitFace = "attack" | "defense" | "action" | "empty";

export interface BitDefinition {
  faceA: BitFace;
  faceB: BitFace;
  multiplierA?: number;
  multiplierB?: number;
}

export interface CharacterClassSummary {
  code: string;
  name: string;
  description: string | null;
  baseHp: number;
  baseEnergy: number;
  starterBits: BitDefinition[];
}

export interface Character {
  id: number;
  displayName: string;
  // Discord CDN URL, or null if this user never set an avatar — show a
  // placeholder in that case, same as everywhere else in the app.
  avatarUrl: string | null;
  class: {
    code: string;
    name: string;
  };
  hp: number;
  maxHp: number;
  energy: number;
  maxEnergy: number;
  level: number;
  xp: number;
  coins: number;
  // Which combat abilities this character can currently choose from — see
  // BattleService::assertAbilityAvailable() on the backend, the actual
  // enforcement this list is meant to preview client-side.
  abilities: AbilityType[];
}
