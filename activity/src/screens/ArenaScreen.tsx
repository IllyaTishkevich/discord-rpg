import { useEffect, useRef, useState } from "react";
import type { CSSProperties, MutableRefObject } from "react";
import { fetchAbilities } from "../api/abilities";
import { fetchBattle, fetchLatestRound, submitExchangeMove, throwRound } from "../api/battles";
import { getIconUrl } from "../api/client";
import { subscribeToBattle } from "../api/realtime";
import { BitCoin, FACE_LABEL } from "../components/BitCoin";
import { CombatantBar } from "../components/CombatantBar";
import { TurnTimer } from "../components/TurnTimer";
import type { AbilityCatalogEntry, AbilityChoice, AbilityType, BattleState, Exchange, ExchangeTurn, IncomingMove, RoundResult } from "../types/battle";
import type { BitFace, Character } from "../types/character";
import "./ArenaScreen.css";

// Fallback-only poll interval while it's the other real duelist's turn
// (PvE/event never reaches this — the bot always resolves synchronously in
// the same request as the player's own move). Real-time push (WebSocket,
// see ../api/realtime.ts) is the primary way an opponent's move reaches
// this client now — this just covers a dropped connection, so it can
// afford to be slow.
const PVP_POLL_FALLBACK_INTERVAL_MS = 10000;

type Phase = "loading" | "playing" | "resolved";

