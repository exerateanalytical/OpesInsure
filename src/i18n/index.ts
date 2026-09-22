import { useSession } from "@/store/session";
import { copy, CopyKey, Language } from "@/i18n/strings";

export const translate = (language: Language, key: CopyKey) =>
  copy[language][key] ?? copy.en[key];

export function useTranslation() {
  const language = useSession((state) => state.language);
  return { language, t: (key: CopyKey) => translate(language, key) };
}

export const formatXaf = (amount: number, language: Language) =>
  `${new Intl.NumberFormat(language === "fr" ? "fr-CM" : "en-CM").format(amount)} FCFA`;

export const formatCameroonDate = (value: string, language: Language) =>
  new Intl.DateTimeFormat(language === "fr" ? "fr-CM" : "en-CM", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "Africa/Douala",
  }).format(new Date(value));
