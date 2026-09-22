export type BitFace = "attack" | "defense" | "action";

export interface BitDefinition {
  faceA: BitFace;
  faceB: BitFace;
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
}
