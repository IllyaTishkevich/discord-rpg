import { useEffect, useState } from "react";
import type { BitFace } from "../types/character";
import "./BitCoin.css";

export const FACE_ICON: Record<BitFace, string> = {
  attack: "⚔️",
  defense: "🛡️",
  action: "✨",
};

export const FACE_LABEL: Record<BitFace, string> = {
  attack: "Удар",
  defense: "Защита",
  action: "Действие",
};

interface Props {
  face: BitFace | null;
  selectable?: boolean;
  selected?: boolean;
  // Already activated this round — still shows its real face (unlike an
  // unknown/not-yet-thrown bit, which passes face={null}), just dimmed and
  // no longer clickable.
  used?: boolean;
  onClick?: () => void;
}

export function BitCoin({ face, selectable, selected, used, onClick }: Props) {
  const [flipped, setFlipped] = useState(false);

  useEffect(() => {
    if (face === null) {
      setFlipped(false);
      return;
    }
    // Trigger the flip animation whenever a new face is revealed.
    setFlipped(false);
    const id = requestAnimationFrame(() => setFlipped(true));
    return () => cancelAnimationFrame(id);
  }, [face]);

  const classNames = [
    "bit-coin",
    flipped ? "bit-coin--flipped" : "",
    selectable ? "bit-coin--selectable" : "",
    selected ? "bit-coin--selected" : "",
    used ? "bit-coin--used" : "",
  ]
    .filter(Boolean)
    .join(" ");

  return (
    <button
      type="button"
      className={classNames}
      onClick={selectable ? onClick : undefined}
      disabled={!selectable}
      title={face ? FACE_LABEL[face] : "?"}
    >
      <span className="bit-coin__face">{face ? FACE_ICON[face] : "?"}</span>
    </button>
  );
}
