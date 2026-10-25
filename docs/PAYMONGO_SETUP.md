# Online GCash with PayMongo (test mode)

Customers pay with GCash on PayMongo's page, and the order is marked **paid
automatically**. No reference number, nothing for the cashier to check.

If `PAYMONGO_SECRET_KEY` is empty, the shop keeps using the old manual
GCash-reference flow. To switch back at any time: empty the key.

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
- **Customer backs out:** they get "Payment not completed" with a button to
  cancel the order. Their cart is kept.
- **Never paid:** after 45 minutes (`PAYMONGO_UNPAID_ORDER_MINUTES` in
  `config/constants.php`) the order is cancelled automatically and the
  ingredients/loyalty points are released.
- **Safety:** the webhook checks PayMongo's signature, ignores repeated
  deliveries, and checks that the amount paid matches the order.

## If something goes wrong

| Problem                                      | Likely cause                                                                                                                                                                                                       |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| "Online payment is not available right now"  | Wrong/missing secret key, or PHP cURL cannot verify SSL. On XAMPP set `PAYMONGO_CA_BUNDLE` to the path of a `cacert.pem` file. Check the PHP error log.                                                            |
| Paid, but the order stays "Awaiting payment" | The webhook did not arrive or was rejected: wrong webhook secret, ngrok address changed (update the webhook URL in PayMongo), or wrong base path. PayMongo's dashboard shows each delivery and lets you resend it. |
| Cashier page shows a database error          | The migration was not applied (step 1).                                                                                                                                                                            |
| Redirect after paying shows a login page     | You opened the shop on `localhost` instead of the ngrok address.                                                                                                                                                   |
| ngrok shows a warning page the first time    | Normal for free ngrok. Click through once.                                                                                                                                                                         |

## Known limits (fine for a demo, worth knowing)

- A customer who pays after their order was auto-cancelled (45 min) gets no
  order. It is written to the PHP error log as "PAID BUT ORDER CANCELLED" so
  staff can refund it by hand.
- Refunds are not automated.
- The customer cannot reopen a payment page they closed. They place the order
  again (the old one is released).
- Going live with real money needs a registered business account with PayMongo
  and the **live** keys; this setup is for test mode.
