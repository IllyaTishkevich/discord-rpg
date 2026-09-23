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
  variant: "hp" | "enemy";
}

export function CombatantBar({ name, level, className, hp, maxHp, avatarUrl, iconName, variant }: Props) {
  const iconSrc = avatarUrl ?? (iconName ? getIconUrl("monsters", iconName) : null);
  const description = className ? `${name} · ${className} · ур. ${level ?? "?"}` : null !== (level ?? null) ? `${name} (ур. ${level})` : name;

  return (
    <div className="combatant-bar">
      <div className="combatant-bar__icon">
        {iconSrc ? (
          <img className="combatant-bar__icon-image" src={iconSrc} alt={name} />
        ) : (
          <span className="combatant-bar__icon-placeholder" aria-hidden="true">
            ?
          </span>
        )}
      </div>
      <div className="combatant-bar__info">
        <p className="combatant-bar__description">{description}</p>
        <StatBar label="HP" value={hp} max={maxHp} variant={variant} />
      </div>
    </div>
  );
}
