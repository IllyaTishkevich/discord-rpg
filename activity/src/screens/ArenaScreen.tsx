import { useEffect, useRef, useState } from "react";
import type { CSSProperties, MutableRefObject } from "react";
import { fetchAbilities } from "../api/abilities";
import { fetchBattle, fetchLatestRound, submitExchangeMove, throwRound } from "../api/battles";
import { BitCoin, FACE_LABEL } from "../components/BitCoin";
import { CombatantBar } from "../components/CombatantBar";
import { TurnTimer } from "../components/TurnTimer";
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

// One side's most recently played move, for the center-stage circles —
// derived from the round's last resolved exchange. null when that side
// didn't play into it (e.g. the responder passed).
interface StageMove {
  face: BitFace;
  count: number;
}

function stageMoveFor(exchange: Exchange | undefined, wantPlayerSide: boolean): StageMove | null {
  if (!exchange) return null;
  if (exchange.leaderIsPlayer === wantPlayerSide) {
    return { face: exchange.leaderFace, count: exchange.leaderCount };
  }
  return exchange.responderFace ? { face: exchange.responderFace, count: exchange.responderCount } : null;
}

// A bit mid-flight from its pool slot to the stage circle it was just
// played into — spawned in spawnFlyingBits() below, purely cosmetic (the
// real state transition already happened by the time this renders).
interface FlyingBit {
  id: string;
  face: BitFace;
  multiplier: number;
  from: DOMRect;
  to: DOMRect;
}

const FLIGHT_DURATION_MS = 450;

function FlyingBitCoin({ bit }: { bit: FlyingBit }) {
  const [arrived, setArrived] = useState(false);

  useEffect(() => {
    const id = requestAnimationFrame(() => setArrived(true));
    return () => cancelAnimationFrame(id);
  }, []);

  const rect = arrived ? bit.to : bit.from;
  const style: CSSProperties = {
    position: "fixed",
    left: rect.left,
    top: rect.top,
    width: bit.from.width,
    height: bit.from.height,
    transition: `left ${FLIGHT_DURATION_MS}ms ease, top ${FLIGHT_DURATION_MS}ms ease`,
    pointerEvents: "none",
    zIndex: 50,
  };

  return (
    <div style={style}>
      <BitCoin face={bit.face} multiplier={bit.multiplier} />
    </div>
  );
}

