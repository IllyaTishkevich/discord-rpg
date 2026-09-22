import "dotenv/config";
import { Client, Collection, GatewayIntentBits } from "discord.js";
import { readdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import path from "node:path";

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const client = new Client({
  intents: [GatewayIntentBits.Guilds],
});

client.commands = new Collection();

const commandsDir = path.join(__dirname, "commands");
for (const file of readdirSync(commandsDir).filter((f) => f.endsWith(".js"))) {
  const { default: command } = await import(path.join(commandsDir, file));
  client.commands.set(command.data.name, command);
}

const eventsDir = path.join(__dirname, "events");
for (const file of readdirSync(eventsDir).filter((f) => f.endsWith(".js"))) {
  const { default: event } = await import(path.join(eventsDir, file));
  if (event.once) {
    client.once(event.name, (...args) => event.execute(...args, client));
  } else {
    client.on(event.name, (...args) => event.execute(...args, client));
  }
}

client.login(process.env.DISCORD_BOT_TOKEN);