// entry.actionCost === null means "variable" (Flip, UnblockableDamage) —
// spends however many action points were rolled this move, always
// affordable since this screen only ever shows with at least 1 spent.
function isAffordable(entry: AbilityCatalogEntry, spentCount: number): boolean {
  return null === entry.actionCost || spentCount >= entry.actionCost;
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
// How long to wait before auto-passing a turn with no legal move (see the
// effect below) — long enough to actually register what the opponent's
// last move was before getting swept straight past your own turn.
const AUTO_PASS_DELAY_MS = 2000;

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
  // Non-null only while the no-legal-move auto-pass below is counting down
  // — surfaces *why* the turn is about to be skipped, and locks the move
  // controls/bit pool so nothing races the auto-pass itself.
  const [autoPassSecondsLeft, setAutoPassSecondsLeft] = useState<number | null>(null);
  const [selectedIndices, setSelectedIndices] = useState<number[]>([]);
  const [pendingAbilityChoice, setPendingAbilityChoice] = useState(false);
  const [selectedAbility, setSelectedAbility] = useState<AbilityType>(defaultAbility(character.abilities));
  // Admin-editable label/description/cost/icon per ability (App\Entity\Ability),
  // fetched once on mount — see the effect below. Empty until it resolves;
  // availableAbilityOptions is then simply empty too for that brief window
  // (same "Нет доступных способностей" message already shown for a
  // character with no abilities at all).
  const [abilityCatalog, setAbilityCatalog] = useState<AbilityCatalogEntry[]>([]);
  const availableAbilityOptions = abilityCatalog.filter((entry) => character.abilities.includes(entry.type));
  const [flipTargets, setFlipTargets] = useState<number[]>([]);
  // Flip can also target the caster's own not-yet-played bits, alongside
  // (or instead of) the opponent's — a separate list since the two pools
  // render and get toggled independently, sharing one combined budget
  // (selectedIndices.length, i.e. however many action points were spent).
  const [flipOwnTargets, setFlipOwnTargets] = useState<number[]>([]);
  // Double always targets exactly one of the caster's own not-yet-played
  // bits — kept as a 0/1-length array (like flipOwnTargets) rather than a
  // plain nullable index purely so the same toggle/render/reset patterns
  // apply to both.
  const [doubleTargets, setDoubleTargets] = useState<number[]>([]);
  // Reroll targets exactly one bit total, own OR opponent — same
  // combined-budget idea as Flip's own+opponent pair, just capped at 1
  // instead of selectedIndices.length.
  const [rerollTargets, setRerollTargets] = useState<number[]>([]);
  const [rerollOwnTargets, setRerollOwnTargets] = useState<number[]>([]);
  const [exchangeLog, setExchangeLog] = useState<Exchange[]>([]);
  const [lastRound, setLastRound] = useState<RoundResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
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
  // Indices newly marked used by a side whose move hasn't actually resolved
  // yet — my own lead still awaiting the opponent's separate response (PvP,
  // turn === "wait"), or the opponent's/bot's own lead still awaiting mine
  // (incomingMove present) — held back from scheduleCircleToLog() until
  // that exchange genuinely resolves, instead of flying to the log
  // immediately just because the bit got flagged used. See
  // applyExchangeSnapshot()'s use of this below.
  const pendingRevealRef = useRef<{ player: number[]; opponent: number[] }>({ player: [], opponent: [] });
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
  // Mirrors of the two states above, kept in lockstep with every setter call
  // below — needed because scheduleCircleToLog's "is this still what's
  // showing, or did something replace it already" check must compare against
  // the exact object currently resting, not a value it recomputes itself:
  // aggregateMove() returns a brand-new object every call, so two calls
  // describing the very same bits are never === to each other. Reading these
  // refs (always in sync) instead of re-deriving the value sidesteps that.
  const playerRestingMoveRef = useRef<StageMove | null>(null);
  const opponentRestingMoveRef = useRef<StageMove | null>(null);

  function updatePlayerRestingMove(move: StageMove | null) {
    playerRestingMoveRef.current = move;
    setPlayerRestingMove(move);
  }
  function updateOpponentRestingMove(move: StageMove | null) {
    opponentRestingMoveRef.current = move;
    setOpponentRestingMove(move);
  }

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
    const restingMoveRef = side === "player" ? playerRestingMoveRef : opponentRestingMoveRef;
    const clearIfStillCurrent = () => {
      if (restingMoveRef.current !== restingMove) return;
      restingMoveRef.current = null;
      setRestingMove(null);
    };
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
  // on to its log panel via scheduleCircleToLog, which empties the circle
  // again — but only once that bit's own exchange has actually resolved.
  // A side can be marked "used" while its move is still pending someone
  // else's response — my own just-submitted lead (PvP, turn === "wait",
  // waiting on the real opponent) or the opponent's/bot's own lead waiting
  // on mine (incomingMove present, e.g. mid PvE auto-advance chain, right
  // after my own lead resolved and the bot immediately led the next
  // exchange in the same response) — so those newly-used indices are held
  // in pendingRevealRef instead of scheduled immediately, and only flushed
  // (plus whatever's newly used this call) once that side is no longer
  // pending. Without this, a still-pending move's bits would fly into the
  // log before the exchange they belong to ever resolved.
  function applyExchangeSnapshot(next: {
    playerFaces: BitFace[];
    opponentFaces: BitFace[];
    playerUsed: boolean[];
    opponentUsed: boolean[];
    playerMultipliers: number[];
    opponentMultipliers: number[];
    playerIcons: (string | null)[];
    opponentIcons: (string | null)[];
    turn: ExchangeTurn;
    incomingMove: IncomingMove | null;
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
    if (playerMove) updatePlayerRestingMove(playerMove);
    if (opponentMove) updateOpponentRestingMove(opponentMove);

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

    // turn === "wait" (PvP) covers two different situations, and only the
    // first one means the player's own bit is still genuinely unresolved:
    // (1) the player just led and the real opponent hasn't responded yet —
    //     incomingMove is set (it's the player's own pending lead, per
    //     BattleSerializer's "symmetric" incomingMove — see opponentCircleMove
    //     below), and the bit must keep waiting.
    // (2) that exchange has since resolved (the opponent responded) and it's
    //     now the opponent's turn to lead the *next* one — incomingMove is
    //     null again, turn is still "wait" (still not this player's turn),
    //     but the earlier bit already did its job and must flush now, not
    //     keep waiting for a second exchange it was never part of. Without
    //     this distinction, that bit — and its circle — got stuck forever
    //     (or, if the round happened to end right there, silently lost when
    //     the next round's reset wiped pendingRevealRef).
    const playerPending = "wait" === next.turn && null !== next.incomingMove;
    // Same distinction as opponentCircleMove's own gating below: incomingMove
    // is only genuinely the opponent's still-unresolved move when it's this
    // viewer's turn to respond to it — when turn is "wait" instead (PvP),
    // incomingMove describes the player's own pending lead, and there's
    // nothing of the opponent's to hold back here (opponentNewlyUsed would
    // be empty in that case anyway, since nothing on their side changed).
    const opponentPending = null !== next.incomingMove && "respond" === next.turn;

    const playerToReveal = [...pendingRevealRef.current.player, ...playerNewlyUsed];
    pendingRevealRef.current.player = playerPending ? playerToReveal : [];
    const opponentToReveal = [...pendingRevealRef.current.opponent, ...opponentNewlyUsed];
    pendingRevealRef.current.opponent = opponentPending ? opponentToReveal : [];

    if (!playerPending) {
      scheduleCircleToLog(
        "player",
        playerToReveal,
        next.playerFaces,
        next.playerMultipliers,
        next.playerIcons,
        playerRestingMoveRef.current,
      );
    }
    if (!opponentPending) {
      scheduleCircleToLog(
        "opponent",
        opponentToReveal,
        next.opponentFaces,
        next.opponentMultipliers,
        next.opponentIcons,
        opponentRestingMoveRef.current,
      );
    }

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
      .then(setAbilityCatalog)
      .catch(() => {
        // Leave abilityCatalog empty — availableAbilityOptions then reads
        // as "no abilities available", same message shown for a character
        // that genuinely has none.
      });
  }, []);

  async function handleThrow() {
    setBusy(true);
    setError(null);
    try {
      const result = await throwRound(battle.id);
      setBattle(result.battle);
      // Baseline "nothing used yet", then run the fresh snapshot through the
      // same diffing applyExchangeSnapshot always uses — never assign
      // result.playerUsed/opponentUsed straight into last*UsedRef here.
      // Doing that used to make any bit the opponent already led with before
      // this client ever saw the round (e.g. a monster that moves first)
      // look like it had "always" been used: applyExchangeSnapshot would
      // then never see it as newly used, so it never entered
      // pendingRevealRef and never got its scheduleCircleToLog flight —
      // it just sat in the circle via the incomingMove fallback while
      // "respond" was pending, then silently vanished (no reveal, no log
      // entry) the moment the exchange resolved.
      lastPlayerUsedRef.current = result.playerFaces.map(() => false);
      lastOpponentUsedRef.current = result.opponentFaces.map(() => false);
      pendingRevealRef.current = { player: [], opponent: [] };
      setPlayerLogRevealed(result.playerFaces.map(() => false));
      setOpponentLogRevealed(result.opponentFaces.map(() => false));
      updatePlayerRestingMove(null);
      updateOpponentRestingMove(null);
      setFlyingBits([]);
      applyExchangeSnapshot({
        playerFaces: result.playerFaces,
        opponentFaces: result.opponentFaces,
        playerUsed: result.playerUsed ?? result.playerFaces.map(() => false),
        opponentUsed: result.opponentUsed ?? result.opponentFaces.map(() => false),
        playerMultipliers: result.playerMultipliers,
        opponentMultipliers: result.opponentMultipliers,
        playerIcons: result.playerIcons,
        opponentIcons: result.opponentIcons,
        turn: result.turn ?? "lead",
        incomingMove: result.incomingMove ?? null,
      });
      setTurn(result.turn);
      setIncomingMove(result.incomingMove);
      setExchangeLog([]);
      setSelectedIndices([]);
      setPendingAbilityChoice(false);
      setSelectedAbility(defaultAbility(character.abilities));
      setFlipTargets([]);
      setFlipOwnTargets([]);
      setDoubleTargets([]);
      setRerollTargets([]);
      setRerollOwnTargets([]);
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
      // turn/incomingMove are omitted once roundComplete — nothing is
      // "pending" any more at that point, so any not-yet-revealed bits
      // should flush right away (see applyExchangeSnapshot's docblock).
      applyExchangeSnapshot({ ...response, turn: response.turn ?? "lead", incomingMove: response.incomingMove ?? null });
      setExchangeLog((current) => [...current, ...response.newExchanges]);
      setBattle(response.battle);
      setSelectedIndices([]);
      setPendingAbilityChoice(false);
      setSelectedAbility(defaultAbility(character.abilities));
      setFlipTargets([]);
      setFlipOwnTargets([]);
      setDoubleTargets([]);
      setRerollTargets([]);
      setRerollOwnTargets([]);

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
      // The rejection itself means this client's idea of the board was
      // stale (someone else's move landed first, or a timeout auto-passed
      // this exact decision server-side already) — resync immediately
      // instead of leaving the player stuck retrying the same now-invalid
      // move against unchanged local state.
      void refreshFromServer().catch(() => {
        // Transient failure — the next push/poll recovers.
      });
    } finally {
      setBusy(false);
    }
  }

  // Picks up the other duelist's move: fetches the fresh battle/exchange
  // snapshot and applies it exactly like a throw response would. Shared by
  // the real-time push handler, the slow poll fallback, and handleTimeout
  // below — all three just need "go see what changed right now". The push
  // handler in particular can fire mid-selection (it's live for the whole
  // battle, not just while turn==="wait" — e.g. the opponent's action
  // resolved a whole flurry of exchanges in one go before this side gets to
  // act again), so any locally selected-but-not-yet-submitted bits are
  // always dropped here rather than only on a timeout: a stale index that
  // pointed at an unused bit a moment ago can point at an already-used one
  // now, and submitting it would just bounce off the server as invalid.
  async function refreshFromServer() {
    const updated = await fetchBattle(battle.id);
    setBattle(updated);
    setSelectedIndices([]);
    setPendingAbilityChoice(false);
    setFlipTargets([]);
    setFlipOwnTargets([]);
    setDoubleTargets([]);
    setRerollTargets([]);
    setRerollOwnTargets([]);

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
        turn: updated.exchange.turn ?? "lead",
        incomingMove: updated.exchange.incomingMove,
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

  // Auto-passes a lead or respond turn the player has no legal bit for at
  // all — every remaining bit is already used, empty-faced, or (while
  // responding, which only ever accepts defense) simply the wrong face.
  // Forcing a manual "Пропустить ход" click for a decision that was never
  // actually available to make would just be busywork every single time.
  // Waits AUTO_PASS_DELAY_MS first so the player actually gets to register
  // what the opponent's move just was before getting swept past their own
  // turn — the cleanup below cancels that wait if anything changes first
  // (the opponent's next move arriving, the round ending, etc.), so this
  // never fires against stale conditions.
  // Never fires mid-selection (pendingAbilityChoice) — the player's picked
  // bits haven't been submitted yet, so playerUsed/playerFaces don't
  // reflect them, and hasLegalMove still correctly sees them as available.
  useEffect(() => {
    if (busy || phase !== "playing" || pendingAbilityChoice || ("lead" !== turn && "respond" !== turn)) {
      setAutoPassSecondsLeft(null);
      return;
    }
    const hasLegalMove = playerFaces.some(
      (face, index) => !playerUsed[index] && "empty" !== face && ("respond" !== turn || "defense" === face),
    );
    if (hasLegalMove) {
      setAutoPassSecondsLeft(null);
      return;
    }

    const deadline = Date.now() + AUTO_PASS_DELAY_MS;
    setAutoPassSecondsLeft(Math.ceil(AUTO_PASS_DELAY_MS / 1000));
    const tick = window.setInterval(() => {
      setAutoPassSecondsLeft(Math.max(0, Math.ceil((deadline - Date.now()) / 1000)));
    }, 200);
    const timer = window.setTimeout(() => {
      void sendMove([]);
    }, AUTO_PASS_DELAY_MS);
    return () => {
      window.clearTimeout(timer);
      window.clearInterval(tick);
    };
    // sendMove itself sets `busy` synchronously before its first await, and
    // its own success always moves `turn` away from "lead"/"respond" (or
    // ends the round) — so this can't re-fire for the same decision twice.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [turn, playerFaces, playerUsed, pendingAbilityChoice, busy, phase]);

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
    } catch {
      // Transient failure — the timer will have already hit 0; the next
      // request the player makes (or the next push/poll) recovers.
    }
  }

  function toggleOwnBit(index: number) {
    if (pendingAbilityChoice || playerUsed[index] || null !== autoPassSecondsLeft) return;
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
      if (current.length + flipOwnTargets.length >= selectedIndices.length) {
        return current;
      }
      return [...current, index];
    });
  }

  // Same idea as toggleFlipTarget, but for the caster's own pool — the two
  // share one combined budget (see flipOwnTargets' declaration above).
  function toggleOwnFlipTarget(index: number) {
    if (playerUsed[index] || selectedIndices.includes(index)) return;
    setFlipOwnTargets((current) => {
      if (current.includes(index)) {
        return current.filter((i) => i !== index);
      }
      if (current.length + flipTargets.length >= selectedIndices.length) {
        return current;
      }
      return [...current, index];
    });
  }

  // Double's target picker — always exactly one bit, so picking a new one
  // simply replaces whatever was picked before instead of adding to it.
  function toggleDoubleTarget(index: number) {
    if (playerUsed[index] || selectedIndices.includes(index)) return;
    setDoubleTargets((current) => (current.includes(index) ? [] : [index]));
  }

  // Reroll's opponent-side target — same single-target semantics as
  // toggleDoubleTarget, but sharing its budget with toggleRerollOwnTarget
  // below (at most one bit total, either pool).
  function toggleRerollTarget(index: number) {
    if (opponentUsed[index]) return;
    setRerollTargets((current) => {
      if (current.includes(index)) return [];
      return rerollOwnTargets.length > 0 ? current : [index];
    });
  }

  function toggleRerollOwnTarget(index: number) {
    if (playerUsed[index] || selectedIndices.includes(index)) return;
    setRerollOwnTargets((current) => {
      if (current.includes(index)) return [];
      return rerollTargets.length > 0 ? current : [index];
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
    void sendMove(selectedIndices, {
      ability: selectedAbility,
      targets:
        selectedAbility === "flip"
          ? flipTargets
          : selectedAbility === "double"
            ? doubleTargets
            : selectedAbility === "reroll"
              ? rerollTargets
              : [],
      ownTargets: selectedAbility === "flip" ? flipOwnTargets : selectedAbility === "reroll" ? rerollOwnTargets : [],
    });
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
  // Gated on turn === "respond": the backend's incomingMove is a symmetric
  // description of whatever lead is currently pending (see
  // BattleSerializer::exchangeMoveResultForViewer()'s own comment) — when
  // it's PvP and turn === "wait" instead, that pending move is actually the
  // player's own just-submitted lead, still waiting on the real opponent's
  // response, not something the opponent played. Showing it here
  // unconditionally put the player's own bits in the opponent's circle.
  const opponentCircleMove: StageMove | null =
    incomingMove && "respond" === turn ? { face: incomingMove.face, count: incomingMove.count, icon: incomingMove.icon } : opponentRestingMove;
  const selectedAbilityEntry = availableAbilityOptions.find((entry) => entry.type === selectedAbility) ?? null;

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
                      selectable={pendingAbilityChoice && ("flip" === selectedAbility || "reroll" === selectedAbility)}
                      selected={"flip" === selectedAbility ? flipTargets.includes(index) : rerollTargets.includes(index)}
                      onClick={() => ("flip" === selectedAbility ? toggleFlipTarget(index) : toggleRerollTarget(index))}
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
          {turn === "lead" && !pendingAbilityChoice && null === autoPassSecondsLeft && (
            <p className="arena__hint">Твой ход — выбери одну или несколько одинаковых бит.</p>
          )}
          {null !== autoPassSecondsLeft && (
            <p className="arena__hint arena__hint--autopass">
              Нет доступных ходов — автопропуск через {autoPassSecondsLeft}с...
            </p>
          )}

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
                        selectable={
                          null === autoPassSecondsLeft && turn !== "wait" && face !== "empty" && (turn !== "respond" || face === "defense")
                        }
                        onClick={() => toggleOwnBit(index)}
                      />
                    )}
                  </div>
                ))}
              </div>
            )}

            {pendingAbilityChoice && (selectedAbility === "flip" || selectedAbility === "double" || selectedAbility === "reroll") && (
              <div className="arena__coins">
                {playerFaces.map((face, index) => (
                  <div className="arena__pool-slot" key={index}>
                    {!playerUsed[index] && !selectedIndices.includes(index) && (
                      <BitCoin
                        face={face}
                        multiplier={playerMultipliers[index]}
                        iconUrl={bitIconUrl(playerIcons[index])}
                        selectable
                        selected={
                          "flip" === selectedAbility
                            ? flipOwnTargets.includes(index)
                            : "double" === selectedAbility
                              ? doubleTargets.includes(index)
                              : rerollOwnTargets.includes(index)
                        }
                        onClick={() => {
                          if ("flip" === selectedAbility) toggleOwnFlipTarget(index);
                          else if ("double" === selectedAbility) toggleDoubleTarget(index);
                          else toggleRerollOwnTarget(index);
                        }}
                      />
                    )}
                  </div>
                ))}
              </div>
            )}

            {pendingAbilityChoice && (
              <div className="arena__abilities">
                <p className="arena__hint">Разыгрывается действие ×{selectedIndices.length}. Выбери способность:</p>
                <div className="arena__ability-row">
                  <div className="arena__ability-grid">
                    {availableAbilityOptions.length === 0 && (
                      <p className="arena__hint">Нет доступных способностей — можно только сходить обычным ударом или защитой.</p>
                    )}
                    {availableAbilityOptions.map((entry) => {
                      const affordable = isAffordable(entry, selectedIndices.length);
                      const isSelected = selectedAbility === entry.type;
                      return (
                        <button
                          key={entry.type}
                          type="button"
                          className={["arena__ability-tile", isSelected ? "arena__ability-tile--selected" : ""].filter(Boolean).join(" ")}
                          disabled={!affordable}
                          title={entry.label}
                          onClick={() => {
                            // Re-clicking the already-selected ability (e.g. to
                            // "confirm" the pick before moving on to targets —
                            // a natural instinct, since this tile is the only
                            // visible "choose the ability" affordance) used to
                            // silently wipe any targets already chosen, since
                            // this ran unconditionally. Only actually switching
                            // ability should reset targets picked for a
                            // different one.
                            if (entry.type === selectedAbility) return;
                            setSelectedAbility(entry.type);
                            setFlipTargets([]);
                            setFlipOwnTargets([]);
                            setDoubleTargets([]);
                            setRerollTargets([]);
                            setRerollOwnTargets([]);
                          }}
                        >
                          {entry.iconName ? (
                            <img className="arena__ability-icon" src={getIconUrl("abilities", entry.iconName)} alt={entry.label} />
                          ) : (
                            <span className="arena__ability-icon arena__ability-icon--placeholder" aria-hidden="true">
                              ?
                            </span>
                          )}
                          <span className="arena__ability-tile-label">{entry.label}</span>
                        </button>
                      );
                    })}
                  </div>

                  <div className="arena__ability-confirm">
                    <button className="arena__action arena__action--secondary" disabled={busy} onClick={() => setPendingAbilityChoice(false)}>
                      Назад
                    </button>
                    <button className="arena__action" disabled={busy || availableAbilityOptions.length === 0} onClick={handleSendActionMove}>
                      Подтвердить способность
                    </button>
                  </div>

                  <div className="arena__ability-details">
                    {selectedAbilityEntry ? (
                      <>
                        <h3 className="arena__ability-details-title">{selectedAbilityEntry.label}</h3>
                        <p className="arena__ability-details-cost">
                          {null === selectedAbilityEntry.actionCost
                            ? "Цена: все выпавшие очки действия"
                            : `Цена: ${selectedAbilityEntry.actionCost} очк. действия`}
                        </p>
                        {selectedAbilityEntry.description && (
                          <p className="arena__ability-details-desc">{selectedAbilityEntry.description}</p>
                        )}
                        {selectedAbility === "flip" && (
                          <p className="arena__hint">
                            Выбери до {selectedIndices.length} бит (свои или соперника), чтобы перевернуть их (
                            {flipTargets.length + flipOwnTargets.length}/{selectedIndices.length})
                          </p>
                        )}
                        {selectedAbility === "double" && (
                          <p className="arena__hint">
                            Выбери одну свою неиспользованную биту, чтобы удвоить её номинал ({doubleTargets.length}/1)
                          </p>
                        )}
                        {selectedAbility === "reroll" && (
                          <p className="arena__hint">
                            Выбери одну биту (свою или соперника), чтобы перебросить её на случайную сторону (
                            {rerollTargets.length + rerollOwnTargets.length}/1)
                          </p>
                        )}
                      </>
                    ) : (
                      <p className="arena__hint">Выбери способность</p>
                    )}
                  </div>
                </div>
              </div>
            )}

            {turn !== "wait" && !pendingAbilityChoice && (
              <div className="arena__move-actions">
                {(turn === "respond" || turn === "lead") && (
                  <button
                    className="arena__action arena__action--secondary"
                    disabled={busy || null !== autoPassSecondsLeft}
                    onClick={() => void sendMove([])}
                  >
                    {turn === "respond" ? "Не отвечать" : "Пропустить ход"}
                  </button>
                )}
                <button
                  className="arena__action"
                  disabled={busy || selectedIndices.length === 0 || null !== autoPassSecondsLeft}
                  onClick={handleConfirmSelection}
                >
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
