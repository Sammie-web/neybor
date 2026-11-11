# Payment provider evaluation and MVP boundary

## Recommendation

Keep payments off in the MVP. Evaluate **Paystack** and **Flutterwave** as the first Nigerian-market candidates; do not select one as a production provider or route real money until the business entity, account eligibility, settlement, pricing, support, refund, and marketplace-split requirements have been confirmed with each provider and tested in their sandbox.

The admin settings panel can record a preference for Paystack, Flutterwave, or both. This is configuration intent only. `payments_enabled` is always `false`; there is no checkout endpoint, payment adapter, transaction ledger, commission settlement, refund operation, or successful-payment UI. No transaction or revenue amount is fabricated.

## Initial comparison

| Consideration | Paystack | Flutterwave |
| --- | --- | --- |
| Nigeria fit | Paystack's official documentation describes payment acceptance in mobile apps/websites and Nigerian bank transfers/disbursements. Verify current product eligibility and supported payment methods for Neybor's account before selecting. | Flutterwave's official payment-method documentation lists NGN payment options including cards, USSD, bank transfer, account charge, internet banking, NQR, eNaira, and OPay. Confirm account-level availability before selecting. |
| Collection flow | Official documentation describes server-side transaction initialization, customer checkout, backend verification, and webhooks. | Standard Checkout creates a hosted payment link from a server-side request; the customer returns to the application, and webhooks/verification support asynchronous confirmation. |
| Transaction integrity | Treat the client return as untrusted. Verify the transaction server-to-server against a unique internal reference, expected amount, currency, and status before marking an order paid. | Flutterwave explicitly recommends checking the generated reference, `successful` status, expected currency, and expected amount on its verification endpoint. Validate signed webhooks and reconcile with verification. |
| Marketplace payouts | Paystack documents Nigerian transfers; availability of split settlements, subaccounts, marketplace onboarding, and provider payouts must be confirmed for Neybor's business/account. | Flutterwave Standard documents subaccounts/split payments and documents transfers; eligibility, settlement timing, reversal handling, and provider payout constraints still need provider confirmation. |
| Commercial/operational checks | Obtain current fees, settlement schedule, dispute/chargeback terms, support SLAs, KYC requirements, transfer fees/limits, and production activation requirements directly from Paystack. | Obtain current fees, settlement schedule, dispute/chargeback terms, support SLAs, KYC requirements, transfer fees/limits, and production activation requirements directly from Flutterwave. |

This is a technical shortlist, not a pricing comparison. Rates and legal/account eligibility change and must be obtained from the providers during onboarding. Do not configure default marketplace commission, cancellation fees, or refund terms; the initial settings remain blank until approved by Neybor.

## Architecture before implementation

If payments are approved, implement a provider adapter boundary (for example `PaymentGateway` with Paystack and Flutterwave adapters) behind a single internal payment service. Keep gateway secrets exclusively in a server-side secret manager/environment, never in the dashboard or Vite variables. Persist internal transaction intent and an idempotency key before redirecting to a gateway; process verified webhooks idempotently; verify status/amount/currency/reference server-side; model pending, successful, failed, cancelled, and refunded separately; and make reconciliation observable. Payment success must not be inferred from a browser redirect or push notification.

Before marketplace settlement, decide and document who is merchant of record, when provider earnings become payable, commission calculation and rounding, refunds, partial refunds, cancellations, chargebacks, provider KYC, ledger/reconciliation, and tax/accounting treatment. Those rules are product and legal decisions, not gateway defaults.

## Sources

- [Paystack — Introduction (official documentation repository)](https://github.com/PaystackHQ/documentation/blob/master/getting-started/introduction.md)
- [Paystack — Verifying a transaction (official documentation repository)](https://github.com/PaystackHQ/documentation/blob/master/receiving-payments/verifying-the-transaction.md)
- [Flutterwave — Payment methods](https://developer.flutterwave.com/docs/payment-methods)
- [Flutterwave — Standard checkout](https://developer.flutterwave.com/docs/flutterwave-standard-1)
- [Flutterwave — Transaction verification](https://developer.flutterwave.com/docs/transaction-verification)
- [Flutterwave — Webhooks](https://developer.flutterwave.com/docs/webhooks)
