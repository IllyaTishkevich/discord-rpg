import { useEffect, useState } from "react";
import { claimCurrentQuest, fetchCurrentQuest } from "../api/quests";
import { StatBar } from "../components/StatBar";
import type { CurrentQuest } from "../types/quest";
import "./WeeklyQuestScreen.css";

interface Props {
  onBack: () => void;
}

export function WeeklyQuestScreen({ onBack }: Props) {
  const [quest, setQuest] = useState<CurrentQuest | null | undefined>(undefined);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [claimedMessage, setClaimedMessage] = useState<string | null>(null);

  useEffect(() => {
    fetchCurrentQuest()
      .then(setQuest)
      .catch((err) => setError(err instanceof Error ? err.message : "Не удалось загрузить задание."));
  }, []);

  async function handleClaim() {
    setBusy(true);
    setError(null);
    try {
      await claimCurrentQuest();
      setClaimedMessage("Награда получена!");
      setQuest((current) => (current ? { ...current, isClaimed: true } : current));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось получить награду.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="quest">
      <h1>Недельное задание</h1>

      {quest === undefined && <p>Загрузка...</p>}
      {quest === null && <p>На этой неделе задание ещё не сгенерировано.</p>}
      {error && <p className="quest__error">{error}</p>}
      {claimedMessage && <p className="quest__claimed">{claimedMessage}</p>}

      {quest && (
        <div>
          <p>Победить в {quest.targetValue} боях</p>
          <StatBar label="Прогресс" value={quest.progress} max={quest.targetValue} variant="energy" />
          <p className="quest__reward">
            Награда: +{quest.rewardXp} XP, +{quest.rewardCoins} монет
          </p>
          <button className="quest__claim" disabled={!quest.isComplete || quest.isClaimed || busy} onClick={handleClaim}>
            {quest.isClaimed ? "Получено" : "Забрать награду"}
          </button>
        </div>
      )}

      <button className="quest__back" onClick={onBack}>
        Назад
      </button>
    </div>
  );
}
