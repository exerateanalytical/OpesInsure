/** Language resolution: the app follows the device / browser language
 * unless the user explicitly picked one in the app (Settings > Language).
 * Pure so it can be tested under node. */
export type AppLanguage = "en" | "fr";

export const LANGUAGE_CHOICE_KEY = "opesinsure.language_choice.v1";

/** Maps any BCP-47 tag ("fr-CM", "FR", "en_US") to a supported language;
 * anything unsupported or missing falls back to English. */
export const normalizeLanguage = (tag: unknown): AppLanguage | null => {
  if (typeof tag !== "string" || !tag.trim()) return null;
  const base = tag.trim().toLowerCase().split(/[-_]/)[0];
  return base === "fr" ? "fr" : base === "en" ? "en" : null;
};

/** First supported tag in the device list wins; otherwise English. */
export const deviceLanguageFrom = (tags: readonly unknown[]): AppLanguage => {
  for (const tag of tags) {
    const lang = normalizeLanguage(tag);
    if (lang) return lang;
  }
  return "en";
};

/** Explicit in-app choice beats the device language; the server user
 * locale is deliberately ignored so the app follows the browser/device. */
export const resolveLanguage = (
  explicitChoice: unknown,
  deviceTags: readonly unknown[],
): AppLanguage => normalizeLanguage(explicitChoice) ?? deviceLanguageFrom(deviceTags);
