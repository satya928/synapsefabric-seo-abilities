#!/usr/bin/env bash
# Seeds the wp-env *development* site with posts that have known problems, so every ability has something to find.
# Runs only inside wp-env; never point this at a live site.
set -euo pipefail

WP="npx wp-env run cli wp"
if [ "$($WP post list --post_type=post --name=sfsa-seed-a --format=count)" != "0" ]; then
	echo "Seed content already present."
	exit 0
fi

BASE="WordPress caching speeds up your site by storing rendered pages so the server does not rebuild them on every request. Page caching, object caching and browser caching each help in a different way, and most hosts support at least one of them out of the box."
LONG="$(printf "$BASE %.0s" 1 2 3 4 5 6 7 8 9 10)"

# Near-duplicates (find-duplicates, merge-posts).
$WP post create --post_status=publish --post_name=sfsa-seed-a --post_title="How to speed up WordPress with caching" --post_content="<p>$BASE</p>"
$WP post create --post_status=publish --post_name=sfsa-seed-b --post_title="Speed up WordPress with caching" --post_content="<p>$BASE One more closing sentence.</p>"
# Related pair (suggest-internal-links).
$WP post create --post_status=publish --post_title="Baking sourdough bread at home" --post_content="<p>Mix flour, water and starter, rest the dough overnight, then bake in a hot dutch oven.</p>"
$WP post create --post_status=publish --post_name=sourdough-starter-feeding --post_title="Feeding a sourdough starter" --post_content="<h2>Schedule</h2><p>A healthy sourdough starter doubles in size within hours of feeding. Baking sourdough bread at home works best with a starter fed twice a day.</p>"
# Draft.
$WP post create --post_status=draft --post_title="Choosing a sourdough starter" --post_content="<p>A healthy starter doubles in size within hours of feeding.</p>"
# Long post with: image without alt text, a broken internal link, no subheadings (audit-site).
$WP post create --post_status=publish --post_name=wordpress-hosting-guide --post_title="A Complete Guide to Choosing WordPress Hosting for Your Blog and Business Site" --post_content="<p>$LONG</p><img src=\"/x.jpg\"><p>See our <a href=\"/this-page-does-not-exist/\">old guide</a>.</p>"
# Very thin post.
$WP post create --post_status=publish --post_name=tiny --post_title="Tiny" --post_content="<p>Too short.</p>"

echo "Seeded. Write abilities are OFF: enable them at Settings > SynapseFabric SEO (http://localhost:8888/wp-admin)."
