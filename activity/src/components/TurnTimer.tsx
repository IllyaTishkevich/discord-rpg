import { useEffect, useRef, useState } from "react";
import "./TurnTimer.css";

interface Props {
  // ISO 8601, or null when nothing is currently pending (renders nothing).
  deadline: string | null;
  // Only used to compute the ring's fill percentage — doesn't need to match
  // the backend's ROUND_TIMEOUT_SECONDS exactly, just close enough to look
  // right; defaults to that setting's own default.
  totalSeconds?: number;
  // Fires exactly once per distinct `deadline` value, the moment the
  // countdown reaches 0.
  onExpire?: () => void;
}

export function TurnTimer({ deadline, totalSeconds = 15, onExpire }: Props) {
  const [secondsLeft, setSecondsLeft] = useState<number | null>(null);
  const expiredForDeadline = useRef<string | null>(null);

  useEffect(() => {
    if (!deadline) {
      setSecondsLeft(null);
      return;
    }

    const deadlineMs = new Date(deadline).getTime();

    function tick() {
      const remaining = Math.max(0, Math.ceil((deadlineMs - Date.now()) / 1000));
      setSecondsLeft(remaining);
      if (0 === remaining && expiredForDeadline.current !== deadline) {
        expiredForDeadline.current = deadline;
        onExpire?.();
      }
    }

    tick();
    const interval = setInterval(tick, 1000);
    return () => clearInterval(interval);
  }, [deadline, onExpire]);

  if (null === secondsLeft) {
    return null;
  }

  const percent = Math.max(0, Math.min(100, (secondsLeft / totalSeconds) * 100));
  const low = secondsLeft <= 5;

  return (
    <div
      className={`turn-timer${low ? " turn-timer--low" : ""}`}
      style={{ "--turn-timer-percent": `${percent}%` } as React.CSSProperties}
    >
      <span className="turn-timer__seconds">{secondsLeft}</span>
    </div>
  );
}
