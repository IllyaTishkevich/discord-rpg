import { getIconUrl } from "../api/client";
import { StatBar } from "./StatBar";
import "./CombatantBar.css";

interface Props {
  name: string;
  // Present together (a PvP player opponent, or the local player) → shown as
  // "{name} · {className} · ур. {level}". className absent (a PvE/event
  // Monster opponent, which has no class) → shown as "{name} (ур. {level})",
  // or just the bare name if level is also absent (pre-catalog fallback).
  level?: number | null;
  className?: string | null;
  hp: number;
  maxHp: number;
  // Mutually exclusive: avatarUrl for a real Discord user (PvP opponent or
  // the local player), iconName for a catalog Monster (PvE/event) — see
  // BattleSerializer's opponent shape. Both absent → placeholder.
  avatarUrl?: string | null;
  iconName?: string | null;
  // Decorative overlay from the character's class (App\Entity\CharacterClass::$frameFile)
  // — only ever set together with avatarUrl (a Monster has no class), null means no frame.
  frameName?: string | null;
  variant: "hp" | "enemy";
}

export function CombatantBar({ name, level, className, hp, maxHp, avatarUrl, iconName, frameName, variant }: Props) {
  const iconSrc = avatarUrl ?? (iconName ? getIconUrl("monsters", iconName) : null);
  const description = className ? `${name} · ${className} · ур. ${level ?? "?"}` : null !== (level ?? null) ? `${name} (ур. ${level})` : name;

  return (
    <div className="combatant-bar">
      <div className="combatant-bar__icon">
        <div className="combatant-bar__icon-avatar">
          {iconSrc ? (
            <img className="combatant-bar__icon-image" src={iconSrc} alt={name} />
          ) : (
            <span className="combatant-bar__icon-placeholder" aria-hidden="true">
              ?
            </span>
          )}
        </div>
        {/* Deliberately not clipped by the avatar's own circular overflow:hidden
            above — a frame is typically drawn a bit larger than the avatar it
            decorates (see .combatant-bar__icon-frame), same as Discord's own
            profile decorations. */}
        {frameName && <img className="combatant-bar__icon-frame" src={getIconUrl("frames", frameName)} alt="" aria-hidden="true" />}
      </div>
      <div className="combatant-bar__info">
        <p className="combatant-bar__description">{description}</p>
        <StatBar label="HP" value={hp} max={maxHp} variant={variant} />
      </div>
    </div>
  );
}
