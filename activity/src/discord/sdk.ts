import { DiscordSDK } from "@discord/embedded-app-sdk";
import { setAuthToken } from "../api/client";

const clientId = import.meta.env.VITE_DISCORD_CLIENT_ID;

export const discordSdk = new DiscordSDK(clientId);

/**
 * Runs the Activity handshake: waits for the Discord client, requests an
 * authorization code for our app, exchanges it with our own backend (which
 * talks to Discord server-side and returns our session JWT), then completes
 * the SDK's own authenticate() step so other Discord SDK commands become
 * available later (e.g. duel invites in a future sprint).
 */
export async function authenticateWithDiscord(): Promise<void> {
  await discordSdk.ready();

  const { code } = await discordSdk.commands.authorize({
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

  await discordSdk.commands.authenticate({ access_token: discordAccessToken });
}
