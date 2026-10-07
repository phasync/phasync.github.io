# How the build works

`php build.php` runs these stages. Anything inconsistent fails the build; `--lint` lists the rest.

1. **Symbols** (`lib/Symbols.php`): reflection and docblocks of the packages in `symbols.php`. Tags: DOCBLOCK-STANDARD.md.
2. **Pages**: `content/**.md`, except `content/<project>/topics/` (those are topic definitions). Front matter of prose pages: `title`, `description`, `status`, `order`, `see_also`, and for the Manual `project: phasync|swerve`, `topic: slug`, `role: concept|guide`.
3. **Model** (`lib/Model.php`): topics, the Manual, the Index, breadcrumbs, previous/next, See also, examples. Written to `build/model.json` (shape: MODEL.md).
4. **Render** (stage 1: the old templates, which ignore the model), links check, `public/`.

## Files

| File | What |
| --- | --- |
| `content/<project>/topics/<slug>.md` | A topic: front matter `title`, `summary`, `order`, `status`; the body is its Introduction. Projects: `phasync` (includes http-client), `swerve`. |
| `topics-map.php` | `'Symbol' => 'slug'`, `'phasync::go' => 'coroutines'`: the topic of a symbol whose docblock has no `@topic`. Names as in `@see`. Delete the line when the source is tagged. |
| `see-also-groups.php` | `'slug' => ['title' => ..., 'members' => [...]]`. At most 8 members; a symbol in at most 2 groups; a group made only of one class's methods is an error. |

## A symbol's topic

`@topic slug` in the source, else `topics-map.php`, else none (lint error `unmapped`). Lint lists the symbols served only by the map as `untagged`: those are what remains to tag in the source. A method of a facade class (`phasync`, `Swerve\Swerve`) is assigned on its own; any other method has its class's topic. The slug must be a topic of the symbol's project (`unknown @topic`).

## See also

Per symbol, in this order, without duplicates, at most 10: its own `@see` lines (author's order, annotation as the description), symbols whose `@see` names it (not when it has `@see none`), the other members of its see-also groups (source = the group's title), prose pages that link it with `[[Symbol]]` or `{@see Symbol}` (source "In the guides"). A description is the annotation, else the target's summary.

## Examples

Every `@example path caption` file is run once, from the package root, as `php -d display_errors=1 path` with the docs repo's `vendor/autoload.php` prepended, 20 s timeout, stdout and stderr together; 8 at a time. Output, exit code and `ok` (exit 0, no timeout) go into the model. A failure or timeout is a lint error with the first lines of output. Fenced `php` blocks in docblocks are listed as inline examples and are not run. `--skip-examples` skips running (fast builds). The example files in the packages are never changed.

## Lint

`php build.php --lint` prints, per package and for `site` (topics, map, groups, pages): the problems and counts, then a summary. Exit code 1 when anything is wrong, except "no example" (advisory: hundreds, counted only). `--lint --json` prints `{summary, problems[]}`.

## What stage 2 renders from model.json

The Manual page of each project and a page per topic (`/topics/<project>/<slug>/`: introduction, concept pages, guides, symbols with members, the topic's examples); the Index (symbols, members); on every symbol page: breadcrumbs, topic, Changelog (`since` and `changed`), Notes (`notes`, `warnings`), numbered examples with captions and captured output, See also with its source label; on every prose page: breadcrumbs, previous/next, See also; the search index from `symbols` and `pages`.

## Sidebars, width, landing pages

The sidebar is the current sub-section's own tree (`projects.<p>.sidebars` in the model; MODEL.md). Get started: the numbered path, in front-matter `order`; the sub-navigation's "Get started" links to its first page (`Render::startUrl`). Manual: the topic books, drawn by `Render::sidebar`. Index (symbols, examples): All symbols, Classes, Functions (`/<p>/symbols/classes/`, `/functions/`: the same index, filtered by kind), Examples, and an A to Z row linking to the letter anchors. Changelog: Releases (one per `## ` heading) and the pages Upgrading, Planned changes, Development process, each only when its source exists in the package repo.

Width and breakpoints live in the kit's `site.css` (`--maxw` 1560px content; prose 72ch; rail hidden below 1200px). Landing pages and the 404 have no breadcrumb: `Render::crumbs` returns nothing for a trail of fewer than two items.
