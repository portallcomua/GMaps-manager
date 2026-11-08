# GMaps Client Manager 1.4.0

Client request intake and manager workflow for Google Business Profile services.

## Install and pages
1. Download the repository ZIP from GitHub using Code → Download ZIP and upload it in Plugins → Add New → Upload Plugin.
2. Activate the plugin. Existing installations are migrated automatically.
3. Create a public request page and add `[gmaps_request_form]`.
4. Managers sign in at `/gmaps/`. Add `[gmaps_manager_link]` to a public page to show a separate manager-cabinet button. If the route is unavailable, create a page with slug `gmaps` and add `[gmaps_client_manager]`.
5. Assign trusted WordPress users the **GMaps Manager** role when they need general cabinet access. A matching WordPress account can also view requests addressed to its email. Managers do not connect their Google accounts.

## Google setup
1. In GMaps Manager → Налаштування, enter a Google Places API key. Enable Places API and billing for the Google Cloud project.
2. Enter the administrator OAuth Client ID and Client Secret. Add the displayed Redirect URI to the OAuth client.
3. Connect one central Google account that has access to the relevant Business Profile locations. Enable the Business Profile Account Management and Business Information APIs.

## Request workflow
- A returning customer can enter their email to look up a previously saved Place ID. For a first request, enter the business name and address, a Google Maps URL, or a Place ID.
- The public form stores the customer's email, requested manager email, map result, comments, and optional photos/videos. Camera capture and file selection are supported on compatible devices.
- Existing-map requests appear in the administrator queue. After verifying the map, the administrator can send a Google invitation to the manager as a **Manager**. The invitee must accept it in Google.
- New-map requests are routed to the manager for setup. The plugin does not claim that a profile has been created until Google confirms it.
- Customers use only the public request form; the manager cabinet is separate and requires sign-in.

## Notes
- Google Maps cannot locate a business from an email address alone; the email is used to identify returning requests and contact the customer.
- Use HTTPS. Restrict the Places API key in Google Cloud and back up the WordPress database before testing.
