# Safe AI Change

A WordPress plugin that lets a WooCommerce shop manager change one product short description by writing a request. The plugin asks Claude for a proposal, shows a preview, applies the change, checks the store and rolls back on its own when a check fails.

## How to run it

### Requirements

- WordPress 7.0 or later, with WooCommerce installed and active (WooCommerce 11.2 needs WordPress 7.0).
- PHP 7.4 or later on the server.
- An Anthropic API key that can call the `claude-haiku-5-5` model.
- The site must be able to load its own pages (loopback requests), since the checks visit the store as an anonymous visitor.

It was tested with WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.4 and a block theme, on a local site.

### Install

1. Download `safe-ai-change-main.zip` from https://github.com/DigiHold/safe-ai-change/archive/refs/heads/main.zip (or Code > Download ZIP on the repository page).
2. Install and activate WooCommerce first, because the plugin header declares `Requires Plugins: woocommerce` and WordPress won't activate it without WooCommerce.
3. Go to Plugins > Add New Plugin > Upload Plugin, choose the zip, then install and activate it. WordPress puts it in `wp-content/plugins/safe-ai-change-main/`, and that folder name works.
4. Add your key to `wp-config.php`, above the line that says to stop editing:

```php
define( 'SAC_ANTHROPIC_API_KEY', 'your-anthropic-api-key' );
```

### Sample products

WooCommerce ships sample products in `wp-content/plugins/woocommerce/sample-data/sample_products.csv`. Import that file with the Import button on Products > All Products, or open `wp-admin/edit.php?post_type=product&page=product_importer`. You get products such as Cap (SKU `woo-cap`), Belt (SKU `woo-belt`), Hoodie and Hoodie with Logo, which the examples below use.

### Try it

Open WooCommerce > Safe AI Change, write a request, click Prepare preview, then click Apply or Cancel.

| Request | What you should see |
| --- | --- |
| `Change the short description of Cap to "Classic cotton cap with a curved brim."` | A preview, then APPLIED with every check passing |
| `Set the short description of woo-belt to "Leather belt with a brushed metal buckle."` | The same flow, with the product found by its SKU |
| `Delete the Cap product and set every price in the store to zero.` | REFUSED, with the reason the AI gave |
| `Change the short description of cap to "Classic cotton cap."` | REFUSED, because the name must match exactly, including case |

Every step is written to WooCommerce > Status > Logs under the source `safe-ai-change`.

## What I built

`safe-ai-change.php` holds the plugin header and starts the `Safe_AI_Change` class from `includes/class-safe-ai-change.php`. The page sits under the WooCommerce menu and only users with `manage_woocommerce` can open it. Every form posts to `admin-post.php`, where each handler checks the capability and a nonce before doing anything. The page doesn't use any JavaScript.

Here's what happens with a request:

1. The manager writes a request of 500 characters or fewer. Only that text goes to `claude-haiku-5-5` through `wp_remote_post`, with two strict tools, `update_short_description` (product, new_text) and `refuse` (reason). The request uses `tool_choice` auto and turns off parallel tool use, so the AI makes one tool call at most.
2. The plugin treats the answer as untrusted input. It refuses the proposal when the tool isn't one of the two, when the new text doesn't appear word for word in the request, when the product name or SKU doesn't appear in the request outside the new text, or when `sanitize_text_field()` would change the text. It also refuses a text over 400 characters, a name or SKU that doesn't match exactly one published product, a request that also names another product or variation whose longer name or SKU contains it, and a text that's already the current one.
3. The preview shows the text before and after, with Apply and Cancel. It's kept for 10 minutes in a transient for this user, with a random token that works one time only.
4. On Apply, the plugin refuses if the short description changed since the preview. It runs the store checks first and refuses if one fails. It then saves a snapshot of the product (every property from `get_data()` and every custom meta value) and changes the text with `set_short_description()` followed by `save()`.
5. After the change, it checks that only the short description changed compared with the snapshot. It checks that the product page answers 200 with no redirect and no critical error and shows the new text, and that the home, shop and cart pages answer 200 the same way. With the Store API, it gets a cart, adds the product, reads the checkout and removes the item again. These requests are anonymous, and SSL verification follows the `https_local_ssl_verify` filter.
6. If a check fails or the save throws, it saves the snapshot text back with the product object and compares the product with the snapshot again. Then it runs every check again.
7. The manager sees APPLIED, REFUSED, ROLLED BACK or FAILED, with the list of checks. The logs never contain the API key or the token.

If PHP stops with a fatal error after the save, a guard runs from the WordPress error screen and at shutdown. It saves the snapshot text back and records FAILED, since nothing could be checked. Products are found with `wc_get_products()` and changed with the WooCommerce product object, and the plugin makes no direct database queries.

## Important assumptions

- The short description is plain text, so tags, line breaks, doubled spaces and encoded characters are refused. A description that held HTML is replaced as a whole.
- Product names and SKUs must match exactly, including case, so "cap" doesn't find "Cap".
- Only published products can change, so variations and products in any other status are refused.
- The store checks add the product itself to a cart, or the first purchasable variation in stock for a variable product. Grouped, external, out of stock and priceless products are refused before any change.
- The checkout check calls `GET /wc/store/v1/checkout`, which in WooCommerce 11.2 builds the checkout from the cart without creating an order.
- The theme shows the short description on the product page. If it hides or shortens it, the text check fails and the change is rolled back.
- When the AI service fails or answers with an error, the result is REFUSED and nothing changes.
- Each manager has one preview at a time, and a new request replaces the previous one.

## Known limitations

- If WordPress refuses the restore save without throwing, for example because another plugin blocks it, the result is FAILED. The plugin only uses WooCommerce functions, so it has no way to force the old text back.
- The database check compares what the WooCommerce product object exposes. Fields outside it, such as the post author or taxonomies that WooCommerce doesn't manage, aren't compared.
- An order placed during the checks changes the stock or the sales count, so the database check fails and the change is rolled back.
- If the server kills PHP outright, no code can run. The snapshot then stays in the `sac_last_snapshot` option for a manual restore.
- A page cache or CDN that ignores query strings can serve the old product page and cause a rollback for nothing. The checks add a random `sac_nocache` argument to avoid that.
- The name search returns every product that matches the words, so a common word on a large catalog loads many products.
- It was tested on one local site only, and no automated tests ship with the plugin.
- There's no `readme.txt` yet, so the plugin isn't ready for the WordPress.org directory.

## Next steps for production

- Keep the key out of `wp-config.php` and read it from an environment variable, for example `define( 'SAC_ANTHROPIC_API_KEY', getenv( 'SAC_ANTHROPIC_API_KEY' ) );`. Use a key made only for this plugin, created in its own workspace in the Claude Console, and set a monthly spend limit on that workspace (Workspaces page, then Spend limits; the Default Workspace can't have one).
- Run every change on a staging copy of the store first, and only then on production.
- Add new change types one by one. Each one needs its own tool and rules, plus checks that prove the store still works after that kind of change. A price change, for example, needs a maximum change per request (such as 10 percent) and a Store API check that the cart total matches the new price after the product is added.
