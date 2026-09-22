import { SlashCommandBuilder, EmbedBuilder, PermissionFlagsBits } from "discord.js";

export default {
  data: new SlashCommandBuilder()
    .setName("event-start")
    .setDescription("[Admin] Запустить событие «Нападение монстров» на сервере")
    .setDefaultMemberPermissions(PermissionFlagsBits.ManageGuild),

  async execute(interaction) {
    const backendUrl = process.env.BACKEND_API_URL;
    const response = await fetch(`${backendUrl}/bot/events/monster-attack`, {
      method: "POST",
      headers: { "X-Bot-Secret": process.env.BOT_API_SECRET },
    });

    if (!response.ok) {
      await interaction.reply({ content: "Не удалось запустить событие. Попробуй позже.", ephemeral: true });
      return;
    }

    const event = await response.json();

    const embed = new EmbedBuilder()
      .setTitle("🐉 Нападение монстров!")
      .setDescription(`${event.monsterName} (${event.monsterHp} HP) атакует сервер! Используй /event-fight или открой Activity, чтобы сразиться — бесплатно, без траты энергии.`)
      .setColor(0xf0a020);

    await interaction.reply({ content: "@here", embeds: [embed] });
  },
};
