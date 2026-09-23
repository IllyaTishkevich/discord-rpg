import { useEffect, useState } from "react";
import { fetchMyActivePvp, startPveBattle } from "./api/battles";
import { fetchMyCharacter } from "./api/characters";
import { ApiError, devToken, isEmbeddedInDiscord, setAuthToken } from "./api/client";
import { fetchActiveEvent, startEventBattle } from "./api/events";
import { authenticateWithDiscord } from "./discord/sdk";
import { ArenaScreen } from "./screens/ArenaScreen";
import { BattleResultScreen } from "./screens/BattleResultScreen";
import { ClassSelectScreen } from "./screens/ClassSelectScreen";
import { DuelLobbyScreen } from "./screens/DuelLobbyScreen";
import { InventoryScreen } from "./screens/InventoryScreen";
import { ProfileScreen } from "./screens/ProfileScreen";
import { ShopScreen } from "./screens/ShopScreen";
import { TournamentScreen } from "./screens/TournamentScreen";
import { WeeklyQuestScreen } from "./screens/WeeklyQuestScreen";
import type { BattleState, RoundResult } from "./types/battle";
import type { Character } from "./types/character";
import type { ActiveEvent } from "./types/event";

type LoadState =
  | { status: "loading" }
  | { status: "not-embedded" }
  | { status: "no-character" }
  | { status: "profile"; character: Character }
  | { status: "shop"; character: Character }
  | { status: "inventory"; character: Character }
  | { status: "tournament"; character: Character }
  | { status: "quest"; character: Character }
  | { status: "duel-lobby"; character: Character; battle: BattleState }
  | { status: "arena"; character: Character; battle: BattleState }
  | { status: "battle-result"; character: Character; battle: BattleState; round: RoundResult | null }
  | { status: "error"; message: string };

// Discord SDK RPC rejections (discordSdk.commands.*) are typically plain
// {code, message} objects, not real Error instances — without this, they
// all collapsed into an unhelpful "Unknown error" on screen. The full
// object is still logged via console.error at the call site for debugging.
function describeError(err: unknown): string {
  if (err instanceof Error) return err.message;
  if (err && typeof err === "object" && "message" in err) {
    const code = "code" in err ? ` (code ${(err as { code: unknown }).code})` : "";
    return `${String((err as { message: unknown }).message)}${code}`;
  }
  try {
    return JSON.stringify(err);
  } catch {
    return String(err);
  }
}

function App() {
  const [state, setState] = useState<LoadState>({ status: "loading" });
  const [activeEvent, setActiveEvent] = useState<ActiveEvent | null>(null);

  useEffect(() => {
    // Admin-only design/debug bypass (see backend's ActivityPreviewController,
    // reachable only via /admin) — a pre-minted token for a chosen player,
    // never present outside that admin-embedded iframe. Skips the real
    // Discord SDK handshake entirely.
    if (devToken) {
      setAuthToken(devToken);
    } else if (!isEmbeddedInDiscord) {
      setState({ status: "not-embedded" });
      return;
    }

    (devToken ? Promise.resolve() : authenticateWithDiscord())
      .then(() => fetchMyCharacter())
      .then(async (character) => {
        fetchActiveEvent()
          .then(setActiveEvent)
          .catch(() => setActiveEvent(null));

        // A pending/active duel takes priority over the profile screen —
        // the player came here to fight, not to browse the shop.
        try {
          const pvpBattle = await fetchMyActivePvp();
          if (pvpBattle) {
            setState(
              pvpBattle.status === "in_progress"
                ? { status: "arena", character, battle: pvpBattle }
                : { status: "duel-lobby", character, battle: pvpBattle },
            );
            return;
          }
        } catch {
          // no active duel (or lookup failed) — fall through to the profile screen
        }

        setState({ status: "profile", character });
      })
      .catch((err) => {
        if (err instanceof ApiError && err.status === 404) {
          setState({ status: "no-character" });
        } else {
          console.error("Activity init failed:", err);
          setState({ status: "error", message: describeError(err) });
        }
      });
  }, []);

  async function handleStartBattle(character: Character) {
    try {
      const battle = await startPveBattle();
      setState({ status: "arena", character, battle });
    } catch (err) {
      setState({ status: "error", message: err instanceof Error ? err.message : "Не удалось начать бой." });
    }
  }

  async function handleJoinEvent(character: Character) {
    try {
      const battle = await startEventBattle();
      setState({ status: "arena", character, battle });
    } catch (err) {
      setState({ status: "error", message: err instanceof Error ? err.message : "Не удалось присоединиться к событию." });
    }
  }

  async function handleBattleFinished(character: Character, battle: BattleState, round: RoundResult | null) {
    try {
      const freshCharacter = await fetchMyCharacter();
      setState({ status: "battle-result", character: freshCharacter, battle, round });
    } catch {
      setState({ status: "battle-result", character, battle, round });
    }
  }

  if (state.status === "loading") {
    return <p>Загрузка...</p>;
  }

  if (state.status === "not-embedded") {
    return <p>Это приложение работает только как Discord Activity — запусти его из голосового канала.</p>;
  }

  if (state.status === "error") {
    return <p>{state.message}</p>;
  }

  if (state.status === "no-character") {
    return <ClassSelectScreen onCharacterCreated={(character) => setState({ status: "profile", character })} />;
  }

  if (state.status === "duel-lobby") {
    return (
      <DuelLobbyScreen
        battle={state.battle}
        onReady={(battle) => setState({ status: "arena", character: state.character, battle })}
        onDeclined={() => setState({ status: "profile", character: state.character })}
      />
    );
  }

  if (state.status === "arena") {
    return (
      <ArenaScreen
        initialBattle={state.battle}
        character={state.character}
        onFinished={(battle, round) => handleBattleFinished(state.character, battle, round)}
      />
    );
  }

  if (state.status === "battle-result") {
    return (
      <BattleResultScreen battle={state.battle} round={state.round} onContinue={() => setState({ status: "profile", character: state.character })} />
    );
  }

  if (state.status === "shop") {
    return <ShopScreen character={state.character} onBack={(character) => setState({ status: "profile", character })} />;
  }

  if (state.status === "inventory") {
    return <InventoryScreen character={state.character} onBack={(character) => setState({ status: "profile", character })} />;
  }

  if (state.status === "tournament") {
    return <TournamentScreen onBack={() => setState({ status: "profile", character: state.character })} />;
  }

  if (state.status === "quest") {
    return <WeeklyQuestScreen onBack={() => setState({ status: "profile", character: state.character })} />;
  }

  return (
    <ProfileScreen
      character={state.character}
      activeEvent={activeEvent}
      onStartBattle={() => handleStartBattle(state.character)}
      onJoinEvent={() => handleJoinEvent(state.character)}
      onOpenShop={() => setState({ status: "shop", character: state.character })}
      onOpenInventory={() => setState({ status: "inventory", character: state.character })}
      onOpenTournament={() => setState({ status: "tournament", character: state.character })}
      onOpenQuest={() => setState({ status: "quest", character: state.character })}
    />
  );
}

export default App;
