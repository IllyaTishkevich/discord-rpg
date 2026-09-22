import { SlashCommandBuilder, EmbedBuilder } from "discord.js";

const FACE_LABEL = { attack: "удар", defense: "защита", action: "действие" };

function formatEffect(item) {
  if (item.effectType === "hp") {
    return `+${item.hpBonus} к максимальному HP`;
  }
  return `Новая бита: ${FACE_LABEL[item.bitFaceA] ?? item.bitFaceA} / ${FACE_LABEL[item.bitFaceB] ?? item.bitFaceB}`;
}

export default {
  data: new SlashCommandBuilder().setName("shop").setDescription("Показать список снаряжения в магазине"),

  async execute(interaction) {
    const backendUrl = process.env.BACKEND_API_URL;
    const response = await fetch(`${backendUrl}/equipment`);

    if (!response.ok) {
      await interaction.reply({ content: "Не удалось загрузить магазин. Попробуй позже.", ephemeral: true });
      return;
    }

    const items = await response.json();

    const embed = new EmbedBuilder()
      .setTitle("Магазин")
      .setDescription("Открой Activity в голосовом канале (/play), чтобы купить снаряжение.")
      .addFields(items.map((item) => ({ name: `${item.name} — ${item.price} монет`, value: formatEffect(item) })))
      .setColor(0x5865f2);

    await interaction.reply({ embeds: [embed] });
  },
};
