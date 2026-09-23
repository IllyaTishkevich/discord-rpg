export type ItemType = "weapon" | "shield" | "armor" | "bag" | "potion" | "scroll";
export type ItemEffectType = "add_bit" | "add_ability" | "increase_capacity" | "heal" | "restore_energy" | "grant_xp";

export interface InventoryItem {
  id: number;
  name: string;
  description: string | null;
  type: ItemType;
  effectType: ItemEffectType;
  iconName: string | null; // null → frontend shows the placeholder
  equipped: boolean;
}

export interface InventoryState {
  capacity: number;
  items: InventoryItem[];
}

export interface InventoryActionResult extends InventoryState {
  coins: number;
  hp: number;
  energy: number;
  xp: number;
}

// The shop's catalog — every Item that exists (App\Controller\ItemController::list()),
// as opposed to InventoryItem above, which is one a character actually owns.
export interface CatalogItem {
  id: number;
  name: string;
  description: string | null;
  price: number;
  type: ItemType;
  effectType: ItemEffectType;
  iconName: string | null;
}
