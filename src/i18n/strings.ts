export const copy = {
  en: {
    brandPromise: 'Insurance made clear.', signIn: 'Sign in', continue: 'Continue', compare: 'Compare offers',
    greeting: 'Good morning', protect: 'What would you like to protect?', policies: 'Policies', claims: 'Claims',
    account: 'Account', home: 'Home', insurers: 'Insurers', brokers: 'Brokers', verifiedOnline: 'Verified online offer',
  },
  fr: {
    brandPromise: 'L’assurance en toute clarté.', signIn: 'Se connecter', continue: 'Continuer', compare: 'Comparer les offres',
    greeting: 'Bonjour', protect: 'Que souhaitez-vous protéger ?', policies: 'Contrats', claims: 'Sinistres',
    account: 'Compte', home: 'Accueil', insurers: 'Assureurs', brokers: 'Courtiers', verifiedOnline: 'Offre vérifiée en ligne',
  }
} as const;
export type Language = keyof typeof copy;
