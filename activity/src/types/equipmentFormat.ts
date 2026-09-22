import type { EquipmentItem } from "./equipment";

const FACE_LABEL: Record<string, string> = {
  attack: "удар",
  defense: "защита",
  action: "действие",
};

export function formatEquipmentEffect(item: EquipmentItem): string {
  if (item.effectType === "hp") {
    return `+${item.hpBonus} к максимальному HP`;
  }
  return `Новая бита: ${FACE_LABEL[item.bitFaceA ?? ""]} / ${FACE_LABEL[item.bitFaceB ?? ""]}`;
}
