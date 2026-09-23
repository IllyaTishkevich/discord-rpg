import { apiFetch } from "./client";
import type { EquipmentItem, PurchaseResult } from "../types/equipment";

export function fetchShopItems(): Promise<EquipmentItem[]> {
  return apiFetch<EquipmentItem[]>("/equipment");
}

export function purchaseItem(code: string): Promise<PurchaseResult> {
  return apiFetch<PurchaseResult>(`/equipment/${code}/purchase`, { method: "POST" });
}
