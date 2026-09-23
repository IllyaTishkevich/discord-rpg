import { useEffect, useState } from "react";
import { fetchBattle, fetchLatestRound, submitExchangeMove, throwRound } from "../api/battles";
import { BitCoin, FACE_LABEL } from "../components/BitCoin";
import { StatBar } from "../components/StatBar";
import type { AbilityChoice, AbilityType, BattleState, Exchange, ExchangeTurn, IncomingMove, RoundResult } from "../types/battle";
import type { BitFace, Character } from "../types/character";
import "./ArenaScreen.css";

// How often to poll GET /battles/{id} while it's the other real duelist's
// turn (PvE/event never reaches this — the bot always resolves synchronously
// in the same request as the player's own move).
const PVP_POLL_INTERVAL_MS = 2000;

type Phase = "loading" | "playing" | "resolved";

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
  { type: "destroy", label: "Уничтожение", description: "2 очка — уничтожить одну неиспользованную биту противника", fixedCost: 2 },
  { type: "double", label: "Удвоение", description: "2 очка — удвоить номинал одной своей неиспользованной биты", fixedCost: 2 },
];

function isAffordable(option: AbilityOption, spentCount: number): boolean {
  if (option.type === "flip") return true;
  if (option.type === "unblockable_damage") return spentCount >= 1;
  return spentCount >= (option.fixedCost ?? 0);
}

// Prefer Flip as the default pick (matches the old always-Flip behavior) but
// only when the character actually has it — otherwise fall back to whatever
// it does have, so the pre-selected ability is never one the backend will
// reject (see BattleService::assertAbilityAvailable()).
function defaultAbility(available: AbilityType[]): AbilityType {
  return available.includes("flip") ? "flip" : (available[0] ?? "flip");
}

// Describes one step of the round's exchange sequence in plain language —
// who led with what, how the other side reacted, and what it cost.
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
  character: Character;
  onFinished: (battle: BattleState, lastRound: RoundResult | null) => void;
}

/**
 * A real turn-by-turn exchange sequence (docs/COMBAT_V2_DESIGN.md §7-8),
 * for PvE/event and PvP alike: after a throw, whoever has priority leads
 * with one or more same-face bits, the other side responds, damage applies
 * immediately, and the lead alternates until both hands are spent. For
 * PvE/event the bot responds synchronously in the same request; for PvP the
 * other real duelist acts via their own separate request, so this screen
 * polls while `turn === "wait"` to pick up their move.
 */
