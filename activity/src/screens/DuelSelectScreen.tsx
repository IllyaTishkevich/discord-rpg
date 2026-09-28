import { useEffect, useState } from "react";
import { createDuelChallenge } from "../api/battles";
import { lookupCharactersByDiscordIds } from "../api/characters";
import type { DuelCandidate } from "../api/characters";
import { fetchVoiceChannelParticipants } from "../discord/sdk";
import type { VoiceChannelParticipant } from "../discord/sdk";
import type { BattleState } from "../types/battle";
import "./DuelSelectScreen.css";

interface Props {
  onChallengeSent: (battle: BattleState) => void;
  onBack: () => void;
}

interface Candidate extends DuelCandidate {
  avatarUrl: string | null;
}

/**
 * Voice-channel participants who actually have a character, picked from the
 * Discord SDK's own instance participant list (fetchVoiceChannelParticipants())
 * filtered through the backend (lookupCharactersByDiscordIds()) — only
 * someone with a character can be challenged at all.
 */
export function DuelSelectScreen({ onChallengeSent, onBack }: Props) {
  const [candidates, setCandidates] = useState<Candidate[] | null>(null);
  const [busyDiscordId, setBusyDiscordId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const participants = await fetchVoiceChannelParticipants();
        const discordIds = participants.map((p: VoiceChannelParticipant) => p.discordId);
        const withCharacters = discordIds.length > 0 ? await lookupCharactersByDiscordIds(discordIds) : [];
        if (cancelled) return;

        const byDiscordId = new Map(participants.map((p: VoiceChannelParticipant) => [p.discordId, p]));
        setCandidates(
          withCharacters.map((c) => ({
            ...c,
            avatarUrl: byDiscordId.get(c.discordId)?.avatarUrl ?? null,
          })),
        );
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : "Не удалось получить список игроков в комнате.");
        }
      }
    }

    void load();
    return () => {
      cancelled = true;
    };
  }, []);

  async function handleChallenge(discordId: string) {
    setBusyDiscordId(discordId);
    setError(null);
    try {
      const battle = await createDuelChallenge(discordId);
      onChallengeSent(battle);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось отправить вызов.");
      setBusyDiscordId(null);
    }
  }

  return (
    <div className="duel-select">
      <h1>Начать дуэль</h1>
      <p className="duel-select__hint">Выбери, кого вызвать — список игроков с персонажем в этой голосовой комнате.</p>

      {candidates === null && !error && <p>Загрузка...</p>}
      {error && <p className="duel-select__error">{error}</p>}
      {candidates && candidates.length === 0 && <p>В комнате больше никого с персонажем — позови друзей!</p>}

      {candidates && candidates.length > 0 && (
        <ul className="duel-select__list">
          {candidates.map((candidate) => (
            <li key={candidate.discordId} className="duel-select__candidate">
              {candidate.avatarUrl ? (
                <img className="duel-select__avatar" src={candidate.avatarUrl} alt="" />
              ) : (
                <div className="duel-select__avatar duel-select__avatar--placeholder" aria-hidden="true" />
              )}
              <div className="duel-select__info">
                <strong>{candidate.displayName}</strong>
                <span>
                  {candidate.className} · ур. {candidate.level}
                </span>
              </div>
              <button
                className="duel-select__challenge"
                disabled={busyDiscordId !== null}
                onClick={() => void handleChallenge(candidate.discordId)}
              >
                {busyDiscordId === candidate.discordId ? "Вызываем..." : "Вызвать"}
              </button>
            </li>
          ))}
        </ul>
      )}

      <button className="duel-select__back" onClick={onBack}>
        Назад
      </button>
    </div>
  );
}
