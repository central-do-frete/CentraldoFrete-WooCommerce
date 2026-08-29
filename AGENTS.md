# Project agent memory

This file is the project's committed home for project-intrinsic agent knowledge: build, test, release, architecture, and sharp-edge notes that should travel with the code.

- Add durable project-specific notes here as they are discovered through real work.

## Tests

`composer test` (phpunit 9). The tests never load WordPress or WooCommerce: `tests/bootstrap.php`
pulls in the files under test plus `tests/wp-stubs.php`, which holds the handful of WordPress
symbols they touch and a bare `WC_Shipping_Method` that exists only so the shipping method file
can be loaded.
This is why anything worth testing is extracted as a static free of WordPress calls, next to the
method that uses it - see the note at the top of `includes/class-cdfrete-shipping-class-rule.php`.

## Release

The plugin ships through Subversion at `https://plugins.svn.wordpress.org/central-do-frete/`,
not through GitHub. The public page renders `tags/<Stable tag>/readme.txt`; trunk is read only
for its `Stable tag` line, so a readme fix has to land in the tag directory too - editing trunk
alone changes nothing. The directory picks changes up within minutes.
A release is: bump the version in `woo-central-do-frete.php` (header and `CDFRETE_VERSION`) and
`Stable tag` in `readme.txt`, copy the distributed files into `trunk`, commit, then
`svn cp ^/trunk ^/tags/<version>`. `.gitattributes` lists what is development-only and stays out.

## Sharp edges

The API validates `from` as an eight character string and falls back to the account's pickup
address only when the key is absent, so an origin that is not set must be left out of the
request body rather than sent empty (`Cdfrete_API_Client::build_quotation_payload`).
`Cdfrete_Shipping_Method::classify_origin()` is what makes that hold: it is the only door the
store postcode comes through, and it returns eight digits or nothing, so a malformed postcode
never reaches the request. It also tells the settings screen which of the two problems to
report, because "not filled in" and "filled in wrong" have different fixes.

A store can add the method to several shipping zones, each with its own token and rules, so
settings only mean something next to a destination. Read them with
`Cdfrete_Shipping_Method::get_settings_for_destination()`, or `get_all_settings()` where there is
genuinely no destination. Never pick an instance yourself.
Use `resolve_for_destination()` instead wherever "no zone matched" and "a zone matched but was
never saved" have to be told apart: the settings-only accessor returns an empty array for both.
Both carry the product page's preference, which is not a neutral one: where a zone holds the
method twice they prefer an entry that will answer the product page, so an entry with the
calculator switched off loses to a sibling without it. Any other caller - a checkout path, an
admin preview - has its own preference to state and must not inherit that one.
A per-zone setting governs what that zone does, not just what the store draws: the product page
calculator renders when any zone offers it, and then the zone the shopper's postcode falls into
decides whether it answers. Anything keyed per account (cached prices, the resolved origin map)
is likewise keyed by `Cdfrete_API_Client::account_scope()`, never by a single store-wide value.

Every sentence telling a shopper this store will not price their postcode leaves through
`Cdfrete_Frontend_Calculator::refuse()`, and it takes a coverage verdict with no default:
a message about the region is only released to a caller that read the setting withholding the
quote. Write new refusals there rather than in the AJAX handler. The handler still answers four
cases directly - a postcode that is not eight digits, a product that does not exist or is
unpublished, a failed request, and a postcode the service itself returned no carrier for -
because each states what happened rather than what the store serves; the last is a claim only
because the service was asked and answered nothing. Do not read the single site as a sweep of
every `wp_send_json_error` in the file.

The zone a destination falls into is matched with a state, and a stateless destination silently
falls through to the next zone by order. `Cdfrete_Shipping_Method::state_for_postcode()` derives
it from the postcode itself, so the calculator resolves the same zone the checkout will. Its
range table is cited and probe-verified in the docblock: extend it only the same way, because a
wrong entry is a wrong zone with nothing to show for it, and an uncovered postcode already falls
back to `stateless_pick()` refusing.

## Maintaining this file

Keep this file for knowledge useful to almost every future agent session in this project.
Do not repeat what the codebase already shows; point to the authoritative file or command instead.
Prefer rewriting or pruning existing entries over appending new ones.
When updating this file, preserve this bar for all agents and keep entries concise.
