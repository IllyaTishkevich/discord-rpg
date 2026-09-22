import type { Character } from "../types/character";
import "./ProfileScreen.css";

interface Props {
  character: Character;
}

function StatBar({ label, value, max }: { label: string; value: number; max: number }) {
  const percent = max > 0 ? Math.round((value / max) * 100) : 0;
  return (
    <div className="stat-bar">
      <div className="stat-bar__label">
        <span>{label}</span>
        <span>
          {value}/{max}
        </span>
      </div>
      <div className="stat-bar__track">
        <div className="stat-bar__fill" style={{ width: `${percent}%` }} />
      </div>
    </div>
  );
}

export function ProfileScreen({ character }: Props) {
  return (
    <div className="profile">
      <h1>{character.class.name}</h1>
      <p className="profile__level">Уровень {character.level}</p>
      <StatBar label="HP" value={character.hp} max={character.maxHp} />
      <StatBar label="Энергия" value={character.energy} max={character.maxEnergy} />
      <div className="profile__row">
        <span>XP: {character.xp}</span>
        <span>Монеты: {character.coins}</span>
      </div>
    </div>
  );
}
