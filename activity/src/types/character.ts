import type { AbilityType } from "./battle";

export type BitFace = "attack" | "defense" | "action";

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
