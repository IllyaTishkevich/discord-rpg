import { SlashCommandBuilder, EmbedBuilder } from "discord.js";

export default {
  data: new SlashCommandBuilder().setName("leaderboard").setDescription("Показать топ игроков по опыту"),

  async execute(interaction) {
    const backendUrl = process.env.BACKEND_API_URL;
    const response = await fetch(`${backendUrl}/characters/leaderboard`);

    if (!response.ok) {
      await interaction.reply({ content: "Не удалось загрузить таблицу лидеров.", ephemeral: true });
      return;
    }

    const top = await response.json();
    if (top.length === 0) {
      await interaction.reply("Пока никто не создал персонажа.");
      return;
    }

    const lines = top.map((entry, index) => `${index + 1}. **${entry.displayName}** — ${entry.className}, уровень ${entry.level}, ${entry.xp} XP`);

    const embed = new EmbedBuilder().setTitle("Таблица лидеров").setDescription(lines.join("\n")).setColor(0x5865f2);

    await interaction.reply({ embeds: [embed] });
  },
};
