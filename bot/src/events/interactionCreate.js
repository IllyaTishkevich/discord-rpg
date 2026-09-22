import { handleDuelButton } from "../interactions/duelButtons.js";

export default {
  name: "interactionCreate",
  async execute(interaction) {
    try {
      if (interaction.isChatInputCommand()) {
        const command = interaction.client.commands.get(interaction.commandName);
        if (command) {
          await command.execute(interaction);
        }
        return;
      }

      if (interaction.isButton() && interaction.customId.startsWith("duel:")) {
        await handleDuelButton(interaction);
      }
    } catch (error) {
      console.error("Error handling interaction:", error);
      const reply = { content: "Произошла ошибка при выполнении команды.", ephemeral: true };
      if (interaction.replied || interaction.deferred) {
        await interaction.followUp(reply);
      } else {
        await interaction.reply(reply);
      }
    }
  },
};
