import { apiFetch } from "./client";
import type { InventoryActionResult, InventoryState } from "../types/item";

export function fetchInventory(): Promise<InventoryState> {
  return apiFetch<InventoryState>("/inventory");
}

export function useInventoryItem(id: number): Promise<InventoryActionResult> {
  return apiFetch<InventoryActionResult>(`/inventory/${id}/use`, { method: "POST" });
}

export function sellInventoryItem(id: number): Promise<InventoryActionResult> {
  return apiFetch<InventoryActionResult>(`/inventory/${id}/sell`, { method: "POST" });
}

export function discardInventoryItem(id: number): Promise<InventoryActionResult> {
  return apiFetch<InventoryActionResult>(`/inventory/${id}/discard`, { method: "POST" });
}
