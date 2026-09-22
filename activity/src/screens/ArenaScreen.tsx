import { useEffect, useState } from "react";
import { fetchBattle, fetchLatestRound, resolveRound, submitActions, throwRound } from "../api/battles";
import { BitCoin, FACE_LABEL } from "../components/BitCoin";
import { StatBar } from "../components/StatBar";
import type { AbilityChoice, AbilityType, BattleState, Exchange, RoundResult, ThrowResult } from "../types/battle";
import "./ArenaScreen.css";

type Phase = "idle" | "thrown" | "waiting" | "resolved";

interface AbilityOption {
  type: AbilityType;
  label: string;
  description: string;
  fixedCost: number | null;
}

const ABILITY_OPTIONS: AbilityOption[] = [
  { type: "flip", label: "Переворот", description: "1 очко за 1 цель — перевернуть биты противника", fixedCost: null },
  { type: "unblockable_damage", label: "Неблокируемый урон", description: "Весь запас очков действия — урон в обход защиты", fixedCost: null },
  { type: "reroll", label: "Переброс", description: "1 очко — перебросить все свои биты этого раунда", fixedCost: 1 },
  { type: "damage_mirror", label: "Зеркало урона", description: "2 очка — соперник получает столько же урона, сколько нанёс сам", fixedCost: 2 },
];

function isAffordable(option: AbilityOption, actionCount: number): boolean {
  if (option.type === "flip") return true;
  if (option.type === "unblockable_damage") return actionCount >= 1;
  return actionCount >= (option.fixedCost ?? 0);
}

// Describes one step of the round's exchange sequence in plain language —
// who led with what, how the other side reacted, and what it cost. Empty
// for PvP battles (still resolved as one simultaneous tally), so the log
// section below only renders when there's something to show.
function describeExchange(exchange: Exchange): string {
  const leaderLabel = exchange.leaderIsPlayer ? "Ты" : "Соперник";
  const responderLabel = exchange.leaderIsPlayer ? "Соперник" : "Ты";
  const leaderMove = `${FACE_LABEL[exchange.leaderFace]} ×${exchange.leaderCount}`;
  const responderMove = exchange.responderFace ? `${FACE_LABEL[exchange.responderFace]} ×${exchange.responderCount}` : "нет ответа";

  const damageParts: string[] = [];
  if (exchange.damageToOpponent > 0) damageParts.push(`−${exchange.damageToOpponent} сопернику`);
  if (exchange.damageToPlayer > 0) damageParts.push(`−${exchange.damageToPlayer} тебе`);
  const damageText = damageParts.length > 0 ? damageParts.join(", ") : "без урона";

  return `${leaderLabel}: ${leaderMove} → ${responderLabel}: ${responderMove} — ${damageText}`;
}

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
  const [selectedAbility, setSelectedAbility] = useState<AbilityType>("flip");
  const [selectedTargets, setSelectedTargets] = useState<number[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleThrow() {
    setBusy(true);
    setError(null);
    try {
      const result = await throwRound(battle.id);
      setThrowResult(result);
      setSelectedAbility("flip");
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

  function currentChoice(): AbilityChoice {
    return { ability: selectedAbility, targets: selectedAbility === "flip" ? selectedTargets : [] };
  }

  async function handleResolve() {
    setBusy(true);
    setError(null);
    try {
      const { round, battle: updatedBattle } = await resolveRound(battle.id, currentChoice());
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
      const response = await submitActions(battle.id, currentChoice());
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

  function selectAbility(ability: AbilityType) {
    setSelectedAbility(ability);
    setSelectedTargets([]);
  }

  function toggleTarget(index: number) {
    if (!throwResult || selectedAbility !== "flip") return;
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

  const selectedOption = ABILITY_OPTIONS.find((option) => option.type === selectedAbility);

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
                  selectable={phase === "thrown" && selectedAbility === "flip" && throwResult.playerActionCount > 0}
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
            <div className="arena__abilities">
              <p className="arena__hint">Очки действия: {throwResult.playerActionCount}. Выбери способность:</p>
              <div className="arena__ability-list">
                {ABILITY_OPTIONS.map((option) => {
                  const affordable = isAffordable(option, throwResult.playerActionCount);
                  const isSelected = selectedAbility === option.type;
                  return (
                    <button
                      key={option.type}
                      className={`arena__ability${isSelected ? " arena__ability--selected" : ""}`}
                      disabled={!affordable}
                      onClick={() => selectAbility(option.type)}
                    >
                      <span className="arena__ability-top">
                        <span className="arena__ability-check" aria-hidden="true">
                          {isSelected ? "✓" : ""}
                        </span>
                        <span className="arena__ability-label">{option.label}</span>
                      </span>
                      <span className="arena__ability-desc">{option.description}</span>
                    </button>
                  );
                })}
              </div>
              {selectedOption && (
                <p className="arena__hint arena__hint--selected">
                  Выбрано: <strong>{selectedOption.label}</strong>. {selectedOption.description}.
                </p>
              )}
              {selectedAbility === "flip" && (
                <p className="arena__hint">
                  Выбери до {throwResult.playerActionCount} бит противника, чтобы перевернуть их ({selectedTargets.length}/{throwResult.playerActionCount})
                </p>
              )}
            </div>
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
          {lastRound.exchanges.length > 0 && (
            <div className="arena__exchange-log">
              {lastRound.exchanges.map((exchange, index) => (
                <p key={index} className="arena__exchange-line">
                  {describeExchange(exchange)}
                </p>
              ))}
            </div>
          )}
          <p className="arena__result-total">
            Итого — урон противнику: {lastRound.damageToOpponent} · урон тебе: {lastRound.damageToPlayer}
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
