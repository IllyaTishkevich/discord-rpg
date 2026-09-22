import { useEffect, useRef, useState } from "react";
import { declinePvpBattle, fetchBattle, joinPvpBattle } from "../api/battles";
import type { BattleState } from "../types/battle";
import "./DuelLobbyScreen.css";

interface Props {
  battle: BattleState;
  onReady: (battle: BattleState) => void;
  onDeclined: () => void;
}

export function DuelLobbyScreen({ battle, onReady, onDeclined }: Props) {
  const [current, setCurrent] = useState(battle);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const onReadyRef = useRef(onReady);
  onReadyRef.current = onReady;

  // The other player accepting/readying up happens outside this client's
  // control, so poll for it — same pattern as the active-event check on
  // the profile screen.
  useEffect(() => {
    const interval = setInterval(async () => {
      try {
        const updated = await fetchBattle(current.id);
        if (updated.status === "in_progress") {
          onReadyRef.current(updated);
          return;
        }
        setCurrent(updated);
      } catch {
        // transient poll failure — try again next tick
      }
    }, 3000);

    return () => clearInterval(interval);
  }, [current.id]);

  async function handleReady() {
    setBusy(true);
    setError(null);
    try {
      const updated = await joinPvpBattle(current.id);
      if (updated.status === "in_progress") {
        onReady(updated);
        return;
      }
      setCurrent(updated);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось подтвердить готовность.");
    } finally {
      setBusy(false);
    }
  }

  async function handleDecline() {
    setBusy(true);
    setError(null);
    try {
      await declinePvpBattle(current.id);
      onDeclined();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось отклонить дуэль.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="duel-lobby">
      <h1>Дуэль с {current.opponent.name}</h1>
      {!current.opponentAccepted && <p>Ждём, пока соперник примет вызов в Discord...</p>}
      {current.opponentAccepted && (
        <p>
          Ты: {current.youReady ? "готов(а)" : "не готов(а)"} · Соперник: {current.opponentReady ? "готов(а)" : "не готов(а)"}
        </p>
      )}
      {error && <p className="duel-lobby__error">{error}</p>}
      <div className="duel-lobby__actions">
        <button className="duel-lobby__ready" disabled={busy || current.youReady} onClick={handleReady}>
          {current.youReady ? "Готов(а)" : "Я готов(а)"}
        </button>
        <button className="duel-lobby__decline" disabled={busy} onClick={handleDecline}>
          Отклонить
        </button>
      </div>
    </div>
  );
}
