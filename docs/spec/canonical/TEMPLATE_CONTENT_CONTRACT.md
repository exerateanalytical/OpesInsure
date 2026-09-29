# Template content contract (canonical templates DOC-001 … DOC-220)

Owner approval 2026-09-28: every canonical template is built, approved and published by the seeders in
`database/seeders/CanonicalTemplates/`. This note is the contract shared by the four part seeders
(PartA DOC-001–055, PartB 056–110, PartC 111–165, PartD 166–220). Keep to it exactly.

## 1. Use the shared base class (do not re-implement the lifecycle)

```php
namespace Database\Seeders\CanonicalTemplates;

final class CanonicalTemplatesPartBSeeder extends CanonicalTemplateSeeder
{
    protected function documents(): array
    {
        return [
            'DOC-056' => [
                'intro_en' => 'Enrolment of the member named below under the policy {policy_number}.',
                'intro_fr' => "Adhésion de l'assuré désigné ci-dessous au titre de la police {policy_number}.",
                'fields' => [
                    // [canonical key, English label, French label, zone]
                    ['member.reference', 'Member number', "N° d'adhérent", 'C'],
                    ['health.dependants', 'Dependants', 'Ayants droit', 'D'],
                ],
                // optional
                'sections' => [['heading_en' => '…', 'heading_fr' => '…', 'body_en' => '…', 'body_fr' => '…']],
                'notices' => [['en' => '…', 'fr' => '…']],
                'languages' => ['BILINGUAL', 'FR', 'EN'], // default
                'insurance_class' => 'LIFE', // ONLY for life documents: life policies accept LIFE-scoped templates only
            ],
        ];
    }
}
```

`CanonicalTemplateSeeder::run()` (written by agent 1) does, per document and per language:
resolve the catalogue type from `document_types.canonical_spec_id` → build `content` → compare its content hash
with the current PUBLISHED version of the lineage (`<type>|PLATFORM|-|-|-|-|<lang>`) → skip when identical,
otherwise `createDraft` (system author `ProviderDocumentTemplateSeeder::SYSTEM_USER_ID`) → `submit` →
`approveAndPublishSystem` by the owner-approval account (`CanonicalTemplateSeeder::OWNER_APPROVER_ID`).
Publishing retires the previous version. Titles come from `document_canonical_specs.name_en/name_fr`.
Do not call DocumentTemplateService yourself and do not create your own system users (a second approver
account with the email `owner-approval-templates@opesinsure.invalid` violates `users_email_unique` and
aborts the part). If a part keeps its own loop, it must use `CanonicalTemplateSeeder::systemUser()` with
`CanonicalTemplateSeeder::OWNER_APPROVER_ID` as the approver.

## 2. Stored `content` shape (what the engine reads)

```json
{
  "schema": "canonical-template/v1",
  "canonical_spec_id": "DOC-001",
  "system_seeded": true,
  "source": "OpesInsure_220_Document_Field_Data_Specification_v1",
  "sections": [ { "heading_en": "", "heading_fr": "", "body_en": "", "body_fr": "" } ],
  "fields":   [ { "key": "party.name", "label_en": "", "label_fr": "", "zone": "C" } ],
  "notices":  [ { "en": "", "fr": "" } ]
}
```

- `sections` (existing format, unchanged, backward compatible): printed in Zone D in order. Body placeholders
  substituted by the engine: `{policy_number} {insured_name} {carrier_name} {product_name} {subject}
  {coverage_start} {coverage_end} {document_number} {event}`. The same placeholders are substituted in
  `notices` (DocumentEngine::shellContent). Nothing else is substituted.
- `fields` (new): one entry per field of the field-data spec for that document. `zone` is `C` (parties /
  insured risk) or `D` (transaction content). The shell prints `label` + the value the engine resolved for
  `key` (canonical values from `DocumentFieldRequirements::resolve`, `MappedFieldValues`, and the event's
  `ctx['fields']`). A key with no value prints "Non renseigné / Not recorded" — never an invented value.
  Keys already printed by the fixed zones (party.name, policy.number, policy.insurer, policy.product,
  policy.effective_from/until, risk.*, premium.gross/taxes, payment.*, coverage.lines, claim.number,
  endorsement.number/changes) are listed for completeness but not printed twice.
