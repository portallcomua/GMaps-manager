# GMaps Client Manager 1.1.0

WordPress CRM for Google Business Profile service managers.

## Install
1. Upload `gmaps-client-manager.zip` via Plugins → Add New → Upload Plugin.
2. Activate.
3. In Users, assign trusted users the **GMaps Manager** role.
4. Open `https://example.com/gmaps/` while logged in as a manager.
5. If rewrite routing is unavailable, add shortcode `[gmaps_client_manager]` to a page with slug `gmaps`.
6. Add `[gmaps_manager_link]` to any frontend page to show a separate manager-cabinet button.

## Client orders and media
Create an order from the manager cabinet. Photos and videos can be captured with a mobile device or selected from its files. Uploaded media is stored in the WordPress Media Library and linked to the order. The plugin accepts JPG, PNG, WebP, MP4, WebM and MOV files; the server's WordPress upload limit applies, with up to 8 files per order.

## Google OAuth
Set Google OAuth Client ID and Client Secret in GMaps Manager → Налаштування.
Add the displayed Redirect URI to the OAuth client in Google Cloud.
Enable the Google Business Profile API and configure the OAuth consent screen.
The plugin requests `business.manage`; it does not ask for a customer's Google password.
This first release stores manager OAuth tokens; full business account/location listing and publishing operations are not yet implemented.

## Payments
Managers create orders. An administrator records each incoming partial payment and separately confirms that funds were actually received. Commission is calculated from each payment using the rate snapshotted on the order. Only confirmed payments count toward earned commission. Admin records payouts against available confirmed commission.

## Important
- This release is a first testable CRM foundation, not a completed Google Business Profile publishing/synchronization suite.
- Use HTTPS. Restrict manager accounts to trusted users.
- Back up the site database before testing.
