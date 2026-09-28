import { createServer } from "node:http";
import { readFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import jwt from "jsonwebtoken";
import { WebSocketServer } from "ws";
import { buildDuelChallengeMessage } from "../interactions/duelChallengeMessage.js";

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * Real-time PvP duel sync (see backend's BattleService::publishPvpUpdate()),
 * relayed through this always-running bot process instead of a separate hub
 * — a plain PHP-FPM request/response can't hold a persistent connection the
 * way this Node process already does for the Discord gateway. Also backs
 * duel challenges created directly from the Activity (see
 * handleDuelChallenge() below), since posting the Accept/Decline DM needs
 * the same always-logged-in `client`.
 *
 * POST /internal/publish (backend -> here, X-Bot-Secret authenticated):
 * broadcasts {battleId} to every socket currently subscribed to that battle.
 *
 * POST /internal/duel-challenge (backend -> here, X-Bot-Secret authenticated):
 * DMs the challenged player an Accept/Decline embed — see
 * BattleService::notifyDuelChallenge()'s docblock for why this is a DM
 * rather than a channel reply (there's no Discord interaction/channel
 * context for a challenge created from inside the Activity).
 *
 * WebSocket /ws (Activity -> here): the client's first message must be
 * {type:"subscribe", battleId, token}, where token is the same JWT the
 * Activity already holds for its own API calls. Verified locally against
 * the backend's own JWT public key (RS256) — no shared secret to keep in
 * sync, no round-trip just to check a signature — then cross-checked
 * against BotPvpController::participants() to confirm this specific user
 * is actually one of this specific battle's two sides, since this process
 * has no direct database access of its own.
 */

const JWT_PUBLIC_KEY = readFileSync(
  process.env.JWT_PUBLIC_KEY_PATH ?? path.join(__dirname, "..", "..", "..", "backend", "config", "jwt", "public.pem"),
  "utf8",
);

// battleId (number) -> Set<WebSocket>
const subscribersByBattle = new Map();

function unsubscribe(battleId, socket) {
  const sockets = subscribersByBattle.get(battleId);
  if (!sockets) return;
  sockets.delete(socket);
  if (sockets.size === 0) {
    subscribersByBattle.delete(battleId);
  }
}

async function isParticipant(battleId, discordId) {
  try {
    const backendUrl = process.env.BACKEND_API_URL;
    const response = await fetch(`${backendUrl}/bot/battles/${battleId}/participants`, {
      headers: { "X-Bot-Secret": process.env.BOT_API_SECRET },
    });
    if (!response.ok) {
      return false;
    }
    const { characterDiscordId, opponentDiscordId } = await response.json();
    return discordId === characterDiscordId || discordId === opponentDiscordId;
  } catch {
    // Backend unreachable or battle not found — fail closed.
    return false;
  }
}

function readBody(req) {
  return new Promise((resolve) => {
    let body = "";
    req.on("data", (chunk) => {
      body += chunk;
    });
    req.on("end", () => resolve(body));
  });
}

function checkBotSecret(req, res) {
  if (req.headers["x-bot-secret"] !== process.env.BOT_API_SECRET) {
    res.writeHead(403).end();
    return false;
  }
  return true;
}

async function handlePublish(req, res) {
  if (!checkBotSecret(req, res)) return;

  try {
    const { battleId } = JSON.parse(await readBody(req));
    const sockets = subscribersByBattle.get(battleId);
    if (sockets) {
      const payload = JSON.stringify({ battleId });
      for (const socket of sockets) {
        socket.send(payload);
      }
    }
  } catch {
    // Malformed publish body — nothing to broadcast, still ack below.
  }
  res.writeHead(204).end();
}

async function handleDuelChallenge(req, res, client) {
  if (!checkBotSecret(req, res)) return;

  try {
    const { battleId, challengerDiscordId, opponentDiscordId } = JSON.parse(await readBody(req));

    const recipient = await client.users.fetch(opponentDiscordId);
    await recipient.send(buildDuelChallengeMessage(battleId, challengerDiscordId, opponentDiscordId));
  } catch {
    // Best-effort — see BattleService::notifyDuelChallenge()'s docblock:
    // DMs can be disabled, the user may be unfetchable, etc. The challenge
    // still shows up next time they open the Activity either way.
  }
  res.writeHead(204).end();
}

async function handleSubscribe(socket, message) {
  if (typeof message.battleId !== "number" || typeof message.token !== "string") {
    return;
  }

  let discordId;
  try {
    discordId = jwt.verify(message.token, JWT_PUBLIC_KEY, { algorithms: ["RS256"] }).username;
  } catch {
    socket.close();
    return;
  }

  if (!(await isParticipant(message.battleId, discordId))) {
    socket.close();
    return;
  }

  if (socket.subscribedBattleId !== undefined) {
    unsubscribe(socket.subscribedBattleId, socket);
  }
  socket.subscribedBattleId = message.battleId;
  if (!subscribersByBattle.has(message.battleId)) {
    subscribersByBattle.set(message.battleId, new Set());
  }
  subscribersByBattle.get(message.battleId).add(socket);
}

export function startRealtimeServer(port, client) {
  const httpServer = createServer((req, res) => {
    if (req.method === "POST" && req.url === "/internal/publish") {
      void handlePublish(req, res);
      return;
    }
    if (req.method === "POST" && req.url === "/internal/duel-challenge") {
      void handleDuelChallenge(req, res, client);
      return;
    }
    res.writeHead(404).end();
  });

  const wss = new WebSocketServer({ server: httpServer, path: "/ws" });

  wss.on("connection", (socket) => {
    socket.on("message", (raw) => {
      let message;
      try {
        message = JSON.parse(raw.toString());
      } catch {
        return;
      }
      if (message.type === "subscribe") {
        void handleSubscribe(socket, message);
      }
    });

    socket.on("close", () => {
      if (socket.subscribedBattleId !== undefined) {
        unsubscribe(socket.subscribedBattleId, socket);
      }
    });
  });

  httpServer.listen(port, () => {
    console.log(`Realtime relay listening on port ${port} (POST /internal/publish, WS /ws)`);
  });

  return httpServer;
}
