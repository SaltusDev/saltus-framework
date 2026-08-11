# Field Security

Saltus gates access at the surface boundary: a model decides whether REST, MCP,
WP-CLI, and WebMCP can reach a capability at all, and each surface checks a
WordPress capability before it writes. Neither gates an *individual field*.

Two per-field declarations close that gap. Both are opt-in, and a field that
declares neither behaves exactly as it did before.

## Field permissions

Declare `permissions` on a field to require a capability for reading or writing
it:

```php
'meta' => [
    'employment' => [
        'register_rest_api' => true,
        'fields'            => [
            'job_title' => [
                'type'  => 'text',
                'title' => 'Job Title',
            ],
            'salary' => [
                'type'        => 'number',
                'title'       => 'Salary',
                'permissions' => [
                    'read'  => [ 'manage_options' ],
                    'write' => [ 'manage_options' ],
                ],
            ],
        ],
    ],
],
```

`read` and `write` resolve independently, so a field can be visible but not
editable. Each takes a list of capabilities and grants on **any** of them — a
single string works too.

One policy resolves this for all four surfaces, so a denied field is denied
everywhere:

| Surface | Read | Write |
|---|---|---|
| REST (`/meta/…`) | Absent from the normalized field list | `403 rest_field_forbidden` |
| MCP (`get_meta_fields`, `list_meta_fields`) | Absent from the field list | `403 rest_field_forbidden` |
| WP-CLI (`wp saltus meta update`) | — | Refused with the same error |
| WebMCP | Never exposed | Writes already route through the review queue |

A field with no `permissions` key is unaffected, regardless of the caller's
capabilities. This is deliberately the opposite of a deny-by-default rule:
adding this feature must not change what an existing site exposes.

### Nested fields inherit

A rule on a parent governs everything under it. Denying `compensation.package`
also denies `compensation.package.base`, because otherwise a caller could read
the parts and reconstruct the parent.

### Serialized metaboxes deny by key

A serialized metabox stores many fields under one meta key. A write to that key
cannot honor a rule on one of its parts, so if any field under the key is denied
for writing, the whole key is refused. Reads still filter per field.

## Encryption at rest

Declare `encrypted` to store a field as ciphertext:

```php
'ssn' => [
    'type'      => 'text',
    'title'     => 'SSN',
    'encrypted' => true,
],
```

### Setting a key

Encryption needs a key, and Saltus will refuse to write an encrypted field
without one rather than silently storing plaintext. Generate one:

```php
Saltus\WP\Framework\Features\Meta\FieldEncryptionKeys::generate();
```

Add it to `wp-config.php`:

```php
define( 'SALTUS_FIELD_ENCRYPTION_KEY', 'base64-key-here' );
```

Or resolve it from a secrets store:

```php
add_filter( 'saltus/framework/field_encryption_key', function () {
    return my_kms_client()->get_secret( 'saltus-field-key' );
} );
```

The key never goes in the database. A key stored beside the ciphertext it
protects is not encryption. Base64, hex, and raw 32-byte strings are all
accepted; a wrong-length key is refused rather than padded.

### What encryption costs

**An encrypted field cannot be queried, sorted, or filtered.** Ciphertext does
not compare. A call that references one is rejected with `field_not_queryable`
rather than silently returning nothing:

```
orderby=ssn      → 400 field_not_queryable
meta_key=ssn     → 400 field_not_queryable
meta_query key   → 400 field_not_queryable
```

`search` is unaffected — WordPress searches post title and content, not meta.

**An encrypted field is never publicly readable.** It is excluded from WebMCP's
public surface even if a filter tries to add it back. Declaring a value sensitive
enough to encrypt is incompatible with handing it to an anonymous caller.

Reads through authenticated surfaces return plaintext, and responses report what
you set rather than the stored envelope.

### Changing or losing a key

Values are authenticated: a wrong key fails to decrypt rather than returning
garbage. There is no recovery for a lost key — the values are unreadable. Rotate
by decrypting with the old key and re-encrypting with the new one before
switching; Saltus ships no rotation tooling.

A field switched to `encrypted: true` after it already held plaintext keeps
working. Existing values read through unchanged and are encrypted on next write.

## Privacy requests

Saltus registers a WordPress core exporter and eraser, so model meta is included
in the built-in data export and erasure workflow under **Tools → Export Personal
Data** and **Erase Personal Data**. No configuration is needed.

Export includes encrypted fields **as plaintext**. A data subject request asks
what the site holds about a person; ciphertext answers nothing. Encryption
protects the value at rest, not from its subject.

Erasure removes model meta and leaves the posts themselves, matching how core's
own erasers anonymize rather than delete. It follows `cascade_delete`
relationships, since a dependent post that exists only to serve an erased one
would otherwise keep the subject's data on an orphan.

## Auditing

A field denial is recorded with its own audit status, `field_denied`, separate
from the generic `error` used for capability failures. The two have different
fixes — one is a role, the other is model config — and the log should say which.

Field denials do not count toward the health endpoint's error rate. A working
security rule is not an outage. They remain visible in the status breakdown.

## Not covered

- Row-level (per-post) permissions beyond what WordPress capabilities give
- Key rotation tooling
- Whole-table encryption
- Compliance certification of any kind
