import type { BattleState } from "../types/battle";
import "./BattleResultScreen.css";

interface Props {
  battle: BattleState;
  onContinue: () => void;
}

export function BattleResultScreen({ battle, onContinue }: Props) {
  const won = battle.status === "won";

  return (
    <div className="battle-result">
      <h1 className={won ? "battle-result__title--won" : "battle-result__title--lost"}>{won ? "Победа!" : "Поражение"}</h1>
      <p>{battle.opponent.name} повержен{won ? "" : " не был"}.</p>
      {won && battle.rewards && (
        <p className="battle-result__rewards">
          +{battle.rewards.xp} XP · +{battle.rewards.coins} монет
        </p>
      )}
      <button className="battle-result__continue" onClick={onContinue}>
        Продолжить
      </button>
    </div>
  );
}
