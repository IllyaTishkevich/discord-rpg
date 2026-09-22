import { SlashCommandBuilder, EmbedBuilder } from "discord.js";

const STATUS_LABEL = {
  won: "Победа!",
  lost: "Поражение",
  in_progress: "Бой затянулся (ничья)",
};

const STATUS_COLOR = {
  won: 0x23a55a,
  lost: 0xf23f42,
  in_progress: 0x5865f2,
};

export default {
  data: new SlashCommandBuilder().setName("pve").setDescription("Быстрый текстовый бой против тренировочного голема (тратит энергию)"),

  async execute(interaction) {
    await interaction.deferReply();

    const backendUrl = process.env.BACKEND_API_URL;
    const response = await fetch(`${backendUrl}/bot/battles/${interaction.user.id}/pve/auto`, {
      method: "POST",
      headers: { "X-Bot-Secret": process.env.BOT_API_SECRET },
    });

    if (response.status === 404) {
      await interaction.editReply("У тебя ещё нет персонажа — открой Activity в голосовом канале (/play), чтобы создать его.");
      return;
    }
    if (response.status === 409) {
      await interaction.editReply("Не хватает энергии для боя.");
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
        { name: "HP противника", value: `${result.opponent.hp}/${result.opponent.maxHp}`, inline: true },
      )
      .setColor(STATUS_COLOR[result.status] ?? 0x5865f2);

    if (result.rewards) {
      embed.addFields({ name: "Награда", value: `+${result.rewards.xp} XP, +${result.rewards.coins} монет` });
    }

    await interaction.editReply({ embeds: [embed] });
  },
};
