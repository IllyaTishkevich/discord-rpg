import { EmbedBuilder } from "discord.js";

/**
 * Handles the Confirm/Cancel buttons from /wipe. The confirming user is
 * baked into the customId (set from the command's own interaction.user.id),
 * and re-checked here — the reply is ephemeral so in practice only that
 * user can ever see or press these buttons, but this stays correct even if
 * that ever changes.
 */
export async function handleWipeButton(interaction) {
  const [, action, userId] = interaction.customId.split(":");

  if (interaction.user.id !== userId) {
    await interaction.reply({ content: "Это не твой персонаж.", ephemeral: true });
    return;
  }

  if (action === "cancel") {
    await interaction.update({ content: "Отменено.", embeds: [], components: [] });
    return;
  }

  const backendUrl = process.env.BACKEND_API_URL;
  const response = await fetch(`${backendUrl}/bot/characters/${userId}`, {
    method: "DELETE",
    headers: { "X-Bot-Secret": process.env.BOT_API_SECRET },
  });

  if (!response.ok) {
    await interaction.update({
      content: "Не удалось удалить персонажа. Попробуй позже.",
      embeds: [],
      components: [],
    });
    return;
  }

  const embed = new EmbedBuilder()
    .setTitle("Персонаж удалён")
    .setDescription("Открой Activity (/play) в голосовом канале, чтобы создать нового.")
    .setColor(0x23a55a);

  await interaction.update({ content: null, embeds: [embed], components: [] });
}
