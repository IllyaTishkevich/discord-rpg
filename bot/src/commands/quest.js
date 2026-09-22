import { SlashCommandBuilder, EmbedBuilder } from "discord.js";

export default {
  data: new SlashCommandBuilder().setName("quest").setDescription("Показать прогресс недельного задания"),

  async execute(interaction) {
    const backendUrl = process.env.BACKEND_API_URL;
    const response = await fetch(`${backendUrl}/bot/quests/${interaction.user.id}`, {
      headers: { "X-Bot-Secret": process.env.BOT_API_SECRET },
    });

    if (response.status === 404) {
      await interaction.reply({ content: "У тебя ещё нет персонажа — открой Activity (/play), чтобы создать его.", ephemeral: true });
      return;
    }
    if (!response.ok) {
      await interaction.reply({ content: "Не удалось загрузить задание.", ephemeral: true });
      return;
    }

    const quest = await response.json();
    if (!quest) {
      await interaction.reply({ content: "На этой неделе задание ещё не сгенерировано.", ephemeral: true });
      return;
    }

    const embed = new EmbedBuilder()
      .setTitle("Недельное задание")
      .setDescription(`Победить в ${quest.targetValue} боях`)
      .addFields(
        { name: "Прогресс", value: `${quest.progress}/${quest.targetValue}`, inline: true },
        { name: "Награда", value: `+${quest.rewardXp} XP, +${quest.rewardCoins} монет`, inline: true },
        { name: "Статус", value: quest.isClaimed ? "Получено" : quest.isComplete ? "Готово к получению (открой Activity)" : "В процессе", inline: false },
      )
      .setColor(quest.isClaimed ? 0x23a55a : quest.isComplete ? 0xf0a020 : 0x5865f2);

    await interaction.reply({ embeds: [embed] });
  },
};
