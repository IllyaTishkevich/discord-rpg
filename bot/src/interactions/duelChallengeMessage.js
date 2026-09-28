import { ActionRowBuilder, ButtonBuilder, ButtonStyle, EmbedBuilder } from "discord.js";

/**
 * The Accept/Decline message content for a PvP duel challenge — shared by
 * /duel (bot/src/commands/duel.js, DMs it to the challenged player so the
 * channel only ever sees the challenger's own private confirmation) and the
 * Activity-initiated flow (bot/src/realtime/server.js's /internal/duel-challenge,
 * for a challenge with no Discord interaction/channel of its own). Button
 * customIds are read by duelButtons.js — keep the format in sync with it.
 */
export function buildDuelChallengeMessage(battleId, challengerDiscordId, opponentDiscordId) {
  const row = new ActionRowBuilder().addComponents(
    new ButtonBuilder()
      .setCustomId(`duel:accept:${battleId}:${challengerDiscordId}:${opponentDiscordId}`)
      .setLabel("Принять")
      .setStyle(ButtonStyle.Success),
    new ButtonBuilder()
      .setCustomId(`duel:decline:${battleId}:${challengerDiscordId}:${opponentDiscordId}`)
      .setLabel("Отклонить")
      .setStyle(ButtonStyle.Danger),
  );
  const embed = new EmbedBuilder()
    .setTitle("Вызов на дуэль")
    .setDescription(`<@${challengerDiscordId}> вызывает тебя на PvP-дуэль!`)
    .setColor(0x5865f2);

  return { embeds: [embed], components: [row] };
}
