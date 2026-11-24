# Run on XAMPP (5 steps)

1. Copy the whole `Final-Project-main` folder into `C:\xampp\htdocs\`
   (keep `kph_user_account_identity` and `kph_shopping_promotions` side by side).
2. Open the XAMPP Control Panel and start **Apache** and **MySQL**.
3. Open http://localhost/phpmyadmin -> **Import** tab -> choose
   `kph_user_account_identity/sql/xampp_setup.sql` -> **Go**.
4. Open http://localhost/Final-Project-main/kph_user_account_identity/web/register.html
   and create an account (or `login.html`).
5. Done.

## Hardcoded codes (no real email/SMS yet)
Everything uses the code **123456**:
- Forgot password -> "Get reset code" -> Continue -> enter email/phone, code `123456`, new password -> log in.
- Verify email / phone (Edit profile) -> Send code -> enter `123456`.
- Changing email / phone -> same code `123456`.

To turn real codes back on later: `config/config.php` -> `"use_demo_codes" => false`.

## Using Azure instead
Copy `config/config.local.example.php` to `config/config.local.php` and fill in the Azure values.
