# IDer Login for Joomla

"Sign in with IDer" for **Joomla 5 and 6**: users sign in and sign up by scanning a QR code with the [IDer](https://www.ider.com) app, without a password. It works like the IDer Login plugin for [WordPress](https://wordpress.org/plugins/ider-login/) and the [IDer Login module for PrestaShop](https://github.com/iDuckSRL/prestashop-ider-login).

## Features

- **"Sign in with IDer" button** in the Joomla login form and in the Login module.
- **Account:** the user is found by IDer ID or by email. If there is no account, a new one is created in the default group for new users (you can turn this off).
- **User data** updated at every IDer login:
  - the name;
  - if the core plugin *User - Profile* is enabled, also address, phone, date of birth and website.
- **Security for administrators:** by default, accounts that can access the administrator panel cannot sign in with IDer.
- **Landing pages by scope:** a login from an IDer campaign can lead to a specific page.
- English and Italian.

## Security

- OpenID Connect authorization code flow. `state` and `nonce` are random, valid for 15 minutes and used only once.
- RS256 signature of the `id_token` checked against the IDer JWKS keys, together with issuer, audience, expiry and nonce.
- `userinfo` must return the same subject as the `id_token`.
- HTTPS only, with certificate verification. Redirects only to addresses of the site.
- No tokens, secrets or personal data in the log (`administrator/logs/plg_system_iderlogin.log.php`).
- No external dependencies.

## Installation

1. In the administrator go to **System → Install → Extensions** and upload `plg_system_iderlogin-x.y.z.zip`.
2. Open **System → Plugins → System - IDer Login**. The page shows the two exact addresses of your site:
   - `https://your-site.com/index.php/iderlogin/login`
   - `https://your-site.com/index.php/iderlogin/callback`
3. In the [IDer partner portal](https://partner.ider.com) open **Login Button → Edit**, choose the framework **Website** and enter:
   - the first address as the authentication request URL;
   - the second as the callback URL.
4. Copy **Client Id**, **Client Secret** and **Scope Name** into the plugin, enable it and save.

A link to `…/index.php/iderlogin/login?scope=<campaign>` uses another IDer campaign of the same client instead of the configured Scope Name. That campaign must have the same callback.

## Requirements

- Joomla 5.0 – 6.x.
- PHP 8.1 or later with `curl` and `openssl`.

Tested on Joomla 6.2.0 and 5.4.10 (PHP 8.4) with the IDer production server.

## License

GNU General Public License version 2 or later.
