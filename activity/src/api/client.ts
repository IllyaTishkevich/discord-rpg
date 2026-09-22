// Inside a real Discord Activity iframe, external requests must go through
// Discord's own proxy path (mapped to our backend origin in the Developer
// Portal's URL Mappings) — direct cross-origin requests are blocked by the
// iframe's CSP. Outside Discord (plain browser dev), hit the backend directly.
export const isEmbeddedInDiscord = typeof window !== "undefined" && window.self !== window.top;
const BASE_URL = isEmbeddedInDiscord ? "/.proxy/api" : (import.meta.env.VITE_BACKEND_API_URL ?? "http://localhost:8000/api");

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
