import { useEffect, useRef, useState } from "react";
import type { CSSProperties, MutableRefObject } from "react";
import { fetchAbilities } from "../api/abilities";
import { fetchBattle, fetchLatestRound, submitExchangeMove, throwRound } from "../api/battles";
import { getIconUrl } from "../api/client";
import { subscribeToBattle } from "../api/realtime";
import { BitCoin, FACE_LABEL } from "../components/BitCoin";
import { CombatantBar } from "../components/CombatantBar";
import { TurnTimer } from "../components/TurnTimer";
import type { AbilityChoice, AbilityType, BattleState, Exchange, ExchangeTurn, IncomingMove, RoundResult } from "../types/battle";
import type { BitFace, Character } from "../types/character";
import "./ArenaScreen.css";

// Fallback-only poll interval while it's the other real duelist's turn
// (PvE/event never reaches this — the bot always resolves synchronously in
// the same request as the player's own move). Real-time push (Mercure, see
// ../api/realtime.ts) is the primary way an opponent's move reaches this
// client now — this just covers a dropped connection, so it can afford to
// be slow.
const PVP_POLL_FALLBACK_INTERVAL_MS = 10000;

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

// getIconUrl("bits", ...) for a possibly-absent per-bit icon — null/undefined
// falls through to null, letting BitCoin fall back to its generic emoji.
function bitIconUrl(icon: string | null | undefined): string | null {
  return icon ? getIconUrl("bits", icon) : null;
}

// One side's currently-showing move in its stage circle — either still
// resting there after just resolving (cleared once its fly-to-log
// animation lands, see scheduleCircleToLog) or, for the opponent, a
// not-yet-resolved incoming lead (see the opponentCircleMove derivation
// below). null means the circle is empty.
interface StageMove {
  face: BitFace;
  count: number;
  icon: string | null;
}

// Summarizes a group of same-face bits (by index) into one circle-ready
// move — count is the sum of their multipliers, matching how a real
// exchange's leaderCount/responderCount already represents a whole group;
// icon is the first bit's own art (same convention the backend uses when
// building pendingLeaderMove server-side).
function aggregateMove(indices: number[], faces: BitFace[], multipliers: number[], icons: (string | null)[]): StageMove | null {
  if (indices.length === 0) return null;
  return {
    face: faces[indices[0]],
    count: indices.reduce((sum, index) => sum + (multipliers[index] ?? 1), 0),
    icon: icons[indices[0]] ?? null,
  };
}

// A bit mid-flight from its pool slot to the stage circle it was just
// played into — spawned in spawnFlyingBits() below, purely cosmetic (the
// real state transition already happened by the time this renders).
interface FlyingBit {
  id: string;
  face: BitFace;
  multiplier: number;
  icon: string | null;
  from: DOMRect;
  to: DOMRect;
}

const FLIGHT_DURATION_MS = 450;
// How long a resolved move sits visibly in the stage circle before flying
// on to the used-bit log panel — gives the player a beat to register what
// just happened instead of it vanishing into the log instantly.
const CIRCLE_REST_MS = 500;

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
      <BitCoin face={bit.face} multiplier={bit.multiplier} iconUrl={bitIconUrl(bit.icon)} />
    </div>
  );
}

