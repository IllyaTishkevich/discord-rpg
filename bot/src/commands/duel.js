import { SlashCommandBuilder, ActionRowBuilder, ButtonBuilder, ButtonStyle, EmbedBuilder } from "discord.js";

export default {
  data: new SlashCommandBuilder()
    .setName("duel")
    .setDescription("Вызвать другого игрока на PvP-дуэль")
    .addUserOption((option) => option.setName("user").setDescription("Кого вызываешь").setRequired(true)),

  async execute(interaction) {
    const opponent = interaction.options.getUser("user");

    if (opponent.id === interaction.user.id) {
      await interaction.reply({ content: "Нельзя вызвать самого себя.", ephemeral: true });
      return;
    }
    if (opponent.bot) {
      await interaction.reply({ content: "Ботов на дуэль не вызывают — для этого есть /pve.", ephemeral: true });
      return;
    }

    const row = new ActionRowBuilder().addComponents(
      new ButtonBuilder().setCustomId(`duel:accept:${interaction.user.id}:${opponent.id}`).setLabel("Принять").setStyle(ButtonStyle.Success),
      new ButtonBuilder().setCustomId(`duel:decline:${interaction.user.id}:${opponent.id}`).setLabel("Отклонить").setStyle(ButtonStyle.Danger),
    );

    const embed = new EmbedBuilder()
      .setTitle("Вызов на дуэль")
      .setDescription(`${interaction.user} вызывает ${opponent} на PvP-дуэль!`)
      .setColor(0x5865f2);

    await interaction.reply({ content: `${opponent}`, embeds: [embed], components: [row] });
  },
};
