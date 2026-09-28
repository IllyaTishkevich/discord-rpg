import { getAuthToken, isEmbeddedInDiscord } from "./client";

// Where the browser reaches the bot process's realtime relay (see
// bot/src/realtime/server.js) — mirrors client.ts's BASE_URL logic exactly.
// Inside real Discord, the existing root "/" Developer Portal URL Mapping
// already proxies /.proxy/<anything> to that same path on our own domain
// (see client.ts's BASE_URL comment and docs/PRODUCTION_SETUP.md §A.2), and
// that generic proxying covers WebSocket upgrades too — no separate mapping
// needed. Outside Discord, nginx's own /ws proxy (docker/nginx/default.conf
// in dev, docs/PRODUCTION_SETUP.md §B.14 in prod) is reachable directly,
// same-origin as the API.
const WS_PATH = isEmbeddedInDiscord ? "/.proxy/ws" : (import.meta.env.VITE_WS_URL ?? "ws://localhost:8000/ws");

const RECONNECT_DELAYS_MS = [1000, 2000, 4000, 8000, 10000];

function resolveWsUrl(path: string): string {
  if (path.startsWith("ws://") || path.startsWith("wss://")) {
    return path;
  }
  // A relative "/.proxy/ws" path — WebSocket's constructor needs an
  // absolute URL, unlike fetch()/EventSource. Same scheme as the page
  // itself, upgraded to its ws(s) equivalent.
  const url = new URL(path, window.location.origin);
  url.protocol = "https:" === url.protocol ? "wss:" : "ws:";
  return url.toString();
}

/**
 * Subscribes to real-time push for one PvP battle
 * (BattleService::publishPvpUpdate(), relayed through bot/src/realtime/server.js)
 * — `onUpdate` fires the instant either side's move changes the battle,
 * instead of waiting for the next poll tick (see ArenaScreen.tsx, which
 * keeps a slow poll only as a fallback for a dropped connection). The
 * update itself carries no data — `onUpdate` is expected to re-fetch the
 * battle, the same way a poll tick already does.
 *
 * Authenticates by sending the same JWT already used for every other API
 * call (see client.ts's getAuthToken()) as the first message after
 * connecting — a plain WebSocket can't attach an Authorization header the
 * way fetch() does. Reconnects with a short backoff on an unexpected close
 * (unlike EventSource, a WebSocket never retries on its own), up to 10s
 * between attempts; the poll fallback covers whatever gap that leaves.
 *
 * Returns an unsubscribe function; always call it on cleanup (e.g. an
 * unmounting effect) — otherwise this keeps trying to reconnect forever.
 */
export function subscribeToBattle(battleId: number, onUpdate: () => void): () => void {
  let stopped = false;
  let socket: WebSocket | null = null;
  let attempt = 0;
  let reconnectTimer: ReturnType<typeof setTimeout> | null = null;

  function connect() {
    const token = getAuthToken();
    if (null === token) {
      // Nothing to authenticate the subscription with — the poll fallback
      // covers this until a token shows up (it shouldn't ever not, in
      // practice, by the time a battle screen is up).
      return;
    }

    socket = new WebSocket(resolveWsUrl(WS_PATH));

    socket.onopen = () => {
      attempt = 0;
      socket?.send(JSON.stringify({ type: "subscribe", battleId, token }));
    };

    socket.onmessage = () => onUpdate();

    socket.onerror = () => socket?.close();

    socket.onclose = () => {
      if (stopped) return;
      const delay = RECONNECT_DELAYS_MS[Math.min(attempt, RECONNECT_DELAYS_MS.length - 1)];
      attempt += 1;
      reconnectTimer = setTimeout(connect, delay);
    };
  }

  connect();

  return () => {
    stopped = true;
    if (null !== reconnectTimer) {
      clearTimeout(reconnectTimer);
    }
    socket?.close();
  };
}
