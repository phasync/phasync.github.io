# The site model

`php build.php` writes `build/model.json` before it renders (`lib/Model.php` makes it). Stage-2 templates and the search index read this file; they do not recompute anything. Ordered lists are in reading order.

```
projects.<phasync|swerve>
  title, url                 the project's Manual page: /topics/<project>/ (proposed; no page is written for it yet)
  getStarted[]               {title, url, summary}: the pages under /get-started/ (shared by both projects)
  manual[]                   the topics, by `order`
    slug, project, title, summary, order, status (draft|stable), url (/topics/<project>/<slug>/)
    introduction             HTML: the body of content/<project>/topics/<slug>.md
    concepts[], guides[]     {title, url, summary}: prose pages with `topic:` and `role:` in the front matter
    symbols[]                {name, full, kind, url, summary, topic, members[]?}: classes, interfaces, traits, enums,
                             functions, and the methods of a facade class (kind "method"). `members` are all the methods
                             of the class, except for a facade class, where they are the methods of this topic only.
    examples[]               every example of the topic's symbols and members, an example plus {symbol, symbolUrl}
  index
    symbols[], members[]     every symbol A to Z, then every method A to Z: {name, full, kind, url, summary, topic}

symbols.<full name>          `phasync\Util\WaitGroup`, `Swerve\Swerve::publish`, `phasync\sleep`
  name, kind, url, package, project, summary
  topic, topicSource         slug; "source" (@topic) or "map" (topics-map.php); null when none
  since, deprecated, changed[{version,text}], notes[], warnings[]       markdown
  examples[]                 numbered, in order: @example files, then fenced php blocks of the docblock
                             {n, kind: file|inline, path, caption, code, output, exit, ok, timeout, seconds}
                             output/exit/ok are null for inline blocks (they are shown, not run) and when run with --skip-examples
  breadcrumbs[]              {title, url}: Home, project, topic, (class)
  see_also[]                 at most 10: {name, url, desc, source, code}
                             source is "" (explicit @see or a back-reference), a see-also group's title, or "In the guides"

pages.<url>                  every prose page (symbol pages are in `symbols`)
  title, breadcrumbs[], prev, next ({title,url} or null: within the topic's reading order, topic page, concepts, guides),
  project, topic (null for a page outside the Manual), see_also[] (the page's own `see_also` front matter)
```

A symbol with no topic has `topic: null` and a breadcrumb trail without one; lint reports it. URLs are root-relative and are the ones `build.php` writes, except the `/topics/...` URLs, which stage 2 must render (a Manual page per project, a page per topic: introduction, concepts, guides, symbols, examples).
