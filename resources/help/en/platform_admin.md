# Platform administrator guide

The administration console (**/admin**) is used by the OpesInsure platform team to set up organisations, users, products and integrations, and to supervise operations across tenants. Every screen and action is permission-gated: if you do not see an entry, your role does not grant it.

## Signing in and finding your way {#getting-started}

- Sign in at **/admin/login**. Use the password reset link if needed; your profile is under the user menu.
- The sidebar groups screens by area. Collapse it with the arrow to gain space. Switch **EN / FR** in the top bar.
- Use **Search** to find a customer, policy, claim, quote, document or vehicle across your scope.
- Lists have filters, a search box and column toggles; a row opens the record's detail page with its actions in the header.

## Organisations and branches {#tenants}

- **Tenants** holds every organisation (insurer, broker, provider). **Create** a tenant, **Edit** its details (legal name, registration, timezone) and use **change status** to activate or suspend it.
- **Branches** records the branches of an organisation; branch managers only see their own branch.
- **Organisation settings** holds the settings of the organisation you are working in.

## Users, memberships and invitations {#users}

- **Users** – create and edit user accounts.
- **Memberships** link a user to an organisation with a role (and optionally a branch). **Create** a membership to grant access; **Revoke** removes it.
- **Invitations** – invite a person by e-mail or phone with a role; the invitation shows recipient, role, organisation and expiry. **Revoke** an invitation that should no longer be used.
- Roles carry the permissions; a user only sees the screens and records their role allows.

## Products and tariffs {#products}

- **Insurance products**: create a product, then use the product builder actions on its page (attributes, test cases, **run tests**, advance through review, **publish** or **reject**).
- **Tariff versions**: create and edit the tariffs of a product. Only published products with a valid tariff can be sold.
- The CIMA regulatory dictionary screens map products to CIMA branches and reporting categories.

## Policies and claims {#policies-claims}

- **Policies**: open a policy to endorse it, decide a service request, request, review or decide a cancellation, and **issue a certificate** for an active policy (template, serial number, sticker).
- **Claims**: register a claim with the step-by-step wizard on the list page. On a claim, the actions menu covers the whole life cycle: assign, assess, review the assessment, set and approve reserves, decide and approve the decision, offer a settlement, settle, request / approve / reverse a payment, open or resolve a dispute, and recoveries.
- Decisions above a user's authority go to **Approval requests** for a second person (maker-checker).

## Payments and finance {#finance}

- **Payment requests**: see every mobile-money or bank payment and **initiate** one when needed.
- **Reconciliations**: compare payments received with what was expected; the list shows matched lines and exceptions.
- Finance launch pages: **account statements** and the **exception centre** list balances and items needing attention.

## Documents {#documents}

- The **Document engine overview** leads to the document register (types and families), product documents, localisation, QR and signature configuration, signing keys, quality and audit.
- **Letterhead designer** sets the letterhead used on issued documents.

## Operations, integrations and settings {#operations}

- **Operations desk**, **Platform desk** and **Configuration desk** gather the day-to-day queues: failed jobs, notification templates, rating runs, reconciliation exceptions, adjuster assignments, calendars, KPI catalogue and release assurance.
- **Integration health** shows the state of external connections (payments, SMS, insurers).
- **System health**, **backup & recovery**, **audit trail** and **login activity** support supervision and security.
- **Platform settings**: SMS, WhatsApp and Twilio channels, channel and provider priority, the **default timezone** (Africa/Douala unless changed), sign-up verification and partner onboarding e-mail. Changes apply platform-wide: check with the team before saving.
