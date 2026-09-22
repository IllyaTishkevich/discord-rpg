import { SlashCommandBuilder, InviteTargetType, ChannelType } from "discord.js";

export default {
  data: new SlashCommandBuilder().setName("play").setDescription("Запустить discord-rpg Activity в твоём голосовом канале"),

  async execute(interaction) {
    const channel = interaction.member?.voice?.channel;
    if (!channel || channel.type !== ChannelType.GuildVoice) {
      await interaction.reply({ content: "Сначала зайди в голосовой канал.", ephemeral: true });
      return;
    }

    try {
      const invite = await channel.createInvite({
        targetType: InviteTargetType.EmbeddedApplication,
        targetApplication: process.env.DISCORD_CLIENT_ID,
        maxAge: 3600,
      });
      await interaction.reply(`Запускай Activity: ${invite.url}`);
    } catch (error) {
      console.error("Failed to create Activity invite:", error);
      await interaction.reply({
        content: "Не удалось запустить Activity. Проверь, что приложение включено как Activity в Discord Developer Portal.",
        ephemeral: true,
      });
    }
  },
};
