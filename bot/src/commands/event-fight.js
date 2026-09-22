import { SlashCommandBuilder, EmbedBuilder } from "discord.js";

const STATUS_LABEL = { won: "Победа!", lost: "Поражение", in_progress: "Бой затянулся (ничья)" };
const STATUS_COLOR = { won: 0x23a55a, lost: 0xf23f42, in_progress: 0xf0a020 };

export default {
  data: new SlashCommandBuilder().setName("event-fight").setDescription("Сразиться с монстром текущего события (текстовый бой, бесплатно)"),

  async execute(interaction) {
    await interaction.deferReply();

    const backendUrl = process.env.BACKEND_API_URL;
    const response = await fetch(`${backendUrl}/bot/events/active/battle/auto/${interaction.user.id}`, {
      method: "POST",
      headers: { "X-Bot-Secret": process.env.BOT_API_SECRET },
    });

    if (response.status === 404) {
      const body = await response.json().catch(() => ({}));
      await interaction.editReply(
        body.error === "No active event right now."
          ? "Сейчас нет активного события."
          : "У тебя ещё нет персонажа — открой Activity (/play), чтобы создать его.",
      );
      return;
    }
    if (!response.ok) {
      await interaction.editReply("Не удалось провести бой. Попробуй позже.");
      return;
    }

    const result = await response.json();

    const embed = new EmbedBuilder()
      .setTitle(`${STATUS_LABEL[result.status] ?? result.status} — ${result.opponent.name}`)
      .addFields(
        { name: "Раундов сыграно", value: String(result.roundsPlayed), inline: true },
        { name: "Твоё HP", value: `${result.character.hp}/${result.character.maxHp}`, inline: true },
        { name: "HP монстра", value: `${result.opponent.hp}/${result.opponent.maxHp}`, inline: true },
      )
      .setColor(STATUS_COLOR[result.status] ?? 0x5865f2);

    if (result.rewards) {
      embed.addFields({ name: "Награда", value: `+${result.rewards.xp} XP, +${result.rewards.coins} монет` });
    }

    await interaction.editReply({ embeds: [embed] });
  },
};
