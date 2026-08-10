=== SEO Landing Pages (Item x City) ===
Tags: seo, local seo, landing pages, custom post type
Requires at least: 5.9
Tested up to: 6.6
Requires PHP: 7.4
License: GPLv2 or later

== Description ==

Creates one SEO-targeted landing page per city for an item or service you buy/sell
(e.g. "Vintage Silver Buyer in Las Vegas", "Vintage Silver Buyer in Henderson"),
so each city has its own page that can rank for its own local search.

== Installation ==

1. Zip the "vintage-seo-pages" folder (or upload it as-is) to /wp-content/plugins/
2. Activate "SEO Landing Pages (Item x City)" in Plugins.
3. Go to Settings > Permalinks and click "Save Changes" once (this activates the
   pretty URLs for the new page type).
4. Go to SEO Landing Pages > Settings to set your business name, phone number,
   URL structure, and default title / meta description / content templates.
   Use {item} {city} {state} {subcity} {phone} {sitename} anywhere in those
   fields.

   The URL structure is fully configurable:
     - "URL Base Slug" is the first path segment (e.g. "locations").
     - "City Page URL Pattern" controls the rest of the URL for each city
       page, e.g. {item}-{state}-{city} gives
       /locations/vintage-silver-nv-las-vegas/
     - "Sub-City URL Pattern" controls the final segment for a sub-city page,
       which nests under its parent city page automatically, e.g.
       /locations/vintage-silver-nv-las-vegas/summerlin/

5. Go to SEO Landing Pages > Bulk Generate. Enter your item name (e.g.
   "Vintage Silver") and paste your city list, one per line, e.g.:

     Las Vegas, NV
     Henderson, NV
     Reno, NV

   Click "Generate Pages". Each page is created as a Draft by default so you
   can review before publishing (or choose "Publish immediately").

6. Need neighborhood-level pages under a city (e.g. "Summerlin", "Henderson
   East")? Go to SEO Landing Pages > Bulk Generate Sub-Cities, pick the city
   page as the parent, and paste your list of neighborhoods, one per line.
   Sub-city pages inherit the item/city/state/phone from their parent city
   page automatically, and their URL nests underneath it.

7. Open any generated page in the editor if you want to customize its content
   beyond the default template — just leave the editor empty to keep using
   the auto-generated template, or type your own content (placeholders like
   {city} still work inside your own text). A page's parent city (for
   sub-city pages) can be changed any time under Page Attributes in the
   editor sidebar.

IMPORTANT: If you change the URL Base Slug or either URL pattern in Settings,
go to Settings > Permalinks and click "Save Changes" once afterwards so
WordPress regenerates its rewrite rules.

== Internal linking / SEO hub page ==

Create a normal WordPress page (e.g. "Cities We Serve") and add the shortcode:

  [sslp_directory item="Vintage Silver"]

This lists links to every published city page for that item (with any of its
sub-city pages nested underneath), which helps search engines discover and
index them, and passes link authority to them. Leave off the "item"
attribute to list everything, grouped by item.

== Notes ==

* If you already run Yoast SEO or RankMath, turn off "Schema Markup" in
  SEO Landing Pages > Settings to avoid duplicate structured data — those
  plugins can also be used instead of this plugin's Title/Description
  fields if you prefer, though this plugin's per-page overrides work fine
  alongside them for the CPT.
* Works with the default WordPress/Gutenberg editor. Page builders (Elementor,
  Divi, etc.) can be used to design page content, but the automatic
  title/meta-description/schema output still applies at the page level.
* Sub-city pages are just regular landing pages that have a parent city page
  set, so anything you can do to a normal page (custom content, featured
  image, per-page title/description overrides) also works on a sub-city page.

== Changelog ==

= 1.1.0 =
* URL structure is now configurable: separate "City Page URL Pattern" and
  "Sub-City URL Pattern" settings replace the old fixed item-city-state slug.
* Added support for sub-city / neighborhood pages, nested under a parent
  city page (post type is now hierarchical).
* Added SEO Landing Pages > Bulk Generate Sub-Cities.
* [sslp_directory] now nests sub-city pages under their parent city.
* Sub-city pages inherit item/city/state/phone from their parent when left
  blank, and the front-end template shows a breadcrumb back to the parent.

= 1.0.0 =
* Initial release.
