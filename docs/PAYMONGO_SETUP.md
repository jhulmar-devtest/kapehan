# Online GCash with PayMongo (test mode)

Customers pay with GCash on PayMongo's page, and the order is marked **paid
automatically**. No reference number, nothing for the cashier to check.

If `PAYMONGO_SECRET_KEY` is empty, the shop keeps using the manual
GCash-reference flow. Once configured, an admin can pause PayMongo for new
checkouts at Admin > Settings without removing the secret key. Existing
PayMongo sessions remain usable so customers can finish or cancel them.

## One-time setup

1. **Back up your database**, then apply the migration:

   ```
   php database/migrate.php apply
   ```

   (No PHP on the command line? Run `database/migrations/2026_10_23_add_paymongo_gateway.sql`
   in phpMyAdmin instead.) **Do this first.** The cashier pre-orders page reads
   the new columns.

2. **PayMongo account**: sign up at paymongo.com and stay in **Test mode**.
   Developers > API Keys > copy the **secret** key (`sk_test_...`).

3. **Start ngrok** and keep it running (do not restart it before the defense):

   ```
   ngrok http 80
   ```

   Open the shop **through the ngrok https address** from now on (login, menu,
   everything). The customer is sent back to the same address after paying, and
   their login only exists on the address they signed in on.

4. **Create the webhook** (PayMongo dashboard, Test mode):
   Developers > Webhooks > Add endpoint.
   - URL: `https://YOUR-NGROK-ADDRESS` + `/kapehan/public/api/paymongo-webhook.php`
     (use your own base path if it is not `/kapehan/public`)
   - Event: `checkout_session.payment.paid`
   - Copy the **webhook secret** it shows you.

5. **Put the keys in `config/secrets.php`** (copy the lines from
   `config/secrets.example.php`):
   ```php
   define('PAYMONGO_SECRET_KEY', 'sk_test_...');
   define('PAYMONGO_WEBHOOK_SECRET', 'whsk_...');
   ```
   Never share these or put them on GitHub.

## Try it

1. Log in as a student, add a drink, press checkout, choose a pickup time.
2. The payment box now says **Pay with GCash**. Press it.
3. You land on PayMongo's page. In test mode you can simulate a successful or
   a failed payment (see PayMongo's "Testing" docs page for the exact steps).
4. After a successful payment you return to "Payment received!".
5. Log in as the cashier: the order shows **Paid online** and Start Preparing
   works with no manual check.

## How it behaves

- **Unpaid order:** the cashier sees "Awaiting payment" and cannot start it.
- **Customer backs out:** they get "Payment not completed" with **Complete
  payment** and **Cancel this order** buttons. The same two buttons show on the
  order in My Orders, so closing the PayMongo tab is never a dead end.
- **One unpaid online order at a time:** a customer with an unpaid online
  order must finish or cancel it before placing another.
- **Never paid:** after 45 minutes (`PAYMONGO_UNPAID_ORDER_MINUTES` in
  `config/constants.php`) the order is cancelled automatically and the
  ingredients/loyalty points are released. This runs when someone opens
  checkout or the cashier pre-orders page.
- **Cancelling closes the payment page:** PayMongo checkout pages never expire
  on their own, so every cancel (customer, cashier, or the 45-minute timer)
  first expires the PayMongo session. If that cannot be confirmed, or the
  customer turns out to have paid, the order is **not** cancelled.
- **Missed webhook:** pages that show a waiting order (return page, My Orders)
  and the cancel step ask PayMongo directly whether the payment went through,
  so a paid order is still marked paid if the webhook never arrived.
- **Cashier steps are enforced by the server:** an order can only move
  pending → preparing → ready → claimed (cancel from any of those), and an
  unpaid online order cannot move forward.
- **Maintenance switch:** Admin > Settings can pause PayMongo for new orders.
  New checkouts then use manual GCash if the recipient is configured. Existing
  PayMongo payment pages stay usable so customers can finish or cancel them.
- **Safety:** the webhook checks PayMongo's signature, ignores repeated
  deliveries, and checks that the amount paid matches the order.

## Manual GCash review and cancellation

Manual GCash references stay pending until a cashier checks the shop's GCash
transaction history and uses **Verify Payment**. The cashier then starts
preparation as a separate action. A customer can request cancellation while
the order is still pending; the cashier must check whether money arrived.
Unpaid orders can be cancelled and released. If payment arrived, the order is
blocked from preparation and appears in Admin > Sales > Refunds. Complete the
refund through the payment account first, then record its refund reference in
the admin page. Refunds are not sent automatically by this app. Manual orders
pending for more than 45 minutes are highlighted to staff for review, but are
not automatically cancelled because the app cannot tell whether a transfer
arrived from a customer-entered reference alone.

## If something goes wrong

| Problem                                      | Likely cause                                                                                                                                                                                                       |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| "Online payment is not available right now"  | Wrong/missing secret key, or PHP cURL cannot verify SSL. On XAMPP set `PAYMONGO_CA_BUNDLE` to the path of a `cacert.pem` file. Check the PHP error log.                                                            |
| Paid, but the order stays "Awaiting payment" | The webhook did not arrive or was rejected: wrong webhook secret, ngrok address changed (update the webhook URL in PayMongo), or wrong base path. PayMongo's dashboard shows each delivery and lets you resend it. |
| Cashier page shows a database error          | The migration was not applied (step 1).                                                                                                                                                                            |
| Redirect after paying shows a login page     | You opened the shop on `localhost` instead of the ngrok address.                                                                                                                                                   |
| ngrok shows a warning page the first time    | Normal for free ngrok. Click through once.                                                                                                                                                                         |

## Known limits (fine for a demo, worth knowing)

- If a payment still lands on an order that was cancelled (for example PayMongo
  was unreachable at the exact moment), it is written to the PHP error log as
  "PAID BUT ORDER CANCELLED" so staff can refund it by hand.
- Refunds are not automated, and a paid online order that a cashier cancels
  still has to be refunded by hand in the PayMongo dashboard.
- Going live with real money needs a registered business account with PayMongo
  and the **live** keys; this setup is for test mode.
