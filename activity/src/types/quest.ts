export interface CurrentQuest {
  weekStart: string;
  type: string;
  targetValue: number;
  rewardXp: number;
  rewardCoins: number;
  progress: number;
  isComplete: boolean;
  isClaimed: boolean;
}
