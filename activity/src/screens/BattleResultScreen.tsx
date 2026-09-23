import { getIconUrl } from "../api/client";
import type { BattleState, RoundResult } from "../types/battle";
import "./BattleResultScreen.css";

interface Props {
  battle: BattleState;
  round: RoundResult | null;
  onContinue: () => void;
}

export function BattleResultScreen({ battle, round, onContinue }: Props) {
  const won = battle.status === "won";
  const itemsDropped = round?.itemsDropped ?? [];

  return (
    <div className="battle-result">
      <h1 className={won ? "battle-result__title--won" : "battle-result__title--lost"}>{won ? "Победа!" : "Поражение"}</h1>
      <p>{battle.opponent.name} повержен{won ? "" : " не был"}.</p>
      {won && battle.rewards && (
        <p className="battle-result__rewards">
          +{battle.rewards.xp} XP · +{battle.rewards.coins} монет
        </p>
      )}
      {won && itemsDropped.length > 0 && (
        <div className="battle-result__loot">
          <p className="battle-result__loot-title">Получено:</p>
          <div className="battle-result__loot-list">
            {itemsDropped.map((item, index) => (
              <div key={index} className="battle-result__loot-item">
                {item.iconName ? (
                  <img className="battle-result__loot-icon" src={getIconUrl("items", item.iconName)} alt={item.name} />
                ) : (
                  <span className="battle-result__loot-icon battle-result__loot-icon--placeholder" aria-hidden="true">
                    ?
                  </span>
                )}
                <span>{item.name}</span>
              </div>
            ))}
          </div>
        </div>
      )}
      <button className="battle-result__continue" onClick={onContinue}>
        Продолжить
      </button>
    </div>
  );
}