// Diffs a used[] array before/after a move against the pool's last known DOM
// positions (via `refs`) to spawn one FlyingBit per bit that just became
// used — i.e. was just played into `circleEl` (the matching side's stage
// circle). Must be called BEFORE the setPlayerUsed/setOpponentUsed calls
// that would otherwise unmount those bits from the pool.
function flyingBitsFor(
  prevUsed: boolean[],
  nextUsed: boolean[],
  faces: BitFace[],
  multipliers: number[],
  refs: MutableRefObject<Record<number, HTMLDivElement | null>>,
  circleEl: HTMLDivElement | null,
): FlyingBit[] {
  if (!circleEl) return [];
  const to = circleEl.getBoundingClientRect();
  const entries: FlyingBit[] = [];
  nextUsed.forEach((used, index) => {
    if (!used || prevUsed[index]) return;
    const el = refs.current[index];
    if (!el) return;
    entries.push({
      id: `${index}-${Date.now()}-${Math.random().toString(36).slice(2)}`,
      face: faces[index],
      multiplier: multipliers[index] ?? 1,
      from: el.getBoundingClientRect(),
      to,
    });
  });
  return entries;
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
  // Admin-editable player-facing name per ability (App\Entity\Ability::$label),
  // overriding ABILITY_OPTIONS' hardcoded default — never blocks rendering
  // if the fetch fails, it just falls back to that default.
  const [abilityLabels, setAbilityLabels] = useState<Record<string, string>>({});
  const [flyingBits, setFlyingBits] = useState<FlyingBit[]>([]);
  const opponentPoolRefs = useRef<Record<number, HTMLDivElement | null>>({});
  const playerPoolRefs = useRef<Record<number, HTMLDivElement | null>>({});
  const opponentCircleRef = useRef<HTMLDivElement | null>(null);
  const playerCircleRef = useRef<HTMLDivElement | null>(null);
  // The last used[] arrays actually applied to state, tracked outside React
  // state so flyingBitsFor() always diffs against a true "previous" value —
  // reading playerUsed/opponentUsed state directly would go stale inside the
  // PvP poll's setInterval closure across repeated ticks.
  const lastPlayerUsedRef = useRef<boolean[]>([]);
  const lastOpponentUsedRef = useRef<boolean[]>([]);

  function spawnFlyingBits(entries: FlyingBit[]) {
    if (entries.length === 0) return;
    setFlyingBits((current) => [...current, ...entries]);
    window.setTimeout(() => {
      setFlyingBits((current) => current.filter((b) => !entries.includes(b)));
    }, FLIGHT_DURATION_MS);
  }

  // Applies a fresh used/faces/multipliers snapshot for both sides, flying
  // any newly-used bits into their side's stage circle first.
  function applyExchangeSnapshot(next: {
    playerFaces: BitFace[];
    opponentFaces: BitFace[];
    playerUsed: boolean[];
    opponentUsed: boolean[];
    playerMultipliers: number[];
    opponentMultipliers: number[];
  }) {
    spawnFlyingBits([
      ...flyingBitsFor(lastPlayerUsedRef.current, next.playerUsed, next.playerFaces, next.playerMultipliers, playerPoolRefs, playerCircleRef.current),
      ...flyingBitsFor(
        lastOpponentUsedRef.current,
        next.opponentUsed,
        next.opponentFaces,
        next.opponentMultipliers,
        opponentPoolRefs,
        opponentCircleRef.current,
      ),
    ]);
    lastPlayerUsedRef.current = next.playerUsed;
    lastOpponentUsedRef.current = next.opponentUsed;
    setPlayerFaces(next.playerFaces);
    setOpponentFaces(next.opponentFaces);
    setPlayerUsed(next.playerUsed);
    setOpponentUsed(next.opponentUsed);
    setPlayerMultipliers(next.playerMultipliers);
    setOpponentMultipliers(next.opponentMultipliers);
  }

  useEffect(() => {
    fetchAbilities()
      .then((abilities) => setAbilityLabels(Object.fromEntries(abilities.map((a) => [a.type, a.label]))))
      .catch(() => {
        // Keep the hardcoded ABILITY_OPTIONS labels — never block the arena on this.
      });
  }, []);

  async function handleThrow() {
    setBusy(true);
    setError(null);
    try {
      const result = await throwRound(battle.id);
      setBattle(result.battle);
      lastPlayerUsedRef.current = result.playerUsed ?? result.playerFaces.map(() => false);
      lastOpponentUsedRef.current = result.opponentUsed ?? result.opponentFaces.map(() => false);
      setPlayerFaces(result.playerFaces);
      setOpponentFaces(result.opponentFaces);
      setPlayerUsed(lastPlayerUsedRef.current);
      setOpponentUsed(lastOpponentUsedRef.current);
      setPlayerMultipliers(result.playerMultipliers);
      setOpponentMultipliers(result.opponentMultipliers);
      setTurn(result.turn);
      setIncomingMove(result.incomingMove);
      setExchangeLog([]);
      setSelectedIndices([]);
      setPendingAbilityChoice(false);
      setSelectedAbility(defaultAbility(character.abilities));
      setFlipTargets([]);
      setFlyingBits([]);
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
      applyExchangeSnapshot(response);
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
          applyExchangeSnapshot({
            playerFaces: updated.exchange.playerFaces,
            opponentFaces: updated.exchange.opponentFaces,
            playerUsed: updated.exchange.playerUsed ?? [],
            opponentUsed: updated.exchange.opponentUsed ?? [],
            playerMultipliers: updated.exchange.playerMultipliers,
            opponentMultipliers: updated.exchange.opponentMultipliers,
          });
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
    // applyExchangeSnapshot only reads refs and stable setters, never
    // render-scoped state, so a fresh reference each render isn't needed.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [battle.mode, battle.id, turn, phase]);

  // Fires once the per-move deadline (battle.roundDeadlineAt) reaches zero,
  // for PvE/event and PvP alike. Never resubmits the stale local selection
  // as a move of its own — that risks the server having already auto-passed
  // this exact decision server-side (see BattleService::applyMoveTimeoutIfExpired())
  // and moved on to a different one by the time our request arrives. Instead
  // it just re-syncs via a plain GET, the same safe, side-effect-only path
  // the PvP poll above already uses to pick up someone else's move.
  async function handleTimeout() {
    if (busy || phase !== "playing") return;
    try {
      const updated = await fetchBattle(battle.id);
      setBattle(updated);

      if (updated.exchange) {
        applyExchangeSnapshot({
          playerFaces: updated.exchange.playerFaces,
          opponentFaces: updated.exchange.opponentFaces,
          playerUsed: updated.exchange.playerUsed ?? [],
          opponentUsed: updated.exchange.opponentUsed ?? [],
          playerMultipliers: updated.exchange.playerMultipliers,
          opponentMultipliers: updated.exchange.opponentMultipliers,
        });
        setTurn(updated.exchange.turn);
        setIncomingMove(updated.exchange.incomingMove);
        setSelectedIndices([]);
        setPendingAbilityChoice(false);
        setFlipTargets([]);
        return;
      }

      const round = await fetchLatestRound(battle.id);
      if (round) {
        setExchangeLog(round.exchanges);
        setLastRound(round);
      }
      setPhase("resolved");
    } catch {
      // Transient failure — the timer will have already hit 0; the next
      // request the player makes (or the next PvP poll tick) recovers.
    }
  }

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

  const lastExchange = exchangeLog[exchangeLog.length - 1];
  const opponentStageMove = stageMoveFor(lastExchange, false);
  const playerStageMove = stageMoveFor(lastExchange, true);

  return (
    <div className="arena">
      <CombatantBar
        name={battle.opponent.name ?? "?"}
        level={battle.opponent.level}
        className={battle.opponent.className}
        hp={battle.opponent.hp ?? 0}
        maxHp={battle.opponent.maxHp ?? 0}
        avatarUrl={battle.opponent.avatarUrl}
        iconName={battle.opponent.iconName}
        variant="enemy"
      />

      {phase === "loading" && <p className="arena__hint">Бросаем биты...</p>}

      {phase === "playing" && (
        <div className="arena__board">
          <div className="arena__pool arena__pool--opponent">
            <p className="arena__pool-label">Биты противника</p>
            <div className="arena__coins">
              {opponentFaces.map(
                (face, index) =>
                  !opponentUsed[index] && (
                    <div
                      ref={(el) => {
                        opponentPoolRefs.current[index] = el;
                      }}
                      key={index}
                    >
                      <BitCoin
                        face={face}
                        multiplier={opponentMultipliers[index]}
                        selectable={pendingAbilityChoice && selectedAbility === "flip"}
                        selected={flipTargets.includes(index)}
                        onClick={() => toggleFlipTarget(index)}
                      />
                    </div>
                  ),
              )}
            </div>
          </div>

          <div className="arena__stage">
            <div className="arena__stage-logs">
              <div className="arena__log-panel arena__log-panel--opponent">
                {opponentFaces.map(
                  (face, index) =>
                    opponentUsed[index] && (
                      <div className="arena__log-coin" key={index}>
                        <BitCoin face={face} multiplier={opponentMultipliers[index]} used />
                      </div>
                    ),
                )}
              </div>
              <div className="arena__log-panel arena__log-panel--player">
                {playerFaces.map(
                  (face, index) =>
                    playerUsed[index] && (
                      <div className="arena__log-coin" key={index}>
                        <BitCoin face={face} multiplier={playerMultipliers[index]} used />
                      </div>
                    ),
                )}
              </div>
            </div>

            <div className="arena__stage-circles">
              <div ref={opponentCircleRef} className="arena__stage-circle arena__stage-circle--opponent">
                {opponentStageMove && <BitCoin key={exchangeLog.length} face={opponentStageMove.face} multiplier={opponentStageMove.count} />}
              </div>
              <div ref={playerCircleRef} className="arena__stage-circle arena__stage-circle--player">
                {playerStageMove && <BitCoin key={exchangeLog.length} face={playerStageMove.face} multiplier={playerStageMove.count} />}
              </div>
            </div>

            <TurnTimer deadline={battle.roundDeadlineAt} onExpire={() => void handleTimeout()} />
          </div>

          {turn === "wait" && <p className="arena__hint">Ждём ход соперника...</p>}
          {turn === "respond" && incomingMove && !pendingAbilityChoice && (
            <p className="arena__hint arena__hint--selected">
              Соперник разыграл: <strong>{FACE_LABEL[incomingMove.face]} ×{incomingMove.count}</strong>. Ответить можно только защитой, или пропусти.
            </p>
          )}
          {turn === "lead" && !pendingAbilityChoice && <p className="arena__hint">Твой ход — выбери одну или несколько одинаковых бит.</p>}

          <div className="arena__pool arena__pool--player">
            {!pendingAbilityChoice && (
              <div className="arena__coins">
                {playerFaces.map(
                  (face, index) =>
                    !playerUsed[index] && (
                      <div
                        ref={(el) => {
                          playerPoolRefs.current[index] = el;
                        }}
                        key={index}
                      >
                        <BitCoin
                          face={face}
                          multiplier={playerMultipliers[index]}
                          selectable={turn !== "wait" && face !== "empty" && (turn !== "respond" || face === "defense")}
                          selected={selectedIndices.includes(index)}
                          onClick={() => toggleOwnBit(index)}
                        />
                      </div>
                    ),
                )}
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
                          <span className="arena__ability-label">{abilityLabels[option.type] ?? option.label}</span>
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
          </div>

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

      <CombatantBar
        name={character.displayName}
        level={character.level}
        className={character.class.name}
        hp={battle.character.hp}
        maxHp={battle.character.maxHp}
        avatarUrl={character.avatarUrl}
        variant="hp"
      />

      {flyingBits.map((bit) => (
        <FlyingBitCoin key={bit.id} bit={bit} />
      ))}

      {error && <p className="arena__error">{error}</p>}
    </div>
  );
}
