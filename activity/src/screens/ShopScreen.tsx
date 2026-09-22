import { useEffect, useState } from "react";
import { fetchShopItems, purchaseItem } from "../api/equipment";
import { fetchMyCharacter } from "../api/characters";
import type { Character } from "../types/character";
import type { EquipmentItem } from "../types/equipment";
import { formatEquipmentEffect } from "../types/equipmentFormat";
import "./ShopScreen.css";

interface Props {
  character: Character;
  onBack: (character: Character) => void;
}

export function ShopScreen({ character, onBack }: Props) {
  const [items, setItems] = useState<EquipmentItem[] | null>(null);
  const [coins, setCoins] = useState(character.coins);
  const [busyCode, setBusyCode] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchShopItems()
      .then(setItems)
      .catch((err) => setError(err instanceof Error ? err.message : "Не удалось загрузить магазин."));
  }, []);

  async function handleBuy(item: EquipmentItem) {
    setBusyCode(item.code);
    setError(null);
    try {
      const result = await purchaseItem(item.code);
      setCoins(result.coins);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось купить предмет.");
    } finally {
      setBusyCode(null);
    }
  }

  async function handleBack() {
    try {
      onBack(await fetchMyCharacter());
    } catch {
      onBack(character);
    }
  }

  return (
    <div className="shop">
      <div className="shop__header">
        <h1>Магазин</h1>
        <span>{coins} монет</span>
      </div>

      {!items && <p>Загрузка...</p>}
      {error && <p className="shop__error">{error}</p>}

      <div className="shop__list">
        {items?.map((item) => (
          <div key={item.code} className="shop__item">
            <div>
              <h2>{item.name}</h2>
              <p>{formatEquipmentEffect(item)}</p>
            </div>
            <button disabled={coins < item.price || busyCode === item.code} onClick={() => handleBuy(item)}>
              {item.price} монет
            </button>
          </div>
        ))}
      </div>

      <button className="shop__back" onClick={handleBack}>
        Назад
      </button>
    </div>
  );
}
