import { useEffect, useState } from "react";
import { discardInventoryItem, fetchInventory, sellInventoryItem, useInventoryItem } from "../api/inventory";
import { fetchMyCharacter } from "../api/characters";
import { IMAGE_BASE_URL } from "../api/client";
import type { Character } from "../types/character";
import type { InventoryActionResult, InventoryItem, InventoryState, ItemType } from "../types/item";
import "./InventoryScreen.css";

interface Props {
  character: Character;
  onBack: (character: Character) => void;
}

const TYPE_LABEL: Record<ItemType, string> = {
  weapon: "Оружие",
  shield: "Щит",
  armor: "Доспех",
  bag: "Сумка",
  potion: "Зелье",
  scroll: "Свиток",
};

export function InventoryScreen({ character, onBack }: Props) {
  const [inventory, setInventory] = useState<InventoryState | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [coins, setCoins] = useState(character.coins);

  useEffect(() => {
    fetchInventory()
      .then(setInventory)
      .catch((err) => setError(err instanceof Error ? err.message : "Не удалось загрузить инвентарь."));
  }, []);

  function applyResult(result: InventoryActionResult) {
    setInventory({ capacity: result.capacity, items: result.items });
    setCoins(result.coins);
  }

  async function runAction(id: number, action: (id: number) => Promise<InventoryActionResult>) {
    setBusy(true);
    setError(null);
    try {
      const result = await action(id);
      applyResult(result);
      // Selling/discarding removes the row; using an equippable item keeps
      // it around (equipped), using a consumable removes it — either way,
      // re-select only if that id is still present.
      setSelectedId((current) => (current !== null && result.items.some((item) => item.id === current) ? current : null));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось выполнить действие.");
    } finally {
      setBusy(false);
    }
  }

  async function handleBack() {
    try {
      onBack(await fetchMyCharacter());
    } catch {
      onBack(character);
    }
  }

  const items = inventory?.items ?? [];
  const capacity = inventory?.capacity ?? 12;
  const cells: (InventoryItem | null)[] = [...items, ...Array.from({ length: Math.max(0, capacity - items.length) }, () => null)];
  const selectedItem = items.find((item) => item.id === selectedId) ?? null;

  return (
    <div className="inventory">
      <div className="inventory__header">
        <h1>Инвентарь</h1>
        <span>{coins} монет</span>
      </div>

      {!inventory && !error && <p>Загрузка...</p>}
      {error && <p className="inventory__error">{error}</p>}

      {inventory && (
        <div className="inventory__board">
          <div className="inventory__grid">
            {cells.map((item, index) => (
              <button
                key={item ? item.id : `empty-${index}`}
                type="button"
                className={[
                  "inventory__cell",
                  item ? "" : "inventory__cell--empty",
                  item?.equipped ? "inventory__cell--equipped" : "",
                  item && selectedId === item.id ? "inventory__cell--selected" : "",
                ]
                  .filter(Boolean)
                  .join(" ")}
                disabled={!item}
                onClick={() => item && setSelectedId(item.id)}
              >
                {item &&
                  (item.iconName ? (
                    <img className="inventory__icon" src={`${IMAGE_BASE_URL}/uploads/items/${item.iconName}`} alt={item.name} />
                  ) : (
                    <span className="inventory__icon inventory__icon--placeholder" aria-hidden="true">
                      ?
                    </span>
                  ))}
              </button>
            ))}
          </div>

          <div className="inventory__details">
            {selectedItem ? (
              <>
                <h2>{selectedItem.name}</h2>
                <p className="inventory__type">
                  {TYPE_LABEL[selectedItem.type]}
                  {selectedItem.equipped ? " · надето" : ""}
                </p>
                <p className="inventory__description">{selectedItem.description ?? "Нет описания."}</p>
                <div className="inventory__actions">
                  <button disabled={busy} onClick={() => runAction(selectedItem.id, useInventoryItem)}>
                    Использовать
                  </button>
                  <button disabled={busy} onClick={() => runAction(selectedItem.id, sellInventoryItem)}>
                    Продать
                  </button>
                  <button disabled={busy} onClick={() => runAction(selectedItem.id, discardInventoryItem)}>
                    Выбросить
                  </button>
                </div>
              </>
            ) : (
              <p className="inventory__hint">Выбери предмет</p>
            )}
          </div>
        </div>
      )}

      <button className="inventory__back" onClick={handleBack}>
        Назад
      </button>
    </div>
  );
}
