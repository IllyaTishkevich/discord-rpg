import { SlashCommandBuilder, EmbedBuilder } from "discord.js";

const CLASS_LABELS = {
  warrior: "Воин",
  mage: "Маг",
  rogue: "Плут",
};

export default {
  data: new SlashCommandBuilder().setName("profile").setDescription("Показать карточку своего персонажа"),

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
      await interaction.reply({ content: "Не удалось получить профиль. Попробуй позже.", ephemeral: true });
      return;
    }

    const character = await response.json();
    const className = CLASS_LABELS[character.class.code] ?? character.class.name;

    const embed = new EmbedBuilder()
      .setTitle(`${interaction.user.username} — ${className}`)
      .addFields(
        { name: "Уровень", value: String(character.level), inline: true },
        { name: "HP", value: `${character.hp}/${character.maxHp}`, inline: true },
        { name: "Энергия", value: `${character.energy}/${character.maxEnergy}`, inline: true },
        { name: "XP", value: String(character.xp), inline: true },
        { name: "Монеты", value: String(character.coins), inline: true },
      )
      .setColor(0x5865f2);

    await interaction.reply({ embeds: [embed] });
  },
};
