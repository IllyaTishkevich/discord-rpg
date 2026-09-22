import { useEffect, useState } from "react";
import { fetchMyCharacter } from "./api/characters";
import { ApiError } from "./api/client";
import { ClassSelectScreen } from "./screens/ClassSelectScreen";
import { ProfileScreen } from "./screens/ProfileScreen";
import type { Character } from "./types/character";

type LoadState = { status: "loading" } | { status: "no-character" } | { status: "ready"; character: Character } | { status: "error"; message: string };

function App() {
  const [state, setState] = useState<LoadState>({ status: "loading" });

  useEffect(() => {
    fetchMyCharacter()
      .then((character) => setState({ status: "ready", character }))
      .catch((err) => {
        if (err instanceof ApiError && err.status === 404) {
          setState({ status: "no-character" });
        } else {
          setState({ status: "error", message: err instanceof Error ? err.message : "Unknown error" });
        }
      });
  }, []);

  if (state.status === "loading") {
    return <p>Загрузка...</p>;
  }

  if (state.status === "error") {
    return <p>{state.message}</p>;
  }

  if (state.status === "no-character") {
    return <ClassSelectScreen onCharacterCreated={(character) => setState({ status: "ready", character })} />;
  }

  return <ProfileScreen character={state.character} />;
}

export default App;