export function ArenaScreen({ initialBattle, character, onFinished }: Props) {
  const [battle, setBattle] = useState(initialBattle);
  const [phase, setPhase] = useState<Phase>("loading");
  const [playerFaces, setPlayerFaces] = useState<BitFace[]>([]);
  const [opponentFaces, setOpponentFaces] = useState<BitFace[]>([]);
  const [playerUsed, setPlayerUsed] = useState<boolean[]>([]);
  const [opponentUsed, setOpponentUsed] = useState<boolean[]>([]);
  const [playerMultipliers, setPlayerMultipliers] = useState<number[]>([]);
  const [opponentMultipliers, setOpponentMultipliers] = useState<number[]>([]);
  const [turn, setTurn] = useState<ExchangeTurn | null>(null);
  const [incomingMove, setIncomingMove] = useState<IncomingMove | null>(null);
  const [selectedIndices, setSelectedIndices] = useState<number[]>([]);
  const [pendingAbilityChoice, setPendingAbilityChoice] = useState(false);
  const [selectedAbility, setSelectedAbility] = useState<AbilityType>(defaultAbility(character.abilities));
  const availableAbilityOptions = ABILITY_OPTIONS.filter((option) => character.abilities.includes(option.type));
  const [flipTargets, setFlipTargets] = useState<number[]>([]);
  const [exchangeLog, setExchangeLog] = useState<Exchange[]>([]);
  const [lastRound, setLastRound] = useState<RoundResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleThrow() {
    setBusy(true);
    setError(null);
    try {
      const result = await throwRound(battle.id);
      setPlayerFaces(result.playerFaces);
      setOpponentFaces(result.opponentFaces);
      setPlayerUsed(result.playerUsed ?? result.playerFaces.map(() => false));
      setOpponentUsed(result.opponentUsed ?? result.opponentFaces.map(() => false));
      setPlayerMultipliers(result.playerMultipliers);
      setOpponentMultipliers(result.opponentMultipliers);
      setTurn(result.turn);
      setIncomingMove(result.incomingMove);
      setExchangeLog([]);
      setSelectedIndices([]);
      setPendingAbilityChoice(false);
      setSelectedAbility(defaultAbility(character.abilities));
      setFlipTargets([]);
      setPhase("playing");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось бросить биты.");
    } finally {
      setBusy(false);
    }
  }

  useEffect(() => {
    handleThrow();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function sendMove(indices: number[], ability?: AbilityChoice) {
    setBusy(true);
    setError(null);
    try {
      const response = await submitExchangeMove(battle.id, indices, ability);
      setPlayerFaces(response.playerFaces);
      setOpponentFaces(response.opponentFaces);
      setPlayerUsed(response.playerUsed);
      setOpponentUsed(response.opponentUsed);
      setPlayerMultipliers(response.playerMultipliers);
      setOpponentMultipliers(response.opponentMultipliers);
      setExchangeLog((current) => [...current, ...response.newExchanges]);
      setBattle(response.battle);
      setSelectedIndices([]);
      setPendingAbilityChoice(false);
      setSelectedAbility(defaultAbility(character.abilities));
      setFlipTargets([]);

      if (response.roundComplete && response.round) {
        // Authoritative — covers PvP, where some of this round's exchanges
        // may have come from the other duelist's own moves this client
        // never directly saw (only picked up via polling).
        setExchangeLog(response.round.exchanges);
        setLastRound(response.round);
        setPhase("resolved");
      } else {
        setTurn(response.turn ?? null);
        setIncomingMove(response.incomingMove ?? null);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось отправить ход.");
    } finally {
      setBusy(false);
    }
  }

  // PvP only: while it's the other duelist's turn, poll for their move —
  // PvE/event never sets turn to "wait" (the bot always resolves inline).
  useEffect(() => {
    if (battle.mode !== "pvp" || turn !== "wait" || phase !== "playing") {
      return;
    }

    const interval = setInterval(async () => {
      try {
        const updated = await fetchBattle(battle.id);
        setBattle(updated);

        if (updated.exchange) {
          setPlayerFaces(updated.exchange.playerFaces);
          setOpponentFaces(updated.exchange.opponentFaces);
          setPlayerUsed(updated.exchange.playerUsed ?? []);
          setOpponentUsed(updated.exchange.opponentUsed ?? []);
          setPlayerMultipliers(updated.exchange.playerMultipliers);
          setOpponentMultipliers(updated.exchange.opponentMultipliers);
          setTurn(updated.exchange.turn);
          setIncomingMove(updated.exchange.incomingMove);
          return;
        }

        // No pending exchange left — the other duelist's move just
        // finished the round (or knocked someone out mid-exchange).
        const round = await fetchLatestRound(battle.id);
        if (round) {
          setExchangeLog(round.exchanges);
          setLastRound(round);
        }
        setPhase("resolved");
      } catch {
        // Transient poll failure — try again next tick.
      }
    }, PVP_POLL_INTERVAL_MS);

    return () => clearInterval(interval);
  }, [battle.mode, battle.id, turn, phase]);

  function toggleOwnBit(index: number) {
    if (pendingAbilityChoice || playerUsed[index]) return;
    // An empty-faced bit never activates — it can't be led or responded
    // with at all (it can only ever be targeted by an opponent's Flip, via
    // toggleFlipTarget below, which has no such restriction).
    if (playerFaces[index] === "empty") return;
    // A response can only ever be a defense bit — never attack or action.
    if (turn === "respond" && playerFaces[index] !== "defense") return;
    setSelectedIndices((current) => {
      if (current.includes(index)) {
        return current.filter((i) => i !== index);
      }
      // Selecting a bit of a different face than what's already chosen
      // starts a fresh group instead of mixing faces in one move.
      if (current.length > 0 && playerFaces[current[0]] !== playerFaces[index]) {
        return [index];
      }
      return [...current, index];
    });
  }

  function toggleFlipTarget(index: number) {
    if (opponentUsed[index]) return;
    setFlipTargets((current) => {
      if (current.includes(index)) {
        return current.filter((i) => i !== index);
      }
      if (current.length >= selectedIndices.length) {
        return current;
      }
      return [...current, index];
    });
  }

  function handleConfirmSelection() {
    if (selectedIndices.length === 0) return;
    if (playerFaces[selectedIndices[0]] === "action") {
      setPendingAbilityChoice(true);
      return;
    }
    void sendMove(selectedIndices);
  }

  function handleSendActionMove() {
    void sendMove(selectedIndices, { ability: selectedAbility, targets: selectedAbility === "flip" ? flipTargets : [] });
  }

  function handleContinue() {
    if (battle.status !== "in_progress") {
      onFinished(battle, lastRound);
      return;
    }
    setLastRound(null);
    setPhase("loading");
    void handleThrow();
  }

  return (
    <div className="arena">
      <h1>{battle.opponent.name}</h1>

      <StatBar label="Противник" value={battle.opponent.hp ?? 0} max={battle.opponent.maxHp ?? 0} variant="enemy" />
      <StatBar label="Ты" value={battle.character.hp} max={battle.character.maxHp} variant="hp" />

      {phase === "loading" && <p className="arena__hint">Бросаем биты...</p>}

      {phase === "playing" && (
        <div className="arena__board">
          <div className="arena__side">
            <p>Противник</p>
            <div className="arena__coins">
              {opponentFaces.map((face, index) => (
                <BitCoin
                  key={index}
                  face={face}
                  used={opponentUsed[index]}
                  multiplier={opponentMultipliers[index]}
                  selectable={pendingAbilityChoice && selectedAbility === "flip" && !opponentUsed[index]}
                  selected={flipTargets.includes(index)}
                  onClick={() => toggleFlipTarget(index)}
                />
              ))}
            </div>
          </div>

          <div className="arena__side">
            <p>Ты</p>
            <div className="arena__coins">
              {playerFaces.map((face, index) => (
                <BitCoin
                  key={index}
                  face={face}
                  used={playerUsed[index]}
                  multiplier={playerMultipliers[index]}
                  selectable={
                    turn !== "wait" &&
                    !pendingAbilityChoice &&
                    !playerUsed[index] &&
                    face !== "empty" &&
                    (turn !== "respond" || face === "defense")
                  }
                  selected={selectedIndices.includes(index)}
                  onClick={() => toggleOwnBit(index)}
                />
              ))}
            </div>
          </div>

          {turn === "wait" && <p className="arena__hint">Ждём ход соперника...</p>}
          {turn === "respond" && incomingMove && !pendingAbilityChoice && (
            <p className="arena__hint arena__hint--selected">
              Соперник разыграл: <strong>{FACE_LABEL[incomingMove.face]} ×{incomingMove.count}</strong>. Ответить можно только защитой, или пропусти.
            </p>
          )}
          {turn === "lead" && !pendingAbilityChoice && <p className="arena__hint">Твой ход — выбери одну или несколько одинаковых бит.</p>}

          {turn !== "wait" && !pendingAbilityChoice && (
            <div className="arena__move-actions">
              {turn === "respond" && (
                <button className="arena__action arena__action--secondary" disabled={busy} onClick={() => void sendMove([])}>
                  Не отвечать
                </button>
              )}
              <button className="arena__action" disabled={busy || selectedIndices.length === 0} onClick={handleConfirmSelection}>
                Подтвердить{selectedIndices.length > 0 ? ` (${selectedIndices.length})` : ""}
              </button>
            </div>
          )}

          {pendingAbilityChoice && (
            <div className="arena__abilities">
              <p className="arena__hint">Разыгрывается действие ×{selectedIndices.length}. Выбери способность:</p>
              <div className="arena__ability-list">
                {availableAbilityOptions.length === 0 && (
                  <p className="arena__hint">Нет доступных способностей — можно только сходить обычным ударом или защитой.</p>
                )}
                {availableAbilityOptions.map((option) => {
                  const affordable = isAffordable(option, selectedIndices.length);
                  const isSelected = selectedAbility === option.type;
                  return (
                    <button
                      key={option.type}
                      className={`arena__ability${isSelected ? " arena__ability--selected" : ""}`}
                      disabled={!affordable}
                      onClick={() => {
                        setSelectedAbility(option.type);
                        setFlipTargets([]);
                      }}
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
              {selectedAbility === "flip" && availableAbilityOptions.length > 0 && (
                <p className="arena__hint">
                  Выбери до {selectedIndices.length} бит противника, чтобы перевернуть их ({flipTargets.length}/{selectedIndices.length})
                </p>
              )}
              <div className="arena__move-actions">
                <button className="arena__action arena__action--secondary" disabled={busy} onClick={() => setPendingAbilityChoice(false)}>
                  Назад
                </button>
                <button className="arena__action" disabled={busy || availableAbilityOptions.length === 0} onClick={handleSendActionMove}>
                  Подтвердить способность
                </button>
              </div>
            </div>
          )}

          {exchangeLog.length > 0 && (
            <div className="arena__exchange-log">
              {exchangeLog.map((exchange, index) => (
                <p key={index} className="arena__exchange-line">
                  {describeExchange(exchange)}
                </p>
              ))}
            </div>
          )}
        </div>
      )}

      {phase === "resolved" && lastRound && (
        <div className="arena__result">
          {exchangeLog.length > 0 && (
            <div className="arena__exchange-log">
              {exchangeLog.map((exchange, index) => (
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
