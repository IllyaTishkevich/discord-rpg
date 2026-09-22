import { EmbedBuilder } from "discord.js";

/**
 * Handles the Accept/Decline buttons from /duel — calls the real PvP
 * backend (BotPvpController) so the Battle row (the invite itself, status
 * `waiting`) actually transitions, then tells both players to open the
 * Activity to ready up and fight.
 */
export async function handleDuelButton(interaction) {
  const [, action, battleId, challengerId, opponentId] = interaction.customId.split(":");

  if (interaction.user.id !== opponentId) {
    await interaction.reply({ content: "Эта дуэль не для тебя.", ephemeral: true });
    return;
  }

  const accepted = action === "accept";
  const backendUrl = process.env.BACKEND_API_URL;
  const response = await fetch(`${backendUrl}/bot/battles/${battleId}/${accepted ? "accept" : "decline"}`, {
    method: "POST",
    headers: { "X-Bot-Secret": process.env.BOT_API_SECRET },
  });

  if (!response.ok) {
    await interaction.reply({ content: "Не удалось обработать дуэль. Попробуй позже.", ephemeral: true });
    return;
  }

  const embed = new EmbedBuilder()
    .setTitle(accepted ? "Дуэль принята" : "Дуэль отклонена")
    .setDescription(
      accepted
        ? `<@${challengerId}> и <@${opponentId}> — открывайте Activity (/play) и жмите "Готов", чтобы начать бой.`
        : `<@${opponentId}> отклонил(а) вызов от <@${challengerId}>.`,
    )
    .setColor(accepted ? 0x23a55a : 0xf23f42);

  await interaction.update({ content: null, embeds: [embed], components: [] });
}
