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

A store can add the method to several shipping zones, each with its own token and rules, so
settings only mean something next to a destination. Read them with
`Cdfrete_Shipping_Method::get_settings_for_destination()`, or `get_all_settings()` where there is
genuinely no destination. Never pick an instance yourself.

## Maintaining this file

Keep this file for knowledge useful to almost every future agent session in this project.
Do not repeat what the codebase already shows; point to the authoritative file or command instead.
Prefer rewriting or pruning existing entries over appending new ones.
When updating this file, preserve this bar for all agents and keep entries concise.
