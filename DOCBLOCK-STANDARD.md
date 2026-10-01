# Docblock standard

The reference on phasync.github.io is generated from the docblocks of the public API. This is the format.

## Format

CommonMark. The first paragraph is the summary: one sentence ending with a period. A blank line, then the long description, which may use markdown and fenced `php` blocks. Inline `{@see Symbol}` links to a symbol.

## Tags

| Tag | Meaning |
| --- | --- |
| `@param type $name description` | Names and order must match the signature. The type shown is the reflected one. |
| `@return type description` | What comes back, when the type does not say it. |
| `@throws Class when thrown` | One line per exception. |
| `@see Symbol` | One per line: `Swerve::subscribe`, `Swerve\OrderedChannel`, `phasync::run`. Becomes the See also list, in this order. An unknown symbol fails the build. |
| `@since version` | First release that has it. |
| `@deprecated reason` | Say what to use instead. |
| `@example path/to/file.php [caption]` | Path relative to the package root. The file is expected to run on its own; CI will run them. |
| `@internal` | Left out of the reference, entirely. |

`@since`, `@deprecated` and `@example path [caption]` go last. A class docblock has a description, `@see` and `@example`; its methods have their own docblocks. A method with no docblock gets the one of the method it implements or overrides.

## Rules

- Every public class, interface, trait, enum, method, function and constant that is not `@internal` has a summary.
- No marketing words. State what it does.
- Say what is returned, and what is thrown and when.
- Say what would surprise a reader: ordering, delivery guarantees, blocking, what happens in the other process.
- Every class, and every method that is not trivially obvious, has an `@example` or a fenced `php` block.
- `@see` lists the sibling, the inverse and the nearest alternative.

`php build.php --lint` in phasync.github.io reports what breaks these rules, per package.

## A complete docblock

```php
/**
 * Send a message to every subscriber of `$topic`, in every worker process of this swerve.
 *
 * Returns once the message is on its way, not once delivered. Delivery is at most once, to the
 * subscriptions that exist when the message reaches their worker: there is no history, and a
 * worker that starts later sees nothing sent before. The messages of one publishing worker
 * arrive in the order it published them, with no order between workers.
 *
 * Every message travels as JSON, and every subscriber gets the value published: a string stays
 * a string, a list an array, and an object (an array with keys) a read-only `SealedObject`.
 * Without its master process, Swerve delivers in this process only.
 *
 * ```php
 * Swerve::publish('game', ['kill', $playerId]);
 * ```
 *
 * @param string $topic   1 to 255 bytes
 * @param mixed  $message anything `json_encode()` takes except null; at most 128 KiB encoded
 *
 * @throws \InvalidArgumentException for a topic or message outside those sizes, a topic starting with "\0", or null
 * @throws \LogicException           while the application loads: the worker serves after that
 * @throws \JsonException            for a value JSON cannot express
 *
 * @see Swerve::subscribe    receives the messages
 * @see Swerve\OrderedChannel  when order between workers matters
 */
public static function publish(string $topic, mixed $message): void
```
