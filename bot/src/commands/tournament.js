import { SlashCommandBuilder, EmbedBuilder, PermissionFlagsBits } from "discord.js";

async function fetchJson(url, options) {
  const response = await fetch(url, options);
  return { ok: response.ok, status: response.status, body: await response.json().catch(() => ({})) };
}

function buildBracketEmbed(tournament) {
  const embed = new EmbedBuilder().setTitle("Турнир").setColor(0x5865f2);

  if (tournament.status === "registration") {
    embed.setDescription(`Регистрация открыта — участников: ${tournament.entryCount}. Используй /tournament join, чтобы присоединиться.`);
    return embed;
  }

  embed.setDescription(`🏆 Чемпион: ${tournament.champion ?? "?"}`);
  const rounds = new Map();
  for (const match of tournament.matches) {
    if (!rounds.has(match.round)) rounds.set(match.round, []);
    rounds.get(match.round).push(match);
  }
  for (const [round, matches] of [...rounds.entries()].sort((a, b) => a[0] - b[0])) {
    const lines = matches.map((m) => (m.isBye ? `${m.characterA ?? m.characterB} — проход` : `${m.characterA} vs ${m.characterB} → **${m.winner}**`));
    embed.addFields({ name: `Раунд ${round}`, value: lines.join("\n") });
  }
  return embed;
}

export default {
  data: new SlashCommandBuilder()
    .setName("tournament")
    .setDescription("Турнир")
    .addSubcommand((sub) => sub.setName("join").setDescription("Зарегистрироваться на открытый турнир"))
    .addSubcommand((sub) => sub.setName("bracket").setDescription("Показать сетку текущего/последнего турнира"))
    .addSubcommand((sub) => sub.setName("open").setDescription("[Admin] Открыть регистрацию на новый турнир"))
    .addSubcommand((sub) => sub.setName("start").setDescription("[Admin] Запустить турнир и сыграть сетку")),

  async execute(interaction) {
    const sub = interaction.options.getSubcommand();
    const backendUrl = process.env.BACKEND_API_URL;
    const botSecret = process.env.BOT_API_SECRET;

    // Discord doesn't support per-subcommand default permissions, so the
    // admin-only subcommands are gated here at runtime instead.
    if ((sub === "open" || sub === "start") && !interaction.memberPermissions?.has(PermissionFlagsBits.ManageGuild)) {
      await interaction.reply({ content: "Нужны права ManageGuild.", ephemeral: true });
      return;
    }

    if (sub === "open") {
      const { ok, body } = await fetchJson(`${backendUrl}/bot/tournaments/open`, {
        method: "POST",
        headers: { "X-Bot-Secret": botSecret },
      });
      if (!ok) {
        await interaction.reply({ content: "Не удалось открыть регистрацию.", ephemeral: true });
        return;
      }
      await interaction.reply(`Регистрация на турнир #${body.id} открыта! Используй /tournament join.`);
      return;
    }

    if (sub === "join") {
      const openResponse = await fetchJson(`${backendUrl}/tournaments/open`);
      if (!openResponse.ok || !openResponse.body) {
        await interaction.reply({ content: "Сейчас нет открытой регистрации на турнир.", ephemeral: true });
        return;
      }

      const { ok, status, body } = await fetchJson(`${backendUrl}/bot/tournaments/${openResponse.body.id}/register/${interaction.user.id}`, {
        method: "POST",
        headers: { "X-Bot-Secret": botSecret },
      });

      if (status === 404) {
        await interaction.reply({ content: "У тебя ещё нет персонажа — открой Activity (/play), чтобы создать его.", ephemeral: true });
        return;
      }
      if (status === 409) {
        await interaction.reply({ content: "Ты уже зарегистрирован(а) на этот турнир.", ephemeral: true });
        return;
      }
      if (!ok) {
        await interaction.reply({ content: "Не удалось зарегистрироваться.", ephemeral: true });
        return;
      }

      await interaction.reply(`Готово! Участников зарегистрировано: ${body.entryCount}.`);
      return;
    }

    if (sub === "start") {
      const openResponse = await fetchJson(`${backendUrl}/tournaments/open`);
      if (!openResponse.ok || !openResponse.body) {
        await interaction.reply({ content: "Сейчас нет открытого турнира.", ephemeral: true });
        return;
      }

      const { ok, status, body } = await fetchJson(`${backendUrl}/bot/tournaments/${openResponse.body.id}/start`, {
        method: "POST",
        headers: { "X-Bot-Secret": botSecret },
      });

      if (status === 409) {
        await interaction.reply({ content: "Недостаточно участников для старта (нужно минимум 2).", ephemeral: true });
        return;
      }
      if (!ok) {
        await interaction.reply({ content: "Не удалось запустить турнир.", ephemeral: true });
        return;
      }

      await interaction.reply(`Турнир завершён! Чемпион: **${body.championName}**. Используй /tournament bracket, чтобы увидеть сетку.`);
      return;
    }

    // bracket
    const openResponse = await fetchJson(`${backendUrl}/tournaments/open`);
    let tournament = openResponse.ok ? openResponse.body : null;
    if (!tournament) {
      const latestResponse = await fetchJson(`${backendUrl}/tournaments/latest`);
      tournament = latestResponse.ok ? latestResponse.body : null;
    }

    if (!tournament) {
      await interaction.reply({ content: "Турниров пока не было.", ephemeral: true });
      return;
    }

    await interaction.reply({ embeds: [buildBracketEmbed(tournament)] });
  },
};
