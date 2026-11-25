=== PatchLoom ===
Tags: revisions, ai assistant, custom fields, theme development
Requires PHP: 7.4
Stable tag: 0.9.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Preview AI-assisted content edits and migrate existing PHP templates into ACF and WooCommerce layouts.

== Description ==

PatchLoom by Saleem Iqbal helps administrators review suggested edits before applying them. Supported targets include ordinary page text, supported ACF fields, selected Elementor text controls and regular prices for simple products or variations. After saving an edit, the plugin reads the value back. Undo is available for the most recent applied change for up to one day.

In chat, request Home template ACF fields or a WooCommerce product CSV and template conversion. Select the Home page and source PHP files. Approve the inventory before importing products and inspect each proposed file before applying changes.

For heading-based Elementor service copy, ask chat to replace Lorem Ipsum, select the published custom post type, and prepare the next preview. Each approval covers up to eight recognized placeholder paragraphs on one post. Continue until the scan completes.

== Installation ==

1. Install and activate PatchLoom.
2. Open PatchLoom in the dashboard. For AI revisions, enter your own Arena API key and select Test Arena connection.
3. Enter a revision, review the proposed change and choose Apply changes.
4. For template migrations, choose source files in the chat approval panel.

== Private release updates ==

On your public GitHub repository, publish a release tagged v0.6.0 and attach the installable patchloom.zip. In PatchLoom > Updates, save the exact owner/repository. For each subsequent release, increase the plugin header version and use the matching tag and ZIP asset. WordPress handles installation and automatic updates. A configured installation contacts the GitHub releases API to check the version and downloads updates from GitHub. For a WordPress.org release, remove this private distribution channel and use the directory update service.

== External services ==

AI functions use the Arena API at https://api.arena.ai/v1/chat/completions. The administrator supplies a key, which is stored in the site's options database and sent to Arena as a bearer credential. Sending a chat message sends the message and up to eight recent exchanges to Arena. Clear chat removes the locally stored conversation. The connection test sends a small test prompt. Preview sends the administrator's instructions, editable record titles and slugs (including drafts), and relevant text or supported fields from selected content. Plugin dependency checks can also send installed plugin identifiers. Requests are sent when the administrator uses the relevant AI action. Arena account access and credits may be required. Approved template migrations send selected PHP files and product references to Arena.

Service: https://platform.arena.ai/
Terms: https://platform.arena.ai/terms
Privacy: https://platform.arena.ai/privacy

== Frequently Asked Questions ==

= Where are generated field definitions stored? =

Generated field groups are imported into ACF and saved in the active theme acf-json directory.

== Changelog ==

= 0.7.9 =
* Rank similar link destinations using words and spelling, and show candidates for review.

= 0.7.8 =
* Match common published page aliases in theme links and flat header menus.

= 0.7.7 =
* Recognize additional Latin filler words in service widget paragraphs.

= 0.7.5 =
* Preview supported placeholder text in custom Elementor text_ed widget fields.

= 0.7.2 =
* Answer theme clarification questions in chat and review the resulting preview before writing.

= 0.7.1 =
* Route single homepage product-section edits to the selected template with published product context.

= 0.7.0 =
* Responsive chat, asynchronous actions, task-specific progress, intent routing and downloadable CSV previews with source quality notes.

= 0.5.1 =
* Both chat buttons route template edits to theme file approval before page-text planning.

= 0.5.0 =
* Convert a supported flat header list to an editable native WordPress menu from chat.

= 0.4.3 =
* Keep follow-up messages on the current approval and show pending file actions before the composer.

= 0.4.2 =
* Match full menu labels and convert existing generated ID links to verified page paths.

= 0.4.1 =
* Match simple .html anchor paths in PHP template markup to published page slugs and show a local preview.

= 0.4.0 =
* Request a local theme-file read or approve sending one file to Arena for a proposed change. Each write requires a separate confirmation.

= 0.3.4 =
* Chat can inspect active theme files and count stored .html links locally.

= 0.3.3 =
* Accept plain-text chat replies and report Arena diagnostic codes.

= 0.3.2 =
* Fixed submenu registration and added per-admin chat history and a conversational assistant.

= 0.3.1 =
* Introduced the PatchLoom name and directory packaging.

= Services migration (0.8.0) =
Ask for "Services migration" in chat. Select the existing listing page, listing and detail PHP templates, and an existing public custom post type or Create service. Approve sending the two files to Arena. Review proposed drafts and both template changes before applying. A newly created service type is registered by PatchLoom while active, without an archive. Publish reviewed drafts individually. Additional mapped text sections use post meta, with an editable local field group when ACF is active.

= 0.9.0 =
Ask for "WPBakery page import". Enter the public source site or subdirectory, select pages discovered from sitemaps and same-site links and choose a first draft. Review the destination theme layout before approving remaining drafts. Source text is extracted locally. Arena can choose section arrangements and respond to layout feedback. Supports readable public HTML, standard image elements and native details/summary FAQs. Images are downloaded locally. JavaScript-rendered content, interactive forms, video embeds and background images need manual review. Discovery supports up to 200 pages in a run. WPBakery and PHP DOM are required.
