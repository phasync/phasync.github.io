# phasync.github.io

The documentation site for phasync and Swerve: a static site built by `build.php`, published by GitHub Pages.

```bash
composer install
php build.php && php -S localhost:8000 -t public
```

Open http://localhost:8000. To see what a missing path does on GitHub Pages (the `/swerve/publish` lookup in `404.html`), serve with the router: `php -S localhost:8000 -t public dev-router.php`.

`composer install` takes phasync and swerve from Packagist, as require-dev: the reference pages are read from their source by reflection, so a signature on the site is the signature in the code. To build against your checkouts instead, use `composer.local.json` (path repositories to `../phasync` and `../swerve`): `COMPOSER=composer.local.json composer install`.

## Layout

| Path | What |
| --- | --- |
| `content/` | The pages: markdown with front matter. See [CONTENT-GUIDE.md](CONTENT-GUIDE.md). |
| `symbols.php` | The classes that have reference pages. |
| `lib/` | `Symbols` (reflection and docblocks), `Markdown`. |
| `templates/` | Plain PHP templates. |
| `theme/` | CSS (Pico v2 and our tokens), fonts, JS, `kitchen-sink.html` with every component. |
| `build.php` | Writes `public/`. |

The build fails on a broken internal link, a `see_also` that does not exist, two pages with one URL, and two classes with the same short name. It ends with the number of pages built, placeholder pages, reference pages without examples, and unresolved `TODO-verify` and `TODO-measure` markers.

Theme: add `?theme=dark` or `?theme=light` to any URL to force a theme. The logo is a text wordmark in `templates/layout.php`, in the block marked `LOGO SLOT`.
