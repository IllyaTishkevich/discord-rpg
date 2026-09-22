import { useEffect, useState } from "react";
import { createCharacter, fetchCharacterClasses } from "../api/characters";
import type { Character, CharacterClassSummary } from "../types/character";
import "./ClassSelectScreen.css";

interface Props {
  onCharacterCreated: (character: Character) => void;
}

export function ClassSelectScreen({ onCharacterCreated }: Props) {
  const [classes, setClasses] = useState<CharacterClassSummary[] | null>(null);
  const [selectedCode, setSelectedCode] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchCharacterClasses()
      .then(setClasses)
      .catch((err) => setError(err.message));
  }, []);

  async function handleConfirm() {
    if (!selectedCode) return;
    setSubmitting(true);
    setError(null);
    try {
      const character = await createCharacter(selectedCode);
      onCharacterCreated(character);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Не удалось создать персонажа.");
    } finally {
      setSubmitting(false);
    }
  }

  if (error && !classes) {
    return <p className="class-select__error">{error}</p>;
  }

  if (!classes) {
    return <p>Загрузка классов...</p>;
  }

  return (
    <div className="class-select">
      <h1>Выберите класс</h1>
      <div className="class-select__grid">
        {classes.map((characterClass) => (
          <button
            key={characterClass.code}
            className={`class-select__card${selectedCode === characterClass.code ? " class-select__card--active" : ""}`}
            onClick={() => setSelectedCode(characterClass.code)}
          >
            <h2>{characterClass.name}</h2>
            <p>{characterClass.description ?? ""}</p>
            <dl>
              <dt>HP</dt>
              <dd>{characterClass.baseHp}</dd>
              <dt>Энергия</dt>
              <dd>{characterClass.baseEnergy}</dd>
              <dt>Биты</dt>
              <dd>{characterClass.starterBits.length}</dd>
            </dl>
          </button>
        ))}
      </div>
      {error && <p className="class-select__error">{error}</p>}
      <button className="class-select__confirm" disabled={!selectedCode || submitting} onClick={handleConfirm}>
        {submitting ? "Создаём..." : "Подтвердить выбор"}
      </button>
    </div>
  );
}
