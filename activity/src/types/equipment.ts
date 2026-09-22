import type { BitFace } from "./character";

export interface EquipmentItem {
  code: string;
  name: string;
  description: string | null;
  price: number;
  effectType: "bit" | "hp";
  bitFaceA: BitFace | null;
  bitFaceB: BitFace | null;
  hpBonus: number | null;
}

export interface InventoryItem extends EquipmentItem {
  purchasedAt: string;
}

export interface PurchaseResult {
  coins: number;
  maxHp: number;
  hp: number;
}
