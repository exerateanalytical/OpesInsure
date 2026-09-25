# Cloud baseline failures: triage

Source: `docs/audit/CLOUD_BASELINE_FAILURES.txt` (51 failed, 1039 passed, in the claude.ai cloud container at commit 00ca993).

## Verdict

**All 51 failures come from the environment. None is an app bug.** The tests assert fixed test-only values, such as
`ACtestingaccountsid00000000000000`, `testing-orange-callback-token` and a local default disk. Those values were never
committed: they exist only in a gitignored `.env.testing` on the owner's machine (`.gitignore:2 /.env.testing`). The
cloud container has no `.env.testing`. Its `.env` points `FILESYSTEM_DISK` at `s3` (host `minio:9000`, which does not
resolve), and its provider keys are empty.

**Fix:** only `phpunit.xml`. The same test values are declared as `<env>` entries there. No app code or assertion was
changed. PHPUnit sets these entries before Laravel loads dotenv, and dotenv is immutable, so they apply in every checkout.
They should match the owner's local `.env.testing`, because the tests hard-code them. That makes the change harmless locally.

**Result after the fix:** `tests/Feature/Wave4 Wave8 Wave12 Documents Wave15/PlatformSettingsAndAuthTest.php` gave
351 passed, 0 failed.

## Groups

| Group | Tests (count) | Symptom in cloud | Cause | Class | Fix (phpunit.xml env) |
|---|---|---|---|---|---|
| Twilio SMS / WhatsApp adapters | Wave8 TwilioSmsAdapterTest, TwilioWhatsAppAdapterTest (3) | `DomainException: Twilio is not configured.` | `services.twilio.*` is empty. The tests assert SID `ACtestingaccountsid…`, From `+15005550006` and `whatsapp:+14155238886` | env | `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_SMS_FROM`, `TWILIO_WHATSAPP_FROM` |
| Twilio delivery receipts | Wave8 TwilioDeliveryReceiptControllerTest (3) | Signature rejected / not processed | The tests sign with `testing-twilio-auth-token`, but the app has an empty auth token | env | `TWILIO_AUTH_TOKEN` |
| Notification dispatch | Wave8 DispatchPendingNotificationsTest, NotificationDispatchServiceTest (2) | No SMS sent | The Twilio adapter is unconfigured | env | Twilio vars |
| OTP login / step-up / refresh / self-registration / platform-settings auth | Wave12 MobileAuthFlowTest (7), StepUpAuthTest (6), MobileRefreshAndLogoutTest (4), MobileSelfRegistrationTest (3), Wave15 PlatformSettingsAndAuthTest (7) | `Http::assertSent` / `assertSentCount(1)`: no Twilio request recorded | eTech is unconfigured, so the OTP provider chain falls through to Twilio, which is also unconfigured. No OTP is sent, so the flows cannot progress | env | Twilio vars |
| MTN MoMo adapter + callbacks | Wave4 MtnMomoAdapterTest (2), MtnMomoCallbackControllerTest (4) | Adapter refuses (not configured) / callback token mismatch | The tests expect `testing-subscription-key` and `testing-mtn-callback-token`. `api_user` and `api_key` must be non-empty | env | `MTN_MOMO_SUBSCRIPTION_KEY`, `MTN_MOMO_API_USER`, `MTN_MOMO_API_KEY`, `MTN_MOMO_CALLBACK_TOKEN` |
| Orange Money adapter + callbacks | Wave4 OrangeMoneyAdapterTest (2), OrangeMoneyCallbackControllerTest (4) | Same as MTN | The tests expect `testing-merchant-key` and `testing-orange-callback-token`. The client id and secret must be non-empty | env | `ORANGE_MONEY_MERCHANT_KEY`, `ORANGE_MONEY_CLIENT_ID`, `ORANGE_MONEY_CLIENT_SECRET`, `ORANGE_MONEY_CALLBACK_TOKEN` |
| Mobile / staff documents | Wave12 MobileDocumentTest (3), Documents StaffDocumentAccessTest (1) | `cURL error 6: Could not resolve host: minio`, 404 on the signed download | The tests `Storage::fake('local')` and expect the default disk to be `local`. The cloud `.env` has `FILESYSTEM_DISK=s3`, and `config/filesystems.php` also defaults to `s3` | env | `FILESYSTEM_DISK=local` |

Total: 3+3+2+27+6+6+4 = 51.

## Notes / follow-ups

- None of the added values are secrets. They are placeholders asserted by the tests, and every outbound HTTP call in
  those tests is `Http::fake()`d.
- Suggestion (not done, outside this scope): commit a `.env.testing.example` or document that `phpunit.xml` is now
  the single source for test provider placeholders. The owner's local `.env.testing` can drop these keys.
- `config/filesystems.php` defaults to `s3` when `FILESYSTEM_DISK` is unset. That is intended for production and was left unchanged.
- A fresh worktree also has no `storage/app/` directory. `MasterDataExportService` then fails with `fopen ... No such file`.
  This is a checkout-setup issue (create `storage/app/{private,public}`), not an app bug. It is not in the 51.
- Worktrees must not symlink `vendor/`. With a symlink, Composer and Pest resolve to the main checkout, so the tests run
  the main repo's code. Use a hard-linked copy (`cp -al`) or a real install.
