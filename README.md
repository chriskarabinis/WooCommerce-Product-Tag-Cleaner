# WooCommerce Product Tag Cleaner

A WooCommerce plugin that bulk-removes product tags from selected products or from all products, with **Undo** support.

## Features

- Adds a **Remove Tags** bulk action to the products list.
- Adds a **Remove all product tags** button on the products admin screen.
- Supports **Undo** after tag removal.
- Undo is available for **30 minutes**.
- Includes permission checks (only users with `edit_products` capability).
- Uses WordPress nonces to protect sensitive actions.

## Installation

1. Upload `woo-product-tag-cleaner.php` to:
	`wp-content/plugins/woo-product-tag-cleaner/`
2. In WordPress Admin, go to **Plugins**.
3. Activate **WooCommerce Product Tag Cleaner**.

## Usage

### 1) Remove tags from selected products

1. Go to **WooCommerce → Products**.
2. Select one or more products.
3. In **Bulk actions**, choose **Remove Tags** and click **Apply**.
4. A success notice will appear with an **Undo** button.

### 2) Remove tags from all products

1. Go to **WooCommerce → Products**.
2. Click **Remove all product tags**.
3. Confirm the action in the prompt.
4. A success notice will appear with an **Undo** button.

### 3) Undo

- **Undo** restores the tags that existed before the last action.
- The undo snapshot is stored temporarily and expires in **30 minutes**.
- Undo can only be used by the same user who performed the action.

## Important Notes

- The plugin only affects the `product_tag` taxonomy.
- It does not delete tag terms from the database; it only removes their relationships with products.
- If no products are selected for the bulk action, a warning notice is shown.

## Requirements

- WordPress
- WooCommerce
- Admin access with `edit_products` capability

## Version

- **1.0.0**

## License

You can define the license you want to use for this plugin here (for example, GPL-2.0-or-later).