// Flies each of `indices` from its last known pool position (via `refs`)
// into `circleEl` (the matching side's stage circle). Used for bits that
// become played without having gone through the player's own pre-confirm
// selection flight (see toggleOwnBit) — the opponent/bot's side always,
// and the player's own side only as a fallback should it ever become used
// without being staged first.
function poolToCircleFlights(
  indices: number[],
  faces: BitFace[],
  multipliers: number[],
  icons: (string | null)[],
  refs: MutableRefObject<Record<number, HTMLDivElement | null>>,
  circleEl: HTMLDivElement | null,
): FlyingBit[] {
  if (!circleEl || indices.length === 0) return [];
  const to = circleEl.getBoundingClientRect();
  const entries: FlyingBit[] = [];
  for (const index of indices) {
    const el = refs.current[index];
    if (!el) continue;
    entries.push({
      id: `in-${index}-${Date.now()}-${Math.random().toString(36).slice(2)}`,
      face: faces[index],
      multiplier: multipliers[index] ?? 1,
      icon: icons[index] ?? null,
      from: el.getBoundingClientRect(),
      to,
    });
  }
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
  const [playerIcons, setPlayerIcons] = useState<(string | null)[]>([]);
  const [opponentIcons, setOpponentIcons] = useState<(string | null)[]>([]);
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
  const opponentLogPanelRef = useRef<HTMLDivElement | null>(null);
  const playerLogPanelRef = useRef<HTMLDivElement | null>(null);
  // The last used[] arrays actually applied to state, tracked outside React
  // state so applyExchangeSnapshot() always diffs against a true "previous"
  // value — reading playerUsed/opponentUsed state directly would go stale
  // inside the PvP poll's setInterval closure across repeated ticks.
  const lastPlayerUsedRef = useRef<boolean[]>([]);
  const lastOpponentUsedRef = useRef<boolean[]>([]);
  // A used bit only appears in its log panel once "revealed" — i.e. once
  // its circle → log flight (scheduleCircleToLog below) has landed. Parallel
  // to playerUsed/opponentUsed, but lags behind them during that flight.
  const [playerLogRevealed, setPlayerLogRevealed] = useState<boolean[]>([]);
  const [opponentLogRevealed, setOpponentLogRevealed] = useState<boolean[]>([]);
  // What's currently resting in each side's stage circle, post-resolution —
  // cleared back to null the moment its fly-to-log animation lands (see
  // scheduleCircleToLog), so an empty circle really means "nothing from
  // this cycle is left to show", not "nothing has ever happened yet".
  const [playerRestingMove, setPlayerRestingMove] = useState<StageMove | null>(null);
  const [opponentRestingMove, setOpponentRestingMove] = useState<StageMove | null>(null);

  function spawnFlyingBits(entries: FlyingBit[]) {
    if (entries.length === 0) return;
    setFlyingBits((current) => [...current, ...entries]);
    window.setTimeout(() => {
      setFlyingBits((current) => current.filter((b) => !entries.includes(b)));
    }, FLIGHT_DURATION_MS);
  }

  function revealInLog(side: "player" | "opponent", indices: number[]) {
    const setRevealed = side === "player" ? setPlayerLogRevealed : setOpponentLogRevealed;
    setRevealed((current) => {
      const next = [...current];
      for (const index of indices) next[index] = true;
      return next;
    });
  }

  // After a short rest in the stage circle, flies `indices` on to their
  // side's log panel, marks them revealed there, and empties that side's
  // circle back out (see playerRestingMove/opponentRestingMove — this is
  // what makes an already-resolved cycle's circle go back to empty instead
  // of holding onto the last move forever). `restingMove` is the exact
  // object this call just put in the circle — the clear only actually
  // applies if it's still there by the time it fires, so a fast follow-up
  // exchange (e.g. a PvE auto-advance chain) that's already replaced it
  // can't get wiped out early by this older, slower timeout. Rects are
  // captured at fire time (not schedule time), and the whole thing degrades
  // to an instant, unanimated reveal if the board has since unmounted (e.g.
  // the round resolved while this was pending) — see isConnected below.
  function scheduleCircleToLog(
    side: "player" | "opponent",
    indices: number[],
    faces: BitFace[],
    multipliers: number[],
    icons: (string | null)[],
    restingMove: StageMove | null,
  ) {
    if (indices.length === 0) return;
    const setRestingMove = side === "player" ? setPlayerRestingMove : setOpponentRestingMove;
    const clearIfStillCurrent = () => setRestingMove((current) => (current === restingMove ? null : current));
    window.setTimeout(() => {
      const circleEl = (side === "player" ? playerCircleRef : opponentCircleRef).current;
      const logEl = (side === "player" ? playerLogPanelRef : opponentLogPanelRef).current;
      if (!circleEl?.isConnected || !logEl?.isConnected) {
        revealInLog(side, indices);
        clearIfStillCurrent();
        return;
      }
      const from = circleEl.getBoundingClientRect();
      const to = logEl.getBoundingClientRect();
      spawnFlyingBits(
        indices.map((index) => ({
          id: `out-${side}-${index}-${Date.now()}-${Math.random().toString(36).slice(2)}`,
          face: faces[index],
          multiplier: multipliers[index] ?? 1,
          icon: icons[index] ?? null,
          from,
          to,
        })),
      );
      window.setTimeout(() => {
        revealInLog(side, indices);
        clearIfStillCurrent();
      }, FLIGHT_DURATION_MS);
    }, CIRCLE_REST_MS);
  }

  // Applies a fresh used/faces/multipliers/icons snapshot for both sides.
  // Bits newly used without already being staged in the circle (the
  // opponent's side, always — the player's own only as a fallback, see
  // toggleOwnBit) fly pool → circle first; each side's newly-used group then
  // becomes its resting move (shown in the circle) and, after a beat, flies
  // on to its log panel via scheduleCircleToLog, which empties the circle again.
  function applyExchangeSnapshot(next: {
    playerFaces: BitFace[];
    opponentFaces: BitFace[];
    playerUsed: boolean[];
    opponentUsed: boolean[];
    playerMultipliers: number[];
    opponentMultipliers: number[];
    playerIcons: (string | null)[];
    opponentIcons: (string | null)[];
  }) {
    const playerNewlyUsed: number[] = [];
    next.playerUsed.forEach((used, index) => {
      if (used && !lastPlayerUsedRef.current[index]) playerNewlyUsed.push(index);
    });
    const opponentNewlyUsed: number[] = [];
    next.opponentUsed.forEach((used, index) => {
      if (used && !lastOpponentUsedRef.current[index]) opponentNewlyUsed.push(index);
    });

    const playerMove = aggregateMove(playerNewlyUsed, next.playerFaces, next.playerMultipliers, next.playerIcons);
    const opponentMove = aggregateMove(opponentNewlyUsed, next.opponentFaces, next.opponentMultipliers, next.opponentIcons);
    if (playerMove) setPlayerRestingMove(playerMove);
    if (opponentMove) setOpponentRestingMove(opponentMove);

    const playerNeedingFlyIn = playerNewlyUsed.filter((index) => !selectedIndices.includes(index));
    spawnFlyingBits([
      ...poolToCircleFlights(playerNeedingFlyIn, next.playerFaces, next.playerMultipliers, next.playerIcons, playerPoolRefs, playerCircleRef.current),
      ...poolToCircleFlights(
        opponentNewlyUsed,
        next.opponentFaces,
        next.opponentMultipliers,
        next.opponentIcons,
        opponentPoolRefs,
        opponentCircleRef.current,
      ),
    ]);
    scheduleCircleToLog("player", playerNewlyUsed, next.playerFaces, next.playerMultipliers, next.playerIcons, playerMove);
    scheduleCircleToLog("opponent", opponentNewlyUsed, next.opponentFaces, next.opponentMultipliers, next.opponentIcons, opponentMove);

    lastPlayerUsedRef.current = next.playerUsed;
    lastOpponentUsedRef.current = next.opponentUsed;
    setPlayerFaces(next.playerFaces);
    setOpponentFaces(next.opponentFaces);
    setPlayerUsed(next.playerUsed);
    setOpponentUsed(next.opponentUsed);
    setPlayerMultipliers(next.playerMultipliers);
    setOpponentMultipliers(next.opponentMultipliers);
    setPlayerIcons(next.playerIcons);
    setOpponentIcons(next.opponentIcons);
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
      setPlayerLogRevealed(result.playerFaces.map(() => false));
      setOpponentLogRevealed(result.opponentFaces.map(() => false));
      setPlayerRestingMove(null);
      setOpponentRestingMove(null);
      setPlayerMultipliers(result.playerMultipliers);
      setOpponentMultipliers(result.opponentMultipliers);
      setPlayerIcons(result.playerIcons);
      setOpponentIcons(result.opponentIcons);
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

  // Picks up the other duelist's move: fetches the fresh battle/exchange
  // snapshot and applies it exactly like a throw response would. Shared by
  // the real-time push handler, the slow poll fallback, and handleTimeout
  // below — all three just need "go see what changed right now".
  async function refreshFromServer() {
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
        playerIcons: updated.exchange.playerIcons,
        opponentIcons: updated.exchange.opponentIcons,
      });
      setTurn(updated.exchange.turn);
      setIncomingMove(updated.exchange.incomingMove);
      return;
    }

    // No pending exchange left — the other duelist's move just finished
    // the round (or knocked someone out mid-exchange).
    const round = await fetchLatestRound(battle.id);
    if (round) {
      setExchangeLog(round.exchanges);
      setLastRound(round);
    }
    setPhase("resolved");
  }

  // PvP only: real-time push is the primary way an opponent's move reaches
  // this client — active for the whole battle (not just while turn==="wait"),
  // since it's one persistent connection rather than repeated requests.
  useEffect(() => {
    if (battle.mode !== "pvp" || phase !== "playing") {
      return;
    }

    const unsubscribe = subscribeToBattle(battle.id, () => {
      void refreshFromServer().catch(() => {
        // Transient failure — the poll fallback below or the next push recovers.
      });
    });

    return unsubscribe;
    // refreshFromServer closes over battle.id/applyExchangeSnapshot, both
    // stable in the ways that matter here — see the poll effect right below
    // for the same reasoning, which this mirrors.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [battle.mode, battle.id, phase]);

  // PvP only, fallback: while it's the other duelist's turn, also poll —
  // covers a dropped/reconnecting push connection. PvE/event never sets
  // turn to "wait" (the bot always resolves inline).
  useEffect(() => {
    if (battle.mode !== "pvp" || turn !== "wait" || phase !== "playing") {
      return;
    }

    const interval = setInterval(() => {
      void refreshFromServer().catch(() => {
        // Transient poll failure — try again next tick.
      });
    }, PVP_POLL_FALLBACK_INTERVAL_MS);

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
      await refreshFromServer();
      setSelectedIndices([]);
      setPendingAbilityChoice(false);
      setFlipTargets([]);
    } catch {
      // Transient failure — the timer will have already hit 0; the next
      // request the player makes (or the next push/poll) recovers.
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

    // Selecting (not deselecting) flies the bit from its pool slot into the
    // player's stage circle right away, instead of just outlining it in
    // place — it visually stays there, staged, until the move is confirmed
    // (or the selection changes) — see the player circle's render below.
    if (!selectedIndices.includes(index)) {
      const el = playerPoolRefs.current[index];
      const circleEl = playerCircleRef.current;
      if (el && circleEl) {
        spawnFlyingBits([
          {
            id: `select-${index}-${Date.now()}-${Math.random().toString(36).slice(2)}`,
            face: playerFaces[index],
            multiplier: playerMultipliers[index] ?? 1,
            icon: playerIcons[index] ?? null,
            from: el.getBoundingClientRect(),
            to: circleEl.getBoundingClientRect(),
          },
        ]);
      }
    }

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

  // The opponent's own incoming lead (not yet resolved, still awaiting the
  // player's response) always takes priority over a merely-resting past
  // move — it's live and more current than anything already sitting there.
  const opponentCircleMove: StageMove | null = incomingMove
    ? { face: incomingMove.face, count: incomingMove.count, icon: incomingMove.icon }
    : opponentRestingMove;

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
        frameName={battle.opponent.frameName}
        variant="enemy"
      />

      {phase === "loading" && <p className="arena__hint">Бросаем биты...</p>}

      {phase === "playing" && (
        <div className="arena__board">
          <div className="arena__pool arena__pool--opponent">
            <p className="arena__pool-label">Биты противника</p>
            <div className="arena__coins">
              {opponentFaces.map((face, index) => (
                <div
                  ref={(el) => {
                    opponentPoolRefs.current[index] = el;
                  }}
                  className="arena__pool-slot"
                  key={index}
                >
                  {!opponentUsed[index] && (
                    <BitCoin
                      face={face}
                      multiplier={opponentMultipliers[index]}
                      iconUrl={bitIconUrl(opponentIcons[index])}
                      selectable={pendingAbilityChoice && selectedAbility === "flip"}
                      selected={flipTargets.includes(index)}
                      onClick={() => toggleFlipTarget(index)}
                    />
                  )}
                </div>
              ))}
            </div>
          </div>

          <div className="arena__stage">
            <div className="arena__stage-logs">
              <div ref={opponentLogPanelRef} className="arena__log-panel arena__log-panel--opponent">
                {opponentFaces.map(
                  (face, index) =>
                    opponentUsed[index] &&
                    opponentLogRevealed[index] && (
                      <div className="arena__log-coin" key={index}>
                        <BitCoin face={face} multiplier={opponentMultipliers[index]} iconUrl={bitIconUrl(opponentIcons[index])} used />
                      </div>
                    ),
                )}
              </div>
              <div ref={playerLogPanelRef} className="arena__log-panel arena__log-panel--player">
                {playerFaces.map(
                  (face, index) =>
                    playerUsed[index] &&
                    playerLogRevealed[index] && (
                      <div className="arena__log-coin" key={index}>
                        <BitCoin face={face} multiplier={playerMultipliers[index]} iconUrl={bitIconUrl(playerIcons[index])} used />
                      </div>
                    ),
                )}
              </div>
            </div>

            <div className="arena__stage-circles">
              <div ref={opponentCircleRef} className="arena__stage-circle arena__stage-circle--opponent">
                {opponentCircleMove && (
                  <BitCoin
                    key={`${opponentCircleMove.face}-${opponentCircleMove.count}`}
                    face={opponentCircleMove.face}
                    multiplier={opponentCircleMove.count}
                    iconUrl={bitIconUrl(opponentCircleMove.icon)}
                  />
                )}
              </div>
              <div ref={playerCircleRef} className="arena__stage-circle arena__stage-circle--player">
                {selectedIndices.length > 0
                  ? selectedIndices.map((index, i) => (
                      <div
                        className="arena__staged-coin"
                        key={index}
                        style={{ zIndex: i, transform: `translate(${i * 6}px, ${i * -6}px)` }}
                      >
                        <BitCoin face={playerFaces[index]} multiplier={playerMultipliers[index]} iconUrl={bitIconUrl(playerIcons[index])} />
                      </div>
                    ))
                  : playerRestingMove && (
                      <BitCoin
                        key={`${playerRestingMove.face}-${playerRestingMove.count}`}
                        face={playerRestingMove.face}
                        multiplier={playerRestingMove.count}
                        iconUrl={bitIconUrl(playerRestingMove.icon)}
                      />
                    )}
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
                {playerFaces.map((face, index) => (
                  <div
                    ref={(el) => {
                      playerPoolRefs.current[index] = el;
                    }}
                    className="arena__pool-slot"
                    key={index}
                  >
                    {!playerUsed[index] && !selectedIndices.includes(index) && (
                      <BitCoin
                        face={face}
                        multiplier={playerMultipliers[index]}
                        iconUrl={bitIconUrl(playerIcons[index])}
                        selectable={turn !== "wait" && face !== "empty" && (turn !== "respond" || face === "defense")}
                        onClick={() => toggleOwnBit(index)}
                      />
                    )}
                  </div>
                ))}
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
                {(turn === "respond" || turn === "lead") && (
                  <button className="arena__action arena__action--secondary" disabled={busy} onClick={() => void sendMove([])}>
                    {turn === "respond" ? "Не отвечать" : "Пропустить ход"}
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
        frameName={character.class.frameName}
        variant="hp"
      />

      {flyingBits.map((bit) => (
        <FlyingBitCoin key={bit.id} bit={bit} />
      ))}

      {error && <p className="arena__error">{error}</p>}
    </div>
  );
}
