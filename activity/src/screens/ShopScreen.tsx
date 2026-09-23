import { useEffect, useState } from "react";
import { fetchMyCharacter } from "../api/characters";
import { getIconUrl } from "../api/client";
import { fetchItems, purchaseItem } from "../api/items";
import type { Character } from "../types/character";
import type { CatalogItem, ItemType } from "../types/item";
import "./ShopScreen.css";

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

export function ShopScreen({ character, onBack }: Props) {
  const [items, setItems] = useState<CatalogItem[] | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [coins, setCoins] = useState(character.coins);

  useEffect(() => {
    fetchItems()
      .then(setItems)
      .catch((err) => setError(err instanceof Error ? err.message : "Не удалось загрузить магазин."));
  }, []);

  async function handleBuy(item: CatalogItem) {
    setBusy(true);
    setError(null);
    try {
      const result = await purchaseItem(item.id);
      setCoins(result.coins);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось купить предмет.");
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

  const selectedItem = items?.find((item) => item.id === selectedId) ?? null;

  return (
    <div className="shop">
      <div className="shop__header">
        <h1>Магазин</h1>
        <span>{coins} монет</span>
      </div>

      {!items && !error && <p>Загрузка...</p>}
      {error && <p className="shop__error">{error}</p>}

      {items && (
        <div className="shop__board">
          <div className="shop__grid">
            {items.map((item) => (
              <button
                key={item.id}
                type="button"
                className={["shop__cell", selectedId === item.id ? "shop__cell--selected" : ""].filter(Boolean).join(" ")}
                onClick={() => setSelectedId(item.id)}
              >
                {item.iconName ? (
                  <img className="shop__icon" src={getIconUrl("items", item.iconName)} alt={item.name} />
                ) : (
                  <span className="shop__icon shop__icon--placeholder" aria-hidden="true">
                    ?
                  </span>
                )}
              </button>
            ))}
          </div>

          <div className="shop__details">
            {selectedItem ? (
              <>
                <h2>{selectedItem.name}</h2>
                <p className="shop__type">{TYPE_LABEL[selectedItem.type]}</p>
                <p className="shop__description">{selectedItem.description ?? "Нет описания."}</p>
                <button className="shop__buy" disabled={busy || coins < selectedItem.price} onClick={() => handleBuy(selectedItem)}>
                  Купить — {selectedItem.price} монет
                </button>
              </>
            ) : (
              <p className="shop__hint">Выбери предмет</p>
            )}
          </div>
        </div>
      )}

      <button className="shop__back" onClick={handleBack}>
        Назад
      </button>
    </div>
  );
}
