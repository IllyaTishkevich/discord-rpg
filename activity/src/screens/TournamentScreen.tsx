import { useEffect, useState } from "react";
import { fetchLatestTournament, fetchOpenTournament, registerForTournament } from "../api/tournaments";
import type { Tournament } from "../types/tournament";
import "./TournamentScreen.css";

interface Props {
  onBack: () => void;
}

function groupByRound(tournament: Tournament) {
  const rounds = new Map<number, Tournament["matches"]>();
  for (const match of tournament.matches) {
    const list = rounds.get(match.round) ?? [];
    list.push(match);
    rounds.set(match.round, list);
  }
  return [...rounds.entries()].sort(([a], [b]) => a - b);
}

export function TournamentScreen({ onBack }: Props) {
  const [tournament, setTournament] = useState<Tournament | null | undefined>(undefined);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchOpenTournament()
      .then((open) => (open ? open : fetchLatestTournament()))
      .then(setTournament)
      .catch((err) => setError(err instanceof Error ? err.message : "Не удалось загрузить турнир."));
  }, []);

  async function handleJoin() {
    if (!tournament) return;
    setBusy(true);
    setError(null);
    try {
      setTournament(await registerForTournament(tournament.id));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось зарегистрироваться.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="tournament">
      <h1>Турнир</h1>

      {tournament === undefined && <p>Загрузка...</p>}
      {error && <p className="tournament__error">{error}</p>}

      {tournament === null && <p>Сейчас нет открытой регистрации на турнир.</p>}

      {tournament && tournament.status === "registration" && (
        <div>
          <p>Зарегистрировано игроков: {tournament.entryCount}</p>
          <button className="tournament__join" disabled={busy} onClick={handleJoin}>
            Участвовать
          </button>
        </div>
      )}

      {tournament && tournament.status === "finished" && (
        <div>
          <p className="tournament__champion">🏆 Чемпион: {tournament.champion}</p>
          {groupByRound(tournament).map(([round, matches]) => (
            <div key={round} className="tournament__round">
              <h2>Раунд {round}</h2>
              {matches.map((match) => (
                <div key={match.slot} className="tournament__match">
                  {match.isBye ? (
                    <span>{match.characterA ?? match.characterB} проходит без боя</span>
                  ) : (
                    <span>
                      {match.characterA} vs {match.characterB} → <strong>{match.winner}</strong>
                    </span>
                  )}
                </div>
              ))}
            </div>
          ))}
        </div>
      )}

      <button className="tournament__back" onClick={onBack}>
        Назад
      </button>
    </div>
  );
}
