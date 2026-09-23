import { apiFetch } from "./client";
import type { CatalogItem } from "../types/item";

export function fetchItems(): Promise<CatalogItem[]> {
  return apiFetch<CatalogItem[]>("/items");
}

export function purchaseItem(id: number): Promise<{ coins: number }> {
  return apiFetch<{ coins: number }>(`/items/${id}/purchase`, { method: "POST" });
}
