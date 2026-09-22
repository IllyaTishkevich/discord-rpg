import { useEffect, useState } from "react";
import { startPveBattle } from "./api/battles";
import { fetchMyCharacter } from "./api/characters";
import { ApiError, isEmbeddedInDiscord } from "./api/client";
import { authenticateWithDiscord } from "./discord/sdk";
import { ArenaScreen } from "./screens/ArenaScreen";
import { BattleResultScreen } from "./screens/BattleResultScreen";
import { ClassSelectScreen } from "./screens/ClassSelectScreen";
import { ProfileScreen } from "./screens/ProfileScreen";
import type { BattleState } from "./types/battle";
import type { Character } from "./types/character";

type LoadState =
  | { status: "loading" }
  | { status: "not-embedded" }
  | { status: "no-character" }
  | { status: "profile"; character: Character }
  | { status: "arena"; character: Character; battle: BattleState }
  | { status: "battle-result"; character: Character; battle: BattleState }
  | { status: "error"; message: string };

function App() {
  const [state, setState] = useState<LoadState>({ status: "loading" });

  useEffect(() => {
    if (!isEmbeddedInDiscord) {
      setState({ status: "not-embedded" });
      return;
    }

    authenticateWithDiscord()
      .then(() => fetchMyCharacter())
      .then((character) => setState({ status: "profile", character }))
      .catch((err) => {
        if (err instanceof ApiError && err.status === 404) {
          setState({ status: "no-character" });
        } else {
          setState({ status: "error", message: err instanceof Error ? err.message : "Unknown error" });
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

  async function handleBattleFinished(character: Character, battle: BattleState) {
    try {
      const freshCharacter = await fetchMyCharacter();
      setState({ status: "battle-result", character: freshCharacter, battle });
    } catch {
      setState({ status: "battle-result", character, battle });
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

  if (state.status === "arena") {
    return <ArenaScreen initialBattle={state.battle} onFinished={(battle) => handleBattleFinished(state.character, battle)} />;
  }

  if (state.status === "battle-result") {
    return <BattleResultScreen battle={state.battle} onContinue={() => setState({ status: "profile", character: state.character })} />;
  }

  return <ProfileScreen character={state.character} onStartBattle={() => handleStartBattle(state.character)} />;
}

export default App;
