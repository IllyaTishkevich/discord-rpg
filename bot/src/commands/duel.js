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

    const backendUrl = process.env.BACKEND_API_URL;
    const response = await fetch(`${backendUrl}/bot/battles/pvp`, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Bot-Secret": process.env.BOT_API_SECRET },
      body: JSON.stringify({ challengerDiscordId: interaction.user.id, opponentDiscordId: opponent.id }),
    });

    if (response.status === 404) {
      await interaction.reply({
        content: "У тебя или у соперника ещё нет персонажа — сначала откройте Activity (/play), чтобы его создать.",
        ephemeral: true,
      });
      return;
    }
    if (!response.ok) {
      await interaction.reply({ content: "Не удалось создать вызов. Попробуй позже.", ephemeral: true });
      return;
    }

    const { id: battleId } = await response.json();

    const row = new ActionRowBuilder().addComponents(
      new ButtonBuilder()
        .setCustomId(`duel:accept:${battleId}:${interaction.user.id}:${opponent.id}`)
        .setLabel("Принять")
        .setStyle(ButtonStyle.Success),
      new ButtonBuilder()
        .setCustomId(`duel:decline:${battleId}:${interaction.user.id}:${opponent.id}`)
        .setLabel("Отклонить")
        .setStyle(ButtonStyle.Danger),
    );

    const embed = new EmbedBuilder()
      .setTitle("Вызов на дуэль")
      .setDescription(`${interaction.user} вызывает ${opponent} на PvP-дуэль!`)
      .setColor(0x5865f2);

    await interaction.reply({ content: `${opponent}`, embeds: [embed], components: [row] });
  },
};
