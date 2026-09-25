import { isEmbeddedInDiscord } from "./client";

// Where the browser reaches the Mercure hub's subscribe endpoint — mirrors
// client.ts's BASE_URL logic exactly. Inside real Discord, the existing
// root "/" Developer Portal URL Mapping already proxies /.proxy/<anything>
// to that same path on our own domain (see client.ts's BASE_URL comment
// and docs/PRODUCTION_SETUP.md §A.2) — no *separate* mapping needed, just
// the matching /.proxy-prefixed path. Outside Discord, nginx's own
// /.well-known/mercure proxy (docker/nginx/default.conf in dev,
// docs/PRODUCTION_SETUP.md §B.15 in prod) is reachable directly, same-origin
// as the API.
const MERCURE_URL = isEmbeddedInDiscord
  ? "/.proxy/.well-known/mercure"
  : (import.meta.env.VITE_MERCURE_URL ?? "http://localhost:8000/.well-known/mercure");

/**
 * Subscribes to real-time push for one PvP battle
 * (BattleService::publishPvpUpdate()) — `onUpdate` fires the instant either
 * side's move changes the battle, instead of waiting for the next poll tick
 * (see ArenaScreen.tsx, which keeps a slow poll only as a fallback for a
 * dropped connection). The update itself carries no data — `onUpdate` is
 * expected to re-fetch the battle, the same way a poll tick already does.
 *
 * Relies on the subscriber-authorization cookie
 * (BattleController::attachMercureSubscriberCookie()) already being set —
 * it's attached as a side effect of the battle endpoints this screen already
 * calls (join/throw/exchanges/move/show), so by the time this runs it's
 * normally already there; withCredentials is what makes the browser actually
 * send it, since EventSource — unlike fetch() — never sends cookies by default.
 *
 * Returns an unsubscribe function; always call it on cleanup (e.g. an
 * unmounting effect) — an EventSource left open keeps reconnecting forever.
 */
export function subscribeToBattle(battleId: number, onUpdate: () => void): () => void {
  const url = new URL(MERCURE_URL, window.location.origin);
  url.searchParams.append("topic", `battle/${battleId}`);

  const source = new EventSource(url, { withCredentials: true });
  source.onmessage = () => onUpdate();

  return () => source.close();
}
