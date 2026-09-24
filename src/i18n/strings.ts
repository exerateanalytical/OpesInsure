import { en } from "./en";
import { fr } from "./fr";

/** EN + FR catalogues. Add a key to en.ts and the Record type in fr.ts
 * forces the French translation. */
export const copy = { en, fr } as const;
export type Language = keyof typeof copy;
export type CopyKey = keyof typeof en;
