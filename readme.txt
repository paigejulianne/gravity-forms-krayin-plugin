=== Gravity Forms Krayin CRM Add-On ===
Contributors: paigejulianne
Tags: gravity forms, krayin, crm
Requires at least: 6.0
Requires PHP: 7.4
Tested up to: 6.6
Stable tag: 1.0.0
License: GPLv2 or later

Sends Gravity Forms submissions to Krayin CRM as Leads or Contacts.

== Description ==

This add-on connects Gravity Forms to a self-hosted Krayin CRM installation
using Krayin's official REST API (the `krayin/rest-api` package). For any
form, you can add a "Krayin CRM" feed that maps form fields to a Krayin
Person, and optionally creates a Lead for that Person in one of your Krayin
pipelines.

Each submission always creates a new Person record in Krayin (the API has no
built-in "find or update" by email); use Krayin's own duplicate-contact tools
if the same person submits more than once.

== Installation ==

1. Make sure Gravity Forms is installed and activated.
2. Upload/activate this plugin.
3. In your WordPress admin, go to Forms > Settings > Krayin CRM and enter:
   - Krayin Base URL (e.g. https://crm.devinsight.site)
   - The email and password of a Krayin user this add-on should authenticate
     as. A dedicated "integration" user is strongly recommended: every time
     the add-on logs in again (e.g. after its token is revoked), Krayin
     invalidates all of that user's other active API tokens.
   - Settings are validated against Krayin immediately when you click "Update
     Settings" - a connection error will be shown on the password field if
     the credentials or URL are wrong.
4. Open any form, go to Settings > Krayin CRM, and add a feed:
   - Choose whether to send the submission as a Lead (with its Person) or
     as a standalone Person/Contact.
   - Map the Full Name, Email, Phone, and Company fields from your form.
   - For Leads, optionally map a Title/Description/Value, and choose the
     Lead Source and Lead Type (loaded live from your Krayin instance).
   - Optionally add conditional logic to only send the feed under certain
     conditions.

== Notes ==

- Feed processing runs asynchronously (via Gravity Forms' background
  processing), so a slow or unreachable Krayin server won't delay the
  visitor's form submission response.
- Successes and failures are logged as notes on the Gravity Forms entry.
- If a "Company / Organization" field is mapped, a new Organization record
  is created in Krayin for every submission (no de-duplication).
