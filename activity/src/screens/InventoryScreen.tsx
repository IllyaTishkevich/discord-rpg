import { useEffect, useState } from "react";
import { fetchInventory } from "../api/equipment";
import type { InventoryItem } from "../types/equipment";
import { formatEquipmentEffect } from "../types/equipmentFormat";
import "./InventoryScreen.css";

interface Props {
  onBack: () => void;
}

export function InventoryScreen({ onBack }: Props) {
  const [items, setItems] = useState<InventoryItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchInventory()
      .then(setItems)
      .catch((err) => setError(err instanceof Error ? err.message : "Не удалось загрузить инвентарь."));
  }, []);

  return (
    <div className="inventory">
      <h1>Инвентарь</h1>

      {!items && <p>Загрузка...</p>}
      {error && <p className="inventory__error">{error}</p>}
      {items?.length === 0 && <p>Пока пусто — загляни в магазин.</p>}

      <div className="inventory__list">
        {items?.map((item, index) => (
          <div key={`${item.code}-${index}`} className="inventory__item">
            <h2>{item.name}</h2>
            <p>{formatEquipmentEffect(item)}</p>
          </div>
        ))}
      </div>

      <button className="inventory__back" onClick={onBack}>
        Назад
      </button>
    </div>
  );
}
