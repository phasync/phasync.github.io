# phasync.github.io

The documentation site for phasync and Swerve: a static site built by `build.php`, published by GitHub Pages.

```bash
composer install
php build.php && php -S localhost:8000 -t public
```

Open http://localhost:8000. To see what a missing path does on GitHub Pages (the `/swerve/publish` lookup in `404.html`), serve with the router: `php -S localhost:8000 -t public dev-router.php`.

`composer install` takes phasync and swerve from Packagist, as require-dev: the reference pages are generated from their source docblocks and reflection, so a signature on the site is the signature in the code. The format of the docblocks is in [DOCBLOCK-STANDARD.md](DOCBLOCK-STANDARD.md); `content/reference/` is a legacy hand-written overlay that is being migrated into them. To build against your checkouts instead, use `composer.local.json` (path repositories to `../phasync` and `../swerve`): `COMPOSER=composer.local.json composer install`.

## Layout

| Path | What |
| --- | --- |
| `content/` | The pages: markdown with front matter. See [CONTENT-GUIDE.md](CONTENT-GUIDE.md). |
| `symbols.php` | The packages whose source docblocks make the reference. |
| `lib/` | `Symbols` (reflection and docblocks), `Markdown`. |
| `templates/` | Plain PHP templates. |
| `theme/` | CSS (Pico v2 and our tokens), fonts, JS, `kitchen-sink.html` with every component. |
| `build.php` | Writes `public/`. |

The build fails on a broken internal link, a `see_also` that does not exist, two pages with one URL, and two classes with the same short name. `php build.php --lint` also lists the docblock gaps per package (no summary, `@param` that does not match, no example, `@see` or `@example` that does not resolve); the exit code is 0 unless `--strict`. A normal build ends with the number of pages built, placeholder pages, and reference pages without examples. `php build.php --todo` also lists the working notes (`TODO` comments) in the sources as `file:line`; they are removed from `public/`.

Theme: add `?theme=dark` or `?theme=light` to any URL to force a theme. The logo is a text wordmark in `templates/layout.php`, in the block marked `LOGO SLOT`.
