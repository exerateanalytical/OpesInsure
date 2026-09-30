/**
 * Localized labels for the insurer `product_families` published in the 2026
 * register (database/data/cameroon_insurance_register_2026.json). The API sends
 * the carrier-published free text (mostly English, some French); this maps each
 * known string to an EN / FR label. Brand names (Sécur'Etudes, Âge d'Or Retraite…)
 * stay as published; an unknown string is shown verbatim. No imports: node-tested.
 */
export type FamilyLang = "en" | "fr";

const squash = (v: string) =>
  v.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().replace(/[^a-z0-9]/g, "");

/** [published text, English label, French label] */
const FAMILIES: [string, string, string][] = [
  ["Accident", "Accident", "Accidents"],
  ["Accident/health", "Accident & health", "Accidents et santé"],
  ["Agriculture/livestock", "Agriculture & livestock", "Agriculture et élevage"],
  ["Assistance", "Assistance", "Assistance"],
  ["Auto", "Motor", "Automobile"],
  ["Automobile", "Motor", "Automobile"],
  ["Bancassurance", "Bancassurance", "Bancassurance"],
  ["Bonds/caution", "Bonds & guarantees", "Cautions"],
  ["Borrower", "Borrower cover", "Assurance emprunteur"],
  ["Borrower cover", "Borrower cover", "Assurance emprunteur"],
  ["Borrower insurance", "Borrower insurance", "Assurance emprunteur"],
  ["Borrower protection", "Borrower protection", "Protection emprunteur"],
  ["Borrower/credit-linked life", "Borrower / credit life", "Emprunteur / vie liée au crédit"],
  ["Borrower/group products", "Borrower & group products", "Emprunteur et produits collectifs"],
  ["Capitalization", "Capitalisation", "Capitalisation"],
  ["Caution", "Bonds & guarantees", "Cautions"],
  ["Caution/Bonds", "Bonds & guarantees", "Cautions"],
  ["Collective Provident", "Group provident", "Prévoyance collective"],
  ["Collective Retirement", "Group retirement", "Retraite collective"],
  ["Commercial and industrial risks", "Commercial & industrial risks", "Risques commerciaux et industriels"],
  ["Commercial/corporate risks", "Commercial & corporate risks", "Risques commerciaux et d'entreprise"],
  ["Commercial/industrial risks", "Commercial & industrial risks", "Risques commerciaux et industriels"],
  ["Construction", "Construction", "Construction"],
  ["Construction/engineering", "Construction & engineering", "Construction et ingénierie"],
  ["Corporate property/liability/engineering", "Corporate property, liability & engineering", "Dommages, RC et ingénierie d'entreprise"],
  ["Corporate risks", "Corporate risks", "Risques d'entreprise"],
  ["Corporate/property risks", "Corporate & property risks", "Risques d'entreprise et dommages aux biens"],
  ["Credit Insurance", "Credit insurance", "Assurance crédit"],
  ["Death", "Death cover", "Décès"],
  ["Death Insurance", "Death insurance", "Assurance décès"],
  ["Death cover", "Death cover", "Garantie décès"],
  ["Death/disability/provident", "Death, disability & provident", "Décès, invalidité et prévoyance"],
  ["Death/protection", "Death & protection", "Décès et prévoyance"],
  ["Death/provident protection", "Death & provident cover", "Décès et prévoyance"],
  ["Disability/death protection", "Disability & death cover", "Invalidité et décès"],
  ["Education", "Education", "Éducation"],
  ["Education/family protection", "Education & family protection", "Éducation et protection de la famille"],
  ["Employee/group life", "Employee group life", "Vie collective du personnel"],
  ["End-of-Career Indemnity", "End-of-career indemnity", "Indemnités de fin de carrière"],
  ["Enterprise", "Business", "Entreprise"],
  ["Enterprise and association solutions", "Business & association solutions", "Solutions entreprises et associations"],
  ["Enterprise/property", "Business & property", "Entreprise et dommages aux biens"],
  ["Family Liability", "Family liability", "Responsabilité civile familiale"],
  ["Family/death protection (Pack Family)", "Family & death cover (Pack Family)", "Protection famille et décès (Pack Family)"],
  ["Fire & Professional Multirisk", "Fire & business multirisk", "Incendie et multirisque professionnelle"],
  ["Fleet", "Fleet", "Flotte automobile"],
  ["Fleet Motor", "Motor fleet", "Flotte automobile"],
  ["General liability", "General liability", "Responsabilité civile générale"],
  ["Goods Transport", "Goods in transit", "Transport de marchandises"],
  ["Goods/Cargo in Transit", "Goods & cargo in transit", "Marchandises transportées"],
  ["Group Provident", "Group provident", "Prévoyance collective"],
  ["Group life", "Group life", "Vie collective"],
  ["Group life/employee benefits", "Group life & employee benefits", "Vie collective et avantages du personnel"],
  ["Group protection", "Group protection", "Prévoyance collective"],
  ["Habitation", "Home", "Habitation"],
  ["Health", "Health", "Santé"],
  ["Health/Evacuation", "Health & medical evacuation", "Santé et évacuation sanitaire"],
  ["Health/Medical", "Health & medical", "Santé et frais médicaux"],
  ["Health/hospitalisation (incl. HospiCare)", "Health & hospitalisation (incl. HospiCare)", "Santé et hospitalisation (dont HospiCare)"],
  ["Health/persons", "Health & personal cover", "Santé et assurances de personnes"],
  ["Home/property", "Home & property", "Habitation et biens"],
  ["IFC", "End-of-career indemnity (IFC)", "Indemnités de fin de carrière (IFC)"],
  ["Individual Accident", "Personal accident", "Individuelle accidents"],
  ["Land Transport", "Land transport", "Transport terrestre"],
  ["Liability", "Liability", "Responsabilité civile"],
  ["Life protection", "Life protection", "Prévoyance vie"],
  ["Life/death protection", "Life & death cover", "Vie et décès"],
  ["Major Risks", "Major risks", "Grands risques"],
  ["Marine/air/other transport", "Marine, aviation & other transport", "Maritime, aérien et autres transports"],
  ["Microinsurance", "Microinsurance", "Micro-assurance"],
  ["Mobile Insurance", "Mobile insurance", "Assurance mobile"],
  ["Motor", "Motor", "Automobile"],
  ["Multirisque Entreprise", "Business multirisk", "Multirisque entreprise"],
  ["Multirisque Habitation", "Home multirisk", "Multirisque habitation"],
  ["Multirisque Habitation/Residencia", "Home multirisk (Residencia)", "Multirisque habitation (Residencia)"],
  ["Multirisque Professionnelle", "Business multirisk", "Multirisque professionnelle"],
  ["Multirisque Professionnelle/Entreprise", "Business multirisk", "Multirisque professionnelle et entreprise"],
  ["Multirisques", "Multirisk", "Multirisques"],
  ["Multirisques Habitation", "Home multirisk", "Multirisques habitation"],
  ["Non-life solutions for individuals and companies", "Non-life cover for individuals & businesses", "Solutions IARD pour particuliers et entreprises"],
  ["Personal accident", "Personal accident", "Individuelle accidents"],
  ["Plan Education", "Education plan", "Plan éducation"],
  ["Professional Liability", "Professional liability", "Responsabilité civile professionnelle"],
  ["Property", "Property", "Dommages aux biens"],
  ["Property/business protection", "Property & business protection", "Biens et protection de l'entreprise"],
  ["Property/fire", "Property & fire", "Dommages aux biens et incendie"],
  ["Property/multirisk", "Property multirisk", "Multirisque dommages aux biens"],
  ["Provident", "Provident", "Prévoyance"],
  ["Provident/Prévoyance", "Provident", "Prévoyance"],
  ["Rente Education", "Education annuity", "Rente éducation"],
  ["Retirement", "Retirement", "Retraite"],
  ["Retirement Plan", "Retirement plan", "Plan retraite"],
  ["Retirement/Pension", "Retirement & pension", "Retraite et pension"],
  ["Retirement/capitalization", "Retirement & capitalisation", "Retraite et capitalisation"],
  ["Savings", "Savings", "Épargne"],
  ["Savings/capitalization", "Savings & capitalisation", "Épargne et capitalisation"],
  ["School Liability", "School liability", "Responsabilité civile scolaire"],
  ["Supplementary retirement/CAREC", "Supplementary retirement (CAREC)", "Retraite complémentaire (CAREC)"],
  ["TAME and assistance", "TAME & assistance", "TAME et assistance"],
  ["Tous Risques Chantier", "Contractors' all risks", "Tous risques chantier"],
  ["Tous Risques Informatiques", "IT all risks", "Tous risques informatiques"],
  ["Transport", "Transport", "Transport"],
  ["Travel", "Travel", "Voyage"],
  ["Travel Assistance", "Travel assistance", "Assistance voyage"],
  ["Travel/assistance", "Travel & assistance", "Voyage et assistance"],
];

const INDEX = new Map(FAMILIES.map(([raw, en, fr]) => [squash(raw), { en, fr }]));

/** Localized label of one published product family; unknown or brand names are returned as published. */
export function productFamilyLabel(raw: string, lang: FamilyLang): string {
  const text = raw.trim();
  return INDEX.get(squash(text))?.[lang] ?? text;
}

/** Localized, de-duplicated labels (several published strings can share one label). */
export function productFamilyLabels(raw: unknown, lang: FamilyLang): string[] {
  if (!Array.isArray(raw)) return [];
  const out: string[] = [];
  for (const f of raw) {
    if (typeof f !== "string" || !f.trim()) continue;
    const label = productFamilyLabel(f, lang);
    if (!out.includes(label)) out.push(label);
  }
  return out;
}
