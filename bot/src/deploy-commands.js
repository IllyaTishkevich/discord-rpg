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

const data = await rest.put(route, { body: commands });
console.log(`Registered ${data.length} application (/) commands.`);
