import "dotenv/config";
import { REST, Routes } from "discord.js";
import { readdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import path from "node:path";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const commandsDir = path.join(__dirname, "commands");

const commands = [];
for (const file of readdirSync(commandsDir).filter((f) => f.endsWith(".js"))) {
  const { default: command } = await import(path.join(commandsDir, file));
  commands.push(command.data.toJSON());
}

const rest = new REST().setToken(process.env.DISCORD_BOT_TOKEN);

const route = process.env.DISCORD_GUILD_ID
  ? Routes.applicationGuildCommands(process.env.DISCORD_CLIENT_ID, process.env.DISCORD_GUILD_ID)
  : Routes.applicationCommands(process.env.DISCORD_CLIENT_ID);

// Discord auto-creates a type=4 "Entry Point" command for apps with an
// Activity enabled (the default "Launch Activity" button). A bulk PUT that
// omits it is rejected outright (code 50240) since that would implicitly
// delete it — so we fetch whatever's currently registered and carry any
// Entry Point command over untouched alongside our own slash commands.
const existing = await rest.get(route);
const entryPointCommands = existing.filter((command) => command.type === 4);

const data = await rest.put(route, { body: [...entryPointCommands, ...commands] });
console.log(`Registered ${data.length} application (/) commands.`);
