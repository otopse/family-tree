# family-tree

Landing stránka + jednoduchá registrácia/prihlásenie s overením emailu a telefónu.

## Nastavenie

1. Importujte databázovú schému z `schema.sql`.
2. Nastavte systémové premenné prostredia (ideálne v hostingu):
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_PORT`
   - `APP_URL` (napr. `https://family-tree.cz`)
   - `MAIL_FROM`, `MAIL_FROM_NAME`
   - `SMS_API_URL`, `SMS_API_TOKEN` (ak máte SMS provider)
   - `PHONE_CODE_TTL_MINUTES` (predvolene 10)
   - `EMAIL_TOKEN_TTL_HOURS` (predvolene 48)
3. Ak `SMS_API_URL` nie je nastavené, SMS kód sa zapisuje do PHP error logu.

## Stránky

- `public/index.php` – landing
- `public/register.php` – registrácia
- `public/login.php` – prihlásenie
- `public/verify-email.php` – overenie emailu
- `public/verify-phone.php` – overenie telefónu (SMS kód)
- `public/account.php` – jednoduchý účet
