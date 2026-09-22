import { EmbedBuilder } from "discord.js";

/**
 * Handles the Accept/Decline buttons from /duel. PvP battle resolution isn't
 * built yet (only PvE exists so far) — this covers the invite/confirmation
 * flow the roadmap calls for, ready to hook up real matchmaking once a PvP
 * backend exists.
 */
export async function handleDuelButton(interaction) {
  const [, action, challengerId, opponentId] = interaction.customId.split(":");

  if (interaction.user.id !== opponentId) {
    await interaction.reply({ content: "Эта дуэль не для тебя.", ephemeral: true });
    return;
  }

  const accepted = action === "accept";
  const embed = new EmbedBuilder()
    .setTitle(accepted ? "Дуэль принята" : "Дуэль отклонена")
    .setDescription(
      accepted
        ? `<@${challengerId}> и <@${opponentId}> договорились о дуэли. PvP-бои появятся в одном из следующих спринтов — пока доступны PvE-бои через /pve или Activity.`
        : `<@${opponentId}> отклонил(а) вызов от <@${challengerId}>.`,
    )
    .setColor(accepted ? 0x23a55a : 0xf23f42);

  await interaction.update({ content: null, embeds: [embed], components: [] });
}
