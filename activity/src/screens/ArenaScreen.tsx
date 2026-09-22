import { useEffect, useState } from "react";
import { fetchBattle, fetchLatestRound, resolveRound, submitActions, throwRound } from "../api/battles";
import { BitCoin } from "../components/BitCoin";
import { StatBar } from "../components/StatBar";
import type { BattleState, RoundResult, ThrowResult } from "../types/battle";
import "./ArenaScreen.css";

type Phase = "idle" | "thrown" | "waiting" | "resolved";

interface Props {
  initialBattle: BattleState;
  onFinished: (battle: BattleState, lastRound: RoundResult | null) => void;
}

export function ArenaScreen({ initialBattle, onFinished }: Props) {
  const isPvp = initialBattle.mode === "pvp";
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

  // PvP: throwing is automatic (the server does it the moment both sides are
  // ready, and throwRound() is idempotent for later rounds too — whichever
  // client gets there first actually rolls, the other just reads the same
  // result), so there's no manual "throw" button in that mode.
  useEffect(() => {
    if (isPvp && phase === "idle") {
      handleThrow();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isPvp, phase]);

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

  async function handleSubmitActions() {
    setBusy(true);
    setError(null);
    try {
      const response = await submitActions(battle.id, selectedTargets);
      if (response.round && response.battle) {
        setLastRound(response.round);
        setBattle(response.battle);
        setPhase("resolved");
        return;
      }
      setPhase("waiting");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось отправить действие.");
    } finally {
      setBusy(false);
    }
  }

  // While waiting on the opponent's submit, poll until the round actually
  // resolves (hasPendingThrow flips back to false), then fetch what happened —
  // if the opponent was the one to trigger resolution, their response carried
  // the round data, not ours.
  useEffect(() => {
    if (phase !== "waiting") return;

    const interval = setInterval(async () => {
      try {
        const updated = await fetchBattle(battle.id);
        if (!updated.hasPendingThrow) {
          const round = await fetchLatestRound(battle.id);
          setLastRound(round);
          setBattle(updated);
          setPhase("resolved");
        }
      } catch {
        // transient poll failure — try again next tick
      }
    }, 2000);

    return () => clearInterval(interval);
  }, [phase, battle.id]);

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

      <StatBar label="Противник" value={battle.opponent.hp ?? 0} max={battle.opponent.maxHp ?? 0} variant="enemy" />
      <StatBar label="Ты" value={battle.character.hp} max={battle.character.maxHp} variant="hp" />

      {!isPvp && phase === "idle" && (
        <button className="arena__action" disabled={busy} onClick={handleThrow}>
          Бросить биты
        </button>
      )}
      {isPvp && phase === "idle" && <p className="arena__hint">Бросаем биты...</p>}

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
            <button className="arena__action" disabled={busy} onClick={isPvp ? handleSubmitActions : handleResolve}>
              {isPvp ? "Подтвердить ход" : "Разрешить раунд"}
            </button>
          )}
        </div>
      )}

      {phase === "waiting" && <p className="arena__hint">Ждём соперника...</p>}

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
