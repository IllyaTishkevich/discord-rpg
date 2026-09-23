import { DiscordSDK } from "@discord/embedded-app-sdk";
import { setAuthToken } from "../api/client";

const clientId = import.meta.env.VITE_DISCORD_CLIENT_ID;

/**
 * Lazily constructed. The SDK's constructor reads Discord-injected query
 * params (frame_id, instance_id, ...) and throws immediately if they're
 * missing — which is exactly the case for the admin-only devToken preview
 * bypass (see App.tsx), where authenticateWithDiscord() is never called at
 * all. A top-level `new DiscordSDK(...)` here would still run (and crash,
 * with a blank screen and no React error boundary to catch it) the moment
 * anything imports this module, since ES module evaluation runs the whole
 * file regardless of which export is actually used.
 */
let discordSdk: DiscordSDK | null = null;

function getDiscordSdk(): DiscordSDK {
  discordSdk ??= new DiscordSDK(clientId);

  return discordSdk;
}

/**
 * Runs the Activity handshake: waits for the Discord client, requests an
 * authorization code for our app, exchanges it with our own backend (which
 * talks to Discord server-side and returns our session JWT), then completes
 * the SDK's own authenticate() step so other Discord SDK commands become
 * available later (e.g. duel invites in a future sprint).
 */
export async function authenticateWithDiscord(): Promise<void> {
  const sdk = getDiscordSdk();
  await sdk.ready();

  const { code } = await sdk.commands.authorize({
    client_id: clientId,
    response_type: "code",
    state: "",
    prompt: "none",
    scope: ["identify"],
  });

  const response = await fetch("/.proxy/api/auth/discord/callback", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ code }),
  });

  if (!response.ok) {
    throw new Error(`Discord auth exchange failed with status ${response.status}`);
  }

  const { token, discordAccessToken } = await response.json();

  setAuthToken(token);

  await sdk.commands.authenticate({ access_token: discordAccessToken });
}