- Optional 5th element / `format` property: `money` (integer minor units → "1 250 000 XAF"), `date`, `datetime`,
  `text` (print as recorded). Without a hint, an integer prints as money when the key names an amount
  (`DocumentShellView::isMoneyKey`: premium.*, *_minor, gross, net, balance, retention, ceded, prior_payments,
  loss, fee(s), tax(es), total, value, limit, deductible, sum_insured, refund, reserve, settlement.*, recovery,
  commission …; never keys containing count/number/percent/rate/year/days/share), and an ISO date string prints
  as dd/mm/yyyy. Example: `['settlement_batch.net', 'Net amount', 'Montant net', 'D', 'money']`.
- Table fields (multi-row values: dependants, beneficiaries, census, movements, installments, vehicles, drivers):
  format `table` with a 6th element listing the columns `[column_key, label_en, label_fr, format?]`, e.g.
  `['insured.persons', 'Insured persons', 'Personnes assurées', 'C', 'table', [['name', 'Name', 'Nom'],
  ['since', 'Since', 'Depuis', 'date'], ['premium_minor', 'Premium', 'Prime', 'money']]]`. The value of the key
  must be a list of associative arrays; the shell prints one row per item ("—" for an empty cell) and "Not
  recorded" when the list is empty. Stored form: `{"key", "label_en", "label_fr", "zone", "format": "table",
  "columns": [{"key", "label_en", "label_fr", "format"?}]}`.
- Fixed-zone keys (party.name, policy.number …) are skipped only when the fixed zone actually printed them
  (e.g. `premium.taxes` is printed by the premium block only when a gross premium exists; `claim.number` only
  when a claim is attached); otherwise the template row is printed. Empty fixed rows print "Not recorded".
- Use an existing key from `App\Application\DocumentCatalogue\CanonicalFieldDictionary::KEYS` whenever one
  fits. Otherwise use a lower-case dotted key in the obvious namespace (`quote.valid_until`,
  `claim.loss_location`, `provider.facility`, `life.sum_assured` …); the engine fills it when an event
  supplies it.
- Do NOT put in the template: the document number, dates of issue, QR, hash, verification code, signature,
  seal, tier, watermark, microtext, guilloche, letterhead, footer or page numbers. The shell adds all of
  these from the catalogue security profile (tier S1–S5, master shell code, watermark/seal/physical
  profiles). A template cannot raise or lower security.

Rendering paths: `DocumentEngine::generate` passes `$template->content` to the shell; `SecureShellRenderer::render`
takes it as `template_content` (and `specimen(..., $templateContent)`), so previews show the same field rows.
Page "X / Y" is drawn on the PDF canvas by `DocumentShellView::pdf()`.

## 3. Master shells and tiers (decided by the catalogue, not the template)

| `document_types.master_shell_code` | Shell composition |
|---|---|
| TPL-SHELL-QUOTE-001 | QUOTE (premium hero, quotation notice) |
| TPL-SHELL-POLICY-SCHEDULE-001 | SCHEDULE |
| TPL-SHELL-POLICY-CERTIFICATE-001 | CERTIFICATE (certification statement) |
| TPL-SHELL-MOTOR-ATTESTATION-001 | MOTOR (vehicle identity first) |
| TPL-SHELL-PREMIUM-RECEIPT-001 | RECEIPT (amount hero) |
| null | GENERIC zoned shell |

The spec JSON names a shell only for DOC-001/017/022/036/190; documents of the same family get theirs from
`CanonicalDocumentSpecSeeder::FAMILY_SHELLS` (applied only where the catalogue has none). The CERTIFICATE and
MOTOR shells print the statement "This is to certify that <policyholder> is insured under policy <n> for the
period stated, subject to the policy terms and conditions" — do not repeat it in a template intro.

Tiers S1–S5 (`document_types.security_tier`) switch on: S1 number, template version, issuer, timestamp,
footer; S2 + QR/short code, hash, standard watermark; S3 + seal, microtext, guilloche, dynamic watermark,
signature/approval block; S4 + maker-checker, signer identity, stronger pattern; S5 + physical stock /
UV / hologram statements (never simulated artwork).

## 4. Wording rules

Neutral professional wording only. No legal clauses, obligations, amounts or deadlines beyond the spec
text. No "sample", "example", "lorem", "specimen", "TODO", "placeholder", "test" wording anywhere in a
template. The DEMO watermark is added by the shell only for demo-flagged records.
