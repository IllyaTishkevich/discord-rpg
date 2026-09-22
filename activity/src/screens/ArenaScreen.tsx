import { useState } from "react";
import { resolveRound, throwRound } from "../api/battles";
import { BitCoin } from "../components/BitCoin";
import { StatBar } from "../components/StatBar";
import type { BattleState, RoundResult, ThrowResult } from "../types/battle";
import "./ArenaScreen.css";

type Phase = "idle" | "thrown" | "resolved";

interface Props {
  initialBattle: BattleState;
  onFinished: (battle: BattleState, lastRound: RoundResult | null) => void;
}

export function ArenaScreen({ initialBattle, onFinished }: Props) {
  const [battle, setBattle] = useState(initialBattle);
  const [phase, setPhase] = useState<Phase>("idle");
  const [throwResult, setThrowResult] = useState<ThrowResult | null>(null);
  const [lastRound, setLastRound] = useState<RoundResult | null>(null);
  const [selectedTargets, setSelectedTargets] = useState<number[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleThrow() {
    setBusy(true);
    setError(null);
    try {
      const result = await throwRound(battle.id);
      setThrowResult(result);
      setSelectedTargets([]);
      setPhase("thrown");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось бросить биты.");
    } finally {
      setBusy(false);
    }
  }

  async function handleResolve() {
    setBusy(true);
    setError(null);
    try {
      const { round, battle: updatedBattle } = await resolveRound(battle.id, selectedTargets);
      setLastRound(round);
      setBattle(updatedBattle);
      setPhase("resolved");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось разрешить раунд.");
    } finally {
      setBusy(false);
    }
  }

  function toggleTarget(index: number) {
    if (!throwResult) return;
    setSelectedTargets((current) => {
      if (current.includes(index)) {
        return current.filter((i) => i !== index);
      }
      if (current.length >= throwResult.playerActionCount) {
        return current;
      }
      return [...current, index];
    });
  }

  function handleContinue() {
    if (battle.status !== "in_progress") {
      onFinished(battle, lastRound);
      return;
    }
    setThrowResult(null);
    setLastRound(null);
    setPhase("idle");
  }

  return (
    <div className="arena">
      <h1>{battle.opponent.name}</h1>

      <StatBar label="Противник" value={battle.opponent.hp} max={battle.opponent.maxHp} variant="enemy" />
      <StatBar label="Ты" value={battle.character.hp} max={battle.character.maxHp} variant="hp" />

      {phase === "idle" && (
        <button className="arena__action" disabled={busy} onClick={handleThrow}>
          Бросить биты
        </button>
      )}

      {throwResult && (
        <div className="arena__board">
          <div className="arena__side">
            <p>Противник</p>
            <div className="arena__coins">
              {throwResult.opponentFaces.map((face, index) => (
                <BitCoin
                  key={index}
                  face={face}
                  selectable={phase === "thrown" && throwResult.playerActionCount > 0}
                  selected={selectedTargets.includes(index)}
                  onClick={() => toggleTarget(index)}
                />
              ))}
            </div>
          </div>

          <div className="arena__side">
            <p>Ты</p>
            <div className="arena__coins">
              {throwResult.playerFaces.map((face, index) => (
                <BitCoin key={index} face={face} />
              ))}
            </div>
          </div>

          {phase === "thrown" && throwResult.playerActionCount > 0 && (
            <p className="arena__hint">
              Действие: выбери до {throwResult.playerActionCount} бит противника, чтобы перевернуть их ({selectedTargets.length}/{throwResult.playerActionCount})
            </p>
          )}

          {phase === "thrown" && (
            <button className="arena__action" disabled={busy} onClick={handleResolve}>
              Разрешить раунд
            </button>
          )}
        </div>
      )}

      {phase === "resolved" && lastRound && (
        <div className="arena__result">
          <p>
            Урон противнику: {lastRound.damageToOpponent} · Урон тебе: {lastRound.damageToPlayer}
          </p>
          <button className="arena__action" onClick={handleContinue}>
            {battle.status === "in_progress" ? "Следующий раунд" : "Завершить бой"}
          </button>
        </div>
      )}

      {error && <p className="arena__error">{error}</p>}
    </div>
  );
}
