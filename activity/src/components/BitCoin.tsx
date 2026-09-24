import { useEffect, useState } from "react";
import type { BitFace } from "../types/character";
import "./BitCoin.css";

export const FACE_ICON: Record<BitFace, string> = {
  attack: "⚔️",
  defense: "🛡️",
  action: "✨",
  empty: "➖",
};

export const FACE_LABEL: Record<BitFace, string> = {
  attack: "Удар",
  defense: "Защита",
  action: "Действие",
  empty: "Пусто",
};

interface Props {
  face: BitFace | null;
  selectable?: boolean;
  selected?: boolean;
  // Already activated this round — still shows its real face (unlike an
  // unknown/not-yet-thrown bit, which passes face={null}), just dimmed and
  // no longer clickable.
  used?: boolean;
  // Damage/blocking/action-points this face is worth — omitted or 1 for an
  // ordinary bit, in which case no badge is shown at all.
  multiplier?: number;
  // The owning Bit's own art for this face (App\Entity\Bit::$iconAName/
  // $iconBName, via getIconUrl("bits", ...)) — omitted or null falls back
  // to the generic FACE_ICON emoji, same as always.
  iconUrl?: string | null;
  onClick?: () => void;
}

export function BitCoin({ face, selectable, selected, used, multiplier, iconUrl, onClick }: Props) {
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
    // Distinct from --used: never played at all this round, not just spent.
    face === "empty" ? "bit-coin--empty" : "",
  ]
    .filter(Boolean)
    .join(" ");

  const hasMultiplier = face !== null && (multiplier ?? 1) > 1;

  return (
  <>
    <button
      type="button"
      className={classNames}
      onClick={selectable ? onClick : undefined}
      disabled={!selectable}
      title={face ? (hasMultiplier ? `${FACE_LABEL[face]} ×${multiplier}` : FACE_LABEL[face]) : "?"}
    >
      <span className="bit-coin__sheen" aria-hidden="true" />
      <span className="bit-coin__face">
        {face && iconUrl ? <img className="bit-coin__image" src={iconUrl} alt="" /> : face ? FACE_ICON[face] : "?"}
      </span>
    </button>
    {hasMultiplier && <span className="bit-coin__multiplier">×{multiplier}</span>}
    </>
  );
}
