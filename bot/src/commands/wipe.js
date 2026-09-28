import { SlashCommandBuilder, ActionRowBuilder, ButtonBuilder, ButtonStyle, EmbedBuilder } from "discord.js";

export default {
  data: new SlashCommandBuilder().setName("wipe").setDescription("Удалить своего персонажа и начать заново"),

  async execute(interaction) {
    const backendUrl = process.env.BACKEND_API_URL;
    const response = await fetch(`${backendUrl}/bot/characters/${interaction.user.id}`, {
      headers: { "X-Bot-Secret": process.env.BOT_API_SECRET },
    });

    if (response.status === 404) {
      await interaction.reply({
        content: "У тебя ещё нет персонажа — открой Activity в голосовом канале, чтобы создать его.",
        ephemeral: true,
      });
      return;
    }
    if (!response.ok) {
      await interaction.reply({ content: "Не удалось проверить персонажа. Попробуй позже.", ephemeral: true });
      return;
    }

    const row = new ActionRowBuilder().addComponents(
      new ButtonBuilder().setCustomId(`wipe:confirm:${interaction.user.id}`).setLabel("Удалить").setStyle(ButtonStyle.Danger),
      new ButtonBuilder().setCustomId(`wipe:cancel:${interaction.user.id}`).setLabel("Отмена").setStyle(ButtonStyle.Secondary),
    );

    const embed = new EmbedBuilder()
      .setTitle("Удалить персонажа?")
      .setDescription(
        "Это удалит твоего персонажа безвозвратно: уровень, биты, снаряжение, историю боёв и прогресс турниров. Действие нельзя отменить.",
      )
      .setColor(0xf23f42);

    await interaction.reply({ embeds: [embed], components: [row], ephemeral: true });
  },
};
