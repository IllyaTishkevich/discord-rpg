import { StatBar } from "../components/StatBar";
import type { Character } from "../types/character";
import "./ProfileScreen.css";

interface Props {
  character: Character;
  onStartBattle: () => void;
  onOpenShop: () => void;
  onOpenInventory: () => void;
}

export function ProfileScreen({ character, onStartBattle, onOpenShop, onOpenInventory }: Props) {
  const canFight = character.energy > 0;

  return (
    <div className="profile">
      <h1>{character.class.name}</h1>
      <p className="profile__level">Уровень {character.level}</p>
      <StatBar label="HP" value={character.hp} max={character.maxHp} variant="hp" />
      <StatBar label="Энергия" value={character.energy} max={character.maxEnergy} variant="energy" />
      <div className="profile__row">
        <span>XP: {character.xp}</span>
        <span>Монеты: {character.coins}</span>
      </div>
      <div className="profile__actions">
        <button className="profile__fight" disabled={!canFight} onClick={onStartBattle}>
          {canFight ? "В бой" : "Нет энергии"}
        </button>
        <button className="profile__secondary" onClick={onOpenShop}>
          Магазин
        </button>
        <button className="profile__secondary" onClick={onOpenInventory}>
          Инвентарь
        </button>
      </div>
    </div>
  );
}
