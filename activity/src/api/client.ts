// Admin-only design/debug bypass (see backend's ActivityPreviewController,
// reachable only via /admin): the token is pre-minted server-side for a
// chosen player and handed to us via this query param instead of going
// through the real Discord SDK auth flow. Never present outside that
// admin-embedded iframe — see App.tsx, which is the only place that reads it.
export const devToken = typeof window !== "undefined" ? new URLSearchParams(window.location.search).get("devToken") : null;

// Inside a real Discord Activity iframe, external requests must go through
// Discord's own proxy path (mapped to our backend origin in the Developer
// Portal's URL Mappings) — direct cross-origin requests are blocked by the
// iframe's CSP. Outside Discord (plain browser dev, or the admin preview
// iframe above), hit the backend directly.
export const isEmbeddedInDiscord = !devToken && typeof window !== "undefined" && window.self !== window.top;
const BASE_URL = isEmbeddedInDiscord ? "/.proxy/api" : (import.meta.env.VITE_BACKEND_API_URL ?? "http://localhost:8000/api");

// Same proxying rule as BASE_URL, but for static files served straight off
// the backend (e.g. /uploads/items/...) rather than the /api namespace —
// Discord's URL Mapping is a root-prefix mapping (docs/PRODUCTION_SETUP.md
// §A.2: "/" → backend domain), so /.proxy/uploads/... is proxied exactly
// like /.proxy/api/... already is. Derived from the same env var as
// BASE_URL (stripping the trailing /api) rather than a new one, so
// deployments don't need an extra variable just for this.
export const IMAGE_BASE_URL = isEmbeddedInDiscord
  ? "/.proxy"
  : (import.meta.env.VITE_BACKEND_API_URL ?? "http://localhost:8000/api").replace(/\/api\/?$/, "");

const AUTH_TOKEN_STORAGE_KEY = "discord-rpg.auth-token";

export function getAuthToken(): string | null {
  return localStorage.getItem(AUTH_TOKEN_STORAGE_KEY);
}

export function setAuthToken(token: string): void {
  localStorage.setItem(AUTH_TOKEN_STORAGE_KEY, token);
}

export class ApiError extends Error {
  readonly status: number;

  constructor(status: number, message: string) {
    super(message);
    this.status = status;
  }
}

export async function apiFetch<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = getAuthToken();

  const response = await fetch(`${BASE_URL}${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });

  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    throw new ApiError(response.status, body.error ?? `Request to ${path} failed with status ${response.status}`);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return response.json() as Promise<T>;
}
