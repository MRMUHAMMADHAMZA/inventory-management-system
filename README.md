# Inventory Management System (WordPress)

A WordPress plugin for inventory management: products, suppliers, stock in/out, low-stock alerts, front-end account pages with email OTP, role-based access, rate limiting, an authentication log and a REST API. It's designed for the free **Astra** theme.

- **Plugin source:** `wp-content/plugins/inventory-management/`
- **Theme:** Astra (free) from wordpress.org. It isn't included in this repository.

## Setup (about 10 minutes)

1. **Install Local** (free): download it from https://localwp.com, open the `.dmg` and drag Local into Applications.
2. In Local, click **+ → Create a new site**, name it `inventory`, choose **Preferred** settings, and set an admin username and password.
3. Click **WP Admin** to log in to the site.
4. **Theme:** go to Appearance → Themes → Add New, search for **Astra**, then click **Install** and **Activate**.
5. **Plugin:** build the zip from this repository:
   ```bash
   cd wp-content/plugins && zip -r inventory-management.zip inventory-management
   ```
   Then go to Plugins → Add New → Upload Plugin, choose `inventory-management.zip`, and click **Install** and **Activate**. Or copy the `inventory-management` folder into the site's `wp-content/plugins/` folder.
6. An **Inventory** menu now appears in the admin sidebar. The plugin creates its pages (Home, Login, Register, Profile…) and database tables automatically.
7. Optional: add the Resend email settings to `wp-config.php` (see **Email delivery** below).

## Using it

| Screen | What it does |
|---|---|
| Inventory → Dashboard | Totals, stock value, low and out-of-stock counts, the reorder list, recent activity |
| Inventory → Products | Add, edit, delete, search and filter products; export a CSV |
| Inventory → Stock In / Out | Record stock received, sold/used, or a stocktake adjustment. Every change is logged with who made it and when |
| Inventory → Suppliers | Manage suppliers and link them to products |

A red badge on the Inventory menu shows how many products are low or out of stock.

**Show inventory on the website:** create a page (Pages → Add New), add a Shortcode block containing:

```
[ims_inventory]
```

Options: `[ims_inventory public="yes" show_prices="no" category="Electronics"]`
By default only logged-in users can see the list.

## Editing the code

The plugin source is in `wp-content/plugins/inventory-management/`. To work on it live, copy that folder into your Local site's `app/public/wp-content/plugins/` folder. In Local, right-click the site and choose **Reveal in Finder** to find it.

## Account pages

The plugin creates these pages automatically and adds Log in / Register (or My Profile / Log out) to the header menu.

| Page | URL | Notes |
|---|---|---|
| Login | `/login/` | Email address and password. Locks out for 15 minutes after 5 failed attempts. Admins go to the Inventory dashboard; other users go to their profile |
| Register | `/register/` | Name (optional), email and password; no username field (one is generated from the email internally). Password needs at least 8 characters with a letter and a number. The account stays locked until the email is verified |
| Forgot password | `/forgot-password/` | Email address only. Emails a 6-digit code. Shows the same message whether or not the account exists |
| Verify OTP | `/verify-otp/` | Codes expire after 10 minutes, allow 5 attempts, and can be resent after 60 seconds. After a password-reset code is accepted, this page shows the "set new password" form |
| Change password | `/change-password/` | Logged-in users only. Needs the current password and sends a "password changed" email |
| Profile | `/profile/` | Name, display name, email (changing it needs the current password), phone, job title |

**Email delivery (Resend):** all site email, including OTP codes and password notices, is sent through [Resend](https://resend.com) when these lines are in `wp-config.php`. They go above the line `/* That's all, stop editing! */`:

```php
define( 'IMS_RESEND_API_KEY', 're_...' );                 // A Resend API key ("Sending access" is enough)
define( 'IMS_MAIL_FROM', 'info@workbyhamza.com' );       // Must be on a domain verified in Resend
define( 'IMS_MAIL_FROM_NAME', 'Inventory Management System' );
```

Keep the key in `wp-config.php` only. Never put it in the plugin files, which are zipped and shared. If the lines are missing, WordPress sends email the normal way. On Local, that means it goes to **Tools → Mailpit**.

## Roles & permissions

| Role | View stock | Record stock in/out | Products | Suppliers | CSV export | Security log |
|---|---|---|---|---|---|---|
| Administrator | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Inventory Manager | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Inventory Staff | ✓ | ✓ | – | view only | – | – |
| Inventory Viewer | ✓ | – | view only | view only | – | – |
| Subscriber (new sign-ups) | – | – | – | – | – | – |

New accounts get **no inventory access**. To give someone access, go to **Users → Edit user → Role** and pick Inventory Viewer, Staff or Manager. Every admin screen, form action and API endpoint checks these permissions on the server.

## Security

- **Rate limiting** is stored in the database (`wp_ims_rate_limits`):

  | What | Limit |
  |---|---|
  | Failed logins per account | 5 per 15 min, then a 15-minute lock |
  | Failed logins per IP address | 20 per 15 min |
  | Registrations per IP address | 5 per hour |
  | Password-reset requests per IP address | 5 per 15 min |
  | Reset emails per account | 3 per 15 min (extra requests are silently skipped) |
  | Code checks per IP address | 20 per 15 min |
  | Wrong "current password" entries | 5 per 15 min |
  | API requests per user | 120 per minute |

  The login limits also apply to the standard `wp-login.php` page.
- **Authentication log** (`wp_ims_auth_log`): logins, failures, lockouts, logouts, registrations, codes and password changes, with IP address and device. View it under **Inventory → Security log**, where you can also unlock blocked logins. Entries older than 90 days are deleted daily.
- **Remember me:** checked keeps you signed in for 30 days. Unchecked signs you out when the browser closes. Changing your password signs out other devices but keeps the current one signed in. **My profile → Signed-in devices** lists active sessions and has **Log out of all other devices**.
- Behind a reverse proxy or CDN, return the real client IP with the `ims_client_ip` filter. Proxy headers are not trusted by default.

## REST API

Base URL: `/wp-json/ims/v1`. Authenticate with an **Application Password**: **Users → Profile → Application Passwords**. HTTPS is required on a live site.

```bash
curl -u "you@company.com:xxxx xxxx xxxx xxxx xxxx xxxx" https://your-site/wp-json/ims/v1/products
```

| Method & route | Permission | Notes |
|---|---|---|
| `GET /me` | signed in | Your roles and inventory permissions |
| `GET /stats` | view | Totals, stock value, low/out-of-stock counts |
| `GET /products?search=&category=&low_stock=1` | view | |
| `POST /products` | products | `name`, `sku` required; optional `quantity` = opening stock |
| `GET/PATCH/DELETE /products/{id}` | view / products | Quantity can't be changed here; use `/movements` |
| `GET /movements?product_id=&limit=` | view | |
| `POST /movements` | stock | `product_id`, `type` (`in`/`out`/`adjust`), `quantity`, `note` |
| `GET/POST /suppliers`, `GET/PATCH/DELETE /suppliers/{id}` | view / suppliers | |

Responses use standard status codes: `401` not signed in, `403` no permission, `400` invalid input, `404` not found, `409` SKU conflict or not enough stock, `429` too many requests (check the `Retry-After` header). Every response includes `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers.
