import { StatBar } from "../components/StatBar";
import type { Character } from "../types/character";
import type { ActiveEvent } from "../types/event";
import "./ProfileScreen.css";

interface Props {
  character: Character;
  activeEvent: ActiveEvent | null;
  // Set when someone has challenged you to a duel you haven't responded to
  // yet — swaps the "Начать дуэль" button for a "Вызов" one that goes
  // straight to the confirmation screen instead of the voice-channel picker.
  pendingDuelOpponentName: string | null;
  onStartBattle: () => void;
  onJoinEvent: () => void;
  onStartDuel: () => void;
  onOpenPendingDuel: () => void;
  onOpenShop: () => void;
  onOpenInventory: () => void;
  onOpenTournament: () => void;
  onOpenQuest: () => void;
}

export function ProfileScreen({
  character,
  activeEvent,
  pendingDuelOpponentName,
  onStartBattle,
  onJoinEvent,
  onStartDuel,
  onOpenPendingDuel,
  onOpenShop,
  onOpenInventory,
  onOpenTournament,
  onOpenQuest,
}: Props) {
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

      {activeEvent && (
        <div className="profile__event">
          <p>
            🐉 Событие: <strong>{activeEvent.monsterName}</strong> напал на сервер!
          </p>
          <button className="profile__event-button" onClick={onJoinEvent}>
            Присоединиться (бесплатно)
          </button>
        </div>
      )}

      <div className="profile__actions">
        <button className="profile__fight" disabled={!canFight} onClick={onStartBattle}>
          {canFight ? "В бой" : "Нет энергии"}
        </button>
        {pendingDuelOpponentName ? (
          <button className="profile__duel profile__duel--challenge" onClick={onOpenPendingDuel}>
            Вызов от {pendingDuelOpponentName}!
          </button>
        ) : (
          <button className="profile__duel" onClick={onStartDuel}>
            Начать дуэль
          </button>
        )}
        <button className="profile__secondary" onClick={onOpenShop}>
          Магазин
        </button>
        <button className="profile__secondary" onClick={onOpenInventory}>
          Инвентарь
        </button>
        <button className="profile__secondary" onClick={onOpenTournament}>
          Турнир
        </button>
        <button className="profile__secondary" onClick={onOpenQuest}>
          Задания
        </button>
      </div>
    </div>
  );
}
