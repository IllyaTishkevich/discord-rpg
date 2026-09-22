import { apiFetch } from "./client";
import type { EquipmentItem, InventoryItem, PurchaseResult } from "../types/equipment";

export function fetchShopItems(): Promise<EquipmentItem[]> {
  return apiFetch<EquipmentItem[]>("/equipment");
}

export function fetchInventory(): Promise<InventoryItem[]> {
  return apiFetch<InventoryItem[]>("/equipment/inventory");
}

export function purchaseItem(code: string): Promise<PurchaseResult> {
  return apiFetch<PurchaseResult>(`/equipment/${code}/purchase`, { method: "POST" });
}
