import "./StatBar.css";

interface Props {
  label: string;
  value: number;
  max: number;
  variant?: "hp" | "energy" | "enemy";
}

export function StatBar({ label, value, max, variant = "hp" }: Props) {
  const percent = max > 0 ? Math.round((value / max) * 100) : 0;
  return (
    <div className="stat-bar">
      <div className="stat-bar__label">
        <span>{label}</span>
        <span>
          {value}/{max}
        </span>
      </div>
      <div className="stat-bar__track">
        <div className={`stat-bar__fill stat-bar__fill--${variant}`} style={{ width: `${percent}%` }} />
      </div>
    </div>
  );
}
