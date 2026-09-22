export interface TournamentMatch {
  round: number;
  slot: number;
  characterA: string | null;
  characterB: string | null;
  winner: string | null;
  isBye: boolean;
}

export interface Tournament {
  id: number;
  status: "registration" | "finished";
  entryCount: number;
  champion: string | null;
  matches: TournamentMatch[];
}
