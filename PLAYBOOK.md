# Playbook: letting Claude maintain the blog

Work on **staging** first. Claude only has the abilities you have switched on, and only the permissions of the WordPress user you connected.

## 0. One-time set-up
1. Connect Claude (see README). Keep **write abilities off**.
2. Run `bin/mcp-smoke-test.sh` to confirm the connection.

## 1. Audit (read-only, safe any time)
> Audit my blog with the SynapseFabric tools. Run `audit-site` and `find-duplicates`. Give me a prioritised plan: what to merge, what to rewrite, what to fix first, and why. Do not change anything.

## 2. Fix the basics (turn on `update-post`)
> Using `audit-site`, find posts with missing or badly sized meta descriptions. For each, read the post with `get-post`, write a 120-155 character description that matches its content and focus keyword, and apply it with `update-post`. Do a `dry_run` on the first three and show me before applying the rest.

> Add descriptive alt text to every image missing it, post by post. Show me the list of changes first.

## 3. Consolidate duplicates (turn on `merge-posts`)
> For each group from `find-duplicates`, tell me which post should be kept and why (traffic-worthy title, longest, most complete, best URL). Wait for my approval, then `merge-posts` with `dry_run` first. After each merge, fix every post listed in `posts_still_linking_to_old_url` with `update-post`.

## 4. Strengthen internal links
> For my 10 most important posts, run `suggest-internal-links`. Apply only the `inline` suggestions where the anchor reads naturally, with `update-post`. Never add more than 3 links to a post at once.

## 5. Grow: new content (turn on `create-draft`)
> Look at my categories and the topics my existing posts cover. Propose 10 articles that fill real gaps and do not overlap existing posts (check with `list-posts` and `find-duplicates` logic). When I pick some, write each in my existing voice and create it with `create-draft`, with a meta description and focus keyword. I will review and publish them myself.

## 6. Routine (weekly/monthly)
> Run the full audit, compare with last time, fix the top five issues, and give me a short report of what changed (use the activity log at Settings > SynapseFabric SEO).

## Rules worth keeping
- Review drafts before publishing; the plugin cannot publish.
- Keep revisions enabled (WordPress default) so any content edit can be restored.
- Merges are reversible: delete the redirect and republish the source.
- Do not let an AI edit many posts in a single step without a dry-run and a sample you have approved.
