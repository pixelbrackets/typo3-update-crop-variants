# TYPO3 Update Crop Variants

[![Version](https://img.shields.io/packagist/v/pixelbrackets/typo3-update-crop-variants.svg?style=flat-square)](https://packagist.org/packages/pixelbrackets/typo3-update-crop-variants/)
[![Made With](https://img.shields.io/badge/made_with-php-blue?style=flat-square)](https://gitlab.com/pixelbrackets/typo3-update-crop-variants#requirements)
[![License](https://img.shields.io/badge/license-gpl--2.0--or--later-blue.svg?style=flat-square)](https://spdx.org/licenses/GPL-2.0-or-later.html)

TYPO3 command to add missing crop variants and optionally regenerate changed ratios in file references.

```
$: vendor/bin/typo3 cleanup:updatecropvariants tt_content --dry-run

Auto-detected 1 field(s) with crop variants: image

=== Field: tt_content.image ===

Processing 8 file reference(s)…

Type: text (3 reference(s))

  * desktop (16:9, 4:3)
  * mobile (1:1)

Type: textmedia (5 reference(s))

  * desktop (16:9, 3:2, free)
  * mobile (3:4, 1:1)
  * thumbnail (1:1)

Would update: 4
Skipped: 4
```

## Requirements

- PHP >= 8.1
- TYPO3 12.4, 13.4, 14.0

## Installation

Packagist Entry https://packagist.org/packages/pixelbrackets/typo3-update-crop-variants/

```bash
composer require pixelbrackets/typo3-update-crop-variants
```

## Usage

TYPO3 crop variants allow editors to define different image crops for different contexts
(e.g., `desktop`, `mobile`).
Each variant offers a list of allowed aspect ratios (e.g., `3:2`, `16:9`, `4:3`, free) to choose from.
The editor picks one ratio per variant in the backend,
and the resulting crop coordinates are stored in the file reference.
See [TCA image manipulation](https://docs.typo3.org/permalink/t3tca:columns-imagemanipulation-introduction)
for configuration details.

When new crop variants are added to TCA, or when aspect ratios are changed, all existing
file references with crops need to be updated by editors. This command automates that process.

By default only missing crop variants are added - existing editor crops are preserved.

Use `--updateRatios` to also update variants where the stored ratio no longer matches the TCA
ratio. Only mismatched crops are overwritten with a centered default - crops that already match
the TCA ratio are preserved.

The command defaults to outputting a summary only. Add `-v` to see per-item details.
Use `--dry-run` to preview changes without writing anything to the database.

```bash
# Scenario: Add new mobile crop variant to a specific field
vendor/bin/typo3 cleanup:updatecropvariants tt_content image

# Auto-detect and update all image fields in a table
vendor/bin/typo3 cleanup:updatecropvariants tt_content

# Scenario: Update desktop variant after changing ratio from 3:2 to 16:9
# Note: variants configured as free ratio in TCA are always skipped
vendor/bin/typo3 cleanup:updatecropvariants tt_content image --updateRatios

# Auto-detect all image fields and update changed ratios
vendor/bin/typo3 cleanup:updatecropvariants tt_content --updateRatios

# Reset all crops to defaults (!), removing any existing crop adjustments
vendor/bin/typo3 cleanup:updatecropvariants tt_content image --forceOverride

# Preview changes without writing, show per-reference details
vendor/bin/typo3 cleanup:updatecropvariants tt_content image --dry-run -v

# Update crops in a third-party extension table
vendor/bin/typo3 cleanup:updatecropvariants tx_news_domain_model_news
```

The `cleanup:updatecropvariants` command and the `--updateRatios` option are safe to add to a
deployment script (e.g., a projects `composer.json` hook) or scheduler task - each run only
changes what does not yet match the TCA configuration.

Only the `--forceOverride` flag is destructive and not safe for unsupervised execution.

## Related

Updating crop variants does not remove processed image files from the filesystem which had been
generated under the old crop configuration. They stay orphaned. To avoid purging the complete
processed images directory, you may use the package
[derhansen/processed-images-cleaner](https://packagist.org/packages/derhansen/processed-images-cleaner)
to purge selected stale processed image files:

```bash
# Scenario: The "mobile" crop variant was removed from TCA
# Remove all processed images that were generated for it
vendor/bin/typo3 cleanup:processedimages --config cropVariant=mobile

# Scenario: The "desktop" variant ratio changed from 3:2 to 16:9
# Run this in batches of 500 right after the TCA change, before anything
# regenerates a correct 16:9 file under the same variant name and gets removed too
vendor/bin/typo3 cleanup:processedimages --config cropVariant=desktop --limit=500 --dry-run
```

## Source

https://gitlab.com/pixelbrackets/typo3-update-crop-variants/

Mirror https://github.com/pixelbrackets/typo3-update-crop-variants/

## License

GNU General Public License version 2 or later

The GNU General Public License can be found at http://www.gnu.org/copyleft/gpl.html.

## Author

Dan Kleine (<mail@pixelbrackets.de> / [@pixelbrackets](https://pixelbrackets.de))
for [XIMA](https://www.xima.de/)

## Contribution

This script is Open Source, so please use, share, patch, extend or fork it.

Contributions are welcome!
