import { SlashCommandBuilder } from "discord.js";
import { buildDuelChallengeMessage } from "../interactions/duelChallengeMessage.js";

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

    // Only the two duelists ever see the challenge itself: the challenger
    // gets a private confirmation (no Accept/Decline — it's not their move
    // to make), the opponent gets the real thing by DM. Falls back to a
    // normal channel message only if the DM fails (DMs disabled, etc.) —
    // better a public post than a challenge nobody ever sees.
    await interaction.reply({ content: `Вызов отправлен ${opponent} в личные сообщения.`, ephemeral: true });

    const message = buildDuelChallengeMessage(battleId, interaction.user.id, opponent.id);
    try {
      await opponent.send(message);
    } catch {
      await interaction.followUp({ content: `${opponent} — не получилось отправить в личные сообщения, вызов ниже:`, ...message });
    }
  },
};
