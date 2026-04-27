<?php

declare(strict_types=1);

namespace Pixelbrackets\UpdateCropVariants\Command;

use Doctrine\DBAL\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
* Updates crop variants for image fields across the system
*
* (i) Crop variants allow editors to define different image crops for different contexts (e.g., desktop, mobile).
* Each variant offers a list of allowed aspect ratios (e.g., "3:2", "16:9", "4:3", free) to choose from.
* The editor picks one ratio per variant in the backend, and the resulting crop coordinates are stored
* in the file reference.
* See https://docs.typo3.org/permalink/t3tca:columns-imagemanipulation-introduction for configuration details.
*
* When new crop variants are added to TCA configuration, or when aspect ratios are changed,
* all existing file references with crops need to be updated by editors. This command automates that process.
*
* By default, the command only adds missing crop variants and does not touch existing ones,
* so editors manual crop adjustments are preserved.
*
* Use --updateRatios to also update existing variants where the stored ratio no longer matches the TCA ratio.
* Only mismatched crops are overwritten with a centered default - crops that already match are preserved.
*
* The command defaults to outputting a summary only. Use -v to see per-item details.
*
* Usage:
*   # Scenario: Add new mobile crop variant to specific field image in tt_content
*   vendor/bin/typo3 cleanup:updatecropvariants tt_content image
*
*   # Auto-detect and update all image fields in tt_content
*   vendor/bin/typo3 cleanup:updatecropvariants tt_content
*
*   # Scenario: Update desktop variant after changing ratio from 3:2 to 16:9
*   vendor/bin/typo3 cleanup:updatecropvariants tt_content image --updateRatios
*
*   # Auto-detect all image fields and update changed ratios
*   vendor/bin/typo3 cleanup:updatecropvariants tt_content --updateRatios
*
*   # Reset all crops to defaults (!), removing any existing crop adjustments
*   vendor/bin/typo3 cleanup:updatecropvariants tt_content image --forceOverride
*
*   # Preview changes without writing, show per-reference details
*   vendor/bin/typo3 cleanup:updatecropvariants tt_content image --dry-run -v
*
*   # Update crops in a third-party extension table
*   vendor/bin/typo3 cleanup:updatecropvariants tx_news_domain_model_news
*/
class UpdateCropVariantsCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Update crop variants for image fields - Adds missing crop variants and optionally regenerates changed ratios');
        $this->addArgument(
            'table',
            InputArgument::REQUIRED,
            'Table name (e.g., tt_content, tx_news_domain_model_news)'
        );
        $this->addArgument(
            'field',
            InputArgument::OPTIONAL,
            'Field name (e.g., image, media, fal_media) - omit to auto-detect image fields'
        );
        $this->addOption(
            'updateRatios',
            'r',
            InputOption::VALUE_NONE,
            'Also update crop coordinates for existing variants where the stored ratio no longer matches the TCA ratio'
        );
        $this->addOption(
            'forceOverride',
            'f',
            InputOption::VALUE_NONE,
            'Reset all crops to defaults (!), removing any existing crop adjustments'
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Simulate the run without writing any changes to the database'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = $input->getArgument('table');
        $field = $input->getArgument('field');
        $updateRatios = $input->getOption('updateRatios');
        $forceOverride = $input->getOption('forceOverride');
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $output->writeln('<comment>Dry run - no changes will be written</comment>');
            $output->writeln('');
        }

        // Auto-detect fields if none specified
        if ($field === null) {
            $fields = $this->detectImageFieldsWithCropVariants($table);
            if (empty($fields)) {
                $output->writeln('<error>No image fields with crop variants found in table ' . $table . '</error>');
                return Command::FAILURE;
            }
            $output->writeln('<info>Auto-detected ' . count($fields) . ' field(s) with crop variants: ' . implode(', ', $fields) . '</info>');
            $output->writeln('');
        } else {
            $fields = [$field];
        }

        $totalUpdated = 0;
        $totalSkipped = 0;

        foreach ($fields as $fieldName) {
            $output->writeln('<info>=== Field: ' . $table . '.' . $fieldName . ' ===</info>');
            $output->writeln('');

            $result = $this->processField($table, $fieldName, $updateRatios, $forceOverride, $dryRun, $output);
            $totalUpdated += $result['updated'];
            $totalSkipped += $result['skipped'];

            $output->writeln('');
        }

        $output->writeln('<info>' . ($dryRun ? 'Would update' : 'Updated') . ': ' . $totalUpdated . '</info>');
        $output->writeln('Skipped: ' . $totalSkipped);

        return Command::SUCCESS;
    }

    /**
    * Process a single field
    *
    * @param string $table Table name
    * @param string $field Field name
    * @param bool $updateRatios Whether to reset variants with mismatched ratios
    * @param bool $forceOverride Whether to reset all variants regardless of existing values
    * @param bool $dryRun Whether to skip writing changes to the database
    * @param OutputInterface $output Console output
    * @return array<string, int>
    * @throws Exception
    */
    private function processField(string $table, string $field, bool $updateRatios, bool $forceOverride, bool $dryRun, OutputInterface $output): array
    {
        $fileReferences = $this->getFileReferences($table, $field);
        $output->writeln('Processing ' . count($fileReferences) . ' file reference(s)…');

        $referencesByType = $this->groupReferencesByType($fileReferences, $table);

        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($referencesByType as $type => $references) {
            $TCAType = $type !== '' ? (string)$type : null;
            $cropVariantsConfig = $this->getCropVariantsFromTCAForType($table, $field, $TCAType);
            if ($cropVariantsConfig === null) {
                $output->writeln('');
                $output->writeln('Type: ' . ($type ?: 'default') . ' (' . count($references) . ' reference(s))');
                $output->writeln('');
                $output->writeln('<comment>  No crop variants configured - skipping</comment>');
                $skippedCount += count($references);
                continue;
            }

            $output->writeln('');
            $output->writeln('Type: ' . ($type ?: 'default') . ' (' . count($references) . ' reference(s))');
            $output->writeln('');

            // Crop variants summary
            foreach ($cropVariantsConfig as $variantName => $variantConfig) {
                $ratios = [];
                foreach (array_keys($variantConfig['allowedAspectRatios'] ?? []) as $ratioKey) {
                    $ratios[] = str_contains((string)$ratioKey, ':') ? (string)$ratioKey : 'free';
                }
                $output->writeln('  * ' . $variantName . ' (' . implode(', ', $ratios ?: ['free']) . ')');
            }

            if ($output->isVerbose()) {
                $output->writeln('');
            }

            foreach ($references as $reference) {
                $result = $this->updateFileReference(
                    $reference,
                    $cropVariantsConfig,
                    $updateRatios,
                    $forceOverride,
                    $dryRun,
                    $output
                );

                if ($result) {
                    $updatedCount++;
                } else {
                    $skippedCount++;
                }
            }
        }

        return ['updated' => $updatedCount, 'skipped' => $skippedCount];
    }

    /**
    * Detect all image fields in a table that have crop variants configured
    *
    * @param string $table Table name
    * @return array<int, string> List of field names with crop variants
    */
    private function detectImageFieldsWithCropVariants(string $table): array
    {
        $fields = [];

        if (!isset($GLOBALS['TCA'][$table]['columns'])) {
            return $fields;
        }

        foreach ($GLOBALS['TCA'][$table]['columns'] as $fieldName => $fieldConfig) {
            $type = $fieldConfig['config']['type'] ?? null;
            $foreignTable = $fieldConfig['config']['foreign_table'] ?? null;

            $isFalField = $type === 'file' || ($type === 'inline' && $foreignTable === 'sys_file_reference');

            if (!$isFalField) {
                continue;
            }

            if ($this->hasCropVariantsInField($table, $fieldName)) {
                $fields[] = $fieldName;
            }
        }

        return $fields;
    }

    /**
    * Check if a field has crop variants configured anywhere (base column or type overrides)
    *
    * @param string $table Table name
    * @param string $field Field name
    * @return bool True if crop variants are configured
    */
    private function hasCropVariantsInField(string $table, string $field): bool
    {
        if ($this->getCropVariantsFromTCA($table, $field) !== null) {
            return true;
        }

        $types = $GLOBALS['TCA'][$table]['types'] ?? [];
        foreach ($types as $typeConfig) {
            $overrideCropVariants = $typeConfig['columnsOverrides'][$field]['config']['overrideChildTca']['columns']['crop']['config']['cropVariants'] ?? null;
            if ($overrideCropVariants !== null) {
                return true;
            }
        }

        return false;
    }

    /**
    * Group file references by their type (CType for tt_content, doktype for pages, etc.)
    *
    * @param array<int, array<string, mixed>> $fileReferences File references from getFileReferences()
    * @param string $table Table name to determine type field
    * @return array<int|string, array<int, array<string, mixed>>> References grouped by type
    */
    private function groupReferencesByType(array $fileReferences, string $table): array
    {
        $grouped = [];

        $typeField = $GLOBALS['TCA'][$table]['ctrl']['type'] ?? null;

        foreach ($fileReferences as $reference) {
            $typeValue = $typeField !== null ? ($reference[$typeField] ?? null) : null;

            if (!isset($grouped[$typeValue])) {
                $grouped[$typeValue] = [];
            }

            $grouped[$typeValue][] = $reference;
        }

        return $grouped;
    }

    /**
    * Get crop variants from TCA for a field without type-specific overrides.
    *
    * Returns the global cropVariants config defined directly on the field,
    * used as fallback when no type-specific TCA override exists.
    *
    * @return array<string, mixed>|null
    */
    private function getCropVariantsFromTCA(string $table, string $field): ?array
    {
        $tcaConfig = $GLOBALS['TCA'][$table]['columns'][$field] ?? null;

        if (!$tcaConfig) {
            return null;
        }

        return $tcaConfig['config']['overrideChildTca']['columns']['crop']['config']['cropVariants'] ?? null;
    }

    /**
    * Get crop variants from TCA for a specific type (e.g. CType, doktype) of a table
    *
    * @param string $table Table name (e.g., tt_content, pages)
    * @param string $field Field name (e.g., image, media)
    * @param string|null $type Type identifier (e.g., CType value, doktype value)
    * @return array<string, mixed>|null Crop variants configuration or null if not found
    */
    private function getCropVariantsFromTCAForType(string $table, string $field, ?string $type): ?array
    {
        if ($type === null) {
            return $this->getCropVariantsFromTCA($table, $field);
        }

        $tcaTypes = $GLOBALS['TCA'][$table]['types'] ?? [];
        $typeConfig = $tcaTypes[$type] ?? null;

        // Fall back to TCA default type »0« when the type value has no matching TCA key
        // Extbase extensions may store a PHP class name in the type field instead
        // of a TCA key, so type »0« is used as a safe fallback (same as TYPO3 core does)
        if ($typeConfig === null && isset($tcaTypes[0])) {
            $typeConfig = $tcaTypes[0];
        }

        if ($typeConfig !== null) {
            $overrideCropVariants = $typeConfig['columnsOverrides'][$field]['config']['overrideChildTca']['columns']['crop']['config']['cropVariants'] ?? null;
            if ($overrideCropVariants !== null) {
                return $overrideCropVariants;
            }
        }

        return $this->getCropVariantsFromTCA($table, $field);
    }

    /**
    * Fetch all file references for a table field and group references by type if available
    *
    * @param string $table Table name
    * @param string $field Field name
    * @return array<int, array<string, mixed>>
    * @throws Exception
    */
    private function getFileReferences(string $table, string $field): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        $query = $queryBuilder
            ->select('r.uid', 'r.crop', 'r.uid_foreign', 'f.uid as fileUid', 'f.storage', 'f.identifier')
            ->from('sys_file_reference', 'r')
            ->innerJoin(
                'r',
                'sys_file',
                'f',
                $queryBuilder->expr()->eq('f.uid', $queryBuilder->quoteIdentifier('r.uid_local'))
            )
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($field))
            );

        $typeField = $GLOBALS['TCA'][$table]['ctrl']['type'] ?? null;
        if ($typeField !== null) {
            $query->leftJoin(
                'r',
                $table,
                'p',
                $queryBuilder->expr()->eq('p.uid', $queryBuilder->quoteIdentifier('r.uid_foreign'))
            );
            $query->addSelect('p.' . $typeField);
        }

        return $query->executeQuery()->fetchAllAssociative();
    }

    /**
    * Update a single file reference with new crop variants
    *
    * @param array<string, mixed> $reference File reference row from sys_file_reference
    * @param array<string, mixed> $cropVariantsConfig Crop variants configuration from TCA
    * @param bool $updateRatios Whether to reset variants with mismatched ratios
    * @param bool $forceOverride Whether to reset all variants regardless of existing values
    * @param bool $dryRun Whether to skip writing changes to the database
    * @param OutputInterface $output Console output
    */
    private function updateFileReference(
        array $reference,
        array $cropVariantsConfig,
        bool $updateRatios,
        bool $forceOverride,
        bool $dryRun,
        OutputInterface $output
    ): bool {
        $file = $this->getFile($reference);
        if (!$file) {
            $output->writeln('<comment>  #' . $reference['uid'] . ' - file not found</comment>');
            return false;
        }

        $existingCropAreas = $this->parseExistingCropAreas($reference['crop']);

        $variantsToGenerate = $this->determineVariantsToGenerate(
            $cropVariantsConfig,
            $existingCropAreas,
            $updateRatios,
            $forceOverride
        );

        if (empty($variantsToGenerate)) {
            if ($output->isVerbose()) {
                $output->writeln('  #' . $reference['uid'] . ' - no update needed');
            }
            return false;
        }

        $updatedCropConfiguration = $this->generateCropConfiguration(
            $variantsToGenerate,
            $existingCropAreas,
            $file
        );

        if (!$dryRun) {
            $this->saveCropConfiguration($reference['uid'], $updatedCropConfiguration);
        }

        if ($output->isVerbose()) {
            foreach ($variantsToGenerate as $variantName => $variantConfig) {
                $action = isset($existingCropAreas[$variantName]) ? 'reset' : 'add';
                $ratio = $this->getDisplayRatio($variantConfig);
                $output->writeln('<info>  #' . $reference['uid'] . ' - variant "' . $variantName . '": ' . $action . ' (ratio ' . $ratio . ')</info>');
            }
        }

        return true;
    }

    /**
    * Fetch the actual file object in order to calculate the crop area later on
    *
    * @param array<string, mixed> $reference File reference row from sys_file_reference
    */
    private function getFile(array $reference): ?File
    {
        try {
            $storage = GeneralUtility::makeInstance(StorageRepository::class)
                ->getStorageObject($reference['storage']);
            $file = $storage->getFileByIdentifier($reference['identifier']);
            return !($file instanceof ProcessedFile) ? $file : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
    * Parse crop areas from the stored JSON in sys_file_reference.crop
    *
    * @param string|null $cropJson JSON string from sys_file_reference.crop
    * @return array<string, mixed>
    */
    private function parseExistingCropAreas(?string $cropJson): array
    {
        if (empty($cropJson)) {
            return [];
        }

        try {
            return json_decode($cropJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception) {
            return [];
        }
    }

    /**
    * Determine which crop variants need to be generated or reset
    *
    * @param array<string, mixed> $cropVariantsConfig Crop variants configuration from TCA
    * @param array<string, mixed> $existingCropAreas Existing crop areas from the stored JSON
    * @param bool $updateRatios Whether to reset variants with mismatched ratios
    * @param bool $forceOverride Whether to reset all variants regardless of existing values
    * @return array<string, mixed>
    */
    private function determineVariantsToGenerate(
        array $cropVariantsConfig,
        array $existingCropAreas,
        bool $updateRatios,
        bool $forceOverride
    ): array {
        if ($forceOverride) {
            return $cropVariantsConfig;
        }

        if ($updateRatios) {
            $variantsToGenerate = [];
            foreach ($cropVariantsConfig as $variantName => $variantConfig) {
                // Crop variant missing entirely - add with TCA default ratio
                if (!isset($existingCropAreas[$variantName])) {
                    $variantsToGenerate[$variantName] = $variantConfig;
                    continue;
                }

                $allowedRatios = $this->extractAllowedRatiosFromTCA($variantConfig);

                // Free-ratio variant in TCA - nothing to enforce, keep whatever the editor set
                if (empty($allowedRatios)) {
                    continue;
                }

                // Stored ratio still valid - preserve the editor's crop area and center point
                $existingRatio = $this->calculateAspectRatioFromCropArea($existingCropAreas[$variantName]);
                if ($existingRatio !== null && $this->matchesAnyRatio($existingRatio, $allowedRatios)) {
                    continue;
                }

                // Stored ratio is gone from TCA or was never set - reset to centered default
                $variantsToGenerate[$variantName] = $variantConfig;
            }
            return $variantsToGenerate;
        }

        $existingVariantNames = array_keys($existingCropAreas);
        return array_filter(
            $cropVariantsConfig,
            fn ($variantName) => !in_array($variantName, $existingVariantNames, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
    * Generate updated crop configuration JSON for a file reference
    *
    * @param array<string, mixed> $variantsToGenerate Crop variants to create or reset, keyed by variant name
    * @param array<string, mixed> $existingCropAreas Existing crop areas to preserve, keyed by variant name
    * @param File $file File to calculate crop dimensions against
    * @throws \RuntimeException if crop configuration cannot be encoded to JSON
    */
    private function generateCropConfiguration(array $variantsToGenerate, array $existingCropAreas, File $file): string
    {
        foreach ($variantsToGenerate as &$variant) {
            $variant['cropArea'] = [
                'x' => 0.0,
                'y' => 0.0,
                'width' => 1.0,
                'height' => 1.0,
            ];
        }

        $newCropCollection = CropVariantCollection::create('', $variantsToGenerate);
        $processedNewCrops = $newCropCollection->applyRatioRestrictionToSelectedCropArea($file);

        $processedNewCropsArray = json_decode((string)$processedNewCrops, true) ?: [];
        $finalCropAreas = array_merge($existingCropAreas, $processedNewCropsArray);

        $encoded = json_encode($finalCropAreas);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode crop configuration to JSON');
        }

        return $encoded;
    }

    /**
    * Extract all allowed aspect ratios from TCA crop variant configuration
    *
    * @param array<string, mixed> $variantConfig Crop variant configuration from TCA
    * @return float[] All parseable ratios; empty array means free-ratio variant
    */
    private function extractAllowedRatiosFromTCA(array $variantConfig): array
    {
        $ratios = [];
        foreach (array_keys($variantConfig['allowedAspectRatios'] ?? []) as $ratioString) {
            $ratio = $this->parseRatioString((string)$ratioString);
            if ($ratio !== null) {
                $ratios[] = $ratio;
            }
        }
        return $ratios;
    }

    /**
    * Calculate aspect ratio from existing crop area
    *
    * Uses the stored selectedRatio string when available. For free-ratio crops
    * (selectedRatio key e.g. "NaN") the effective ratio is calculated
    * from the crop coordinates to detect whether the editor set proportions
    * that match a fixed TCA ratio.
    *
    * @param array<string, mixed> $cropArea Crop area with selectedRatio, cropArea coordinates
    * @return float|null Aspect ratio as decimal or null if invalid
    */
    private function calculateAspectRatioFromCropArea(array $cropArea): ?float
    {
        $selectedRatio = $cropArea['selectedRatio'] ?? null;
        if ($selectedRatio !== null) {
            $parsed = $this->parseRatioString($selectedRatio);
            if ($parsed !== null) {
                return $parsed;
            }
            // free-ratio key detected - fall through to coordinate calculation
        }

        $cropAreaData = $cropArea['cropArea'] ?? null;
        if ($cropAreaData === null) {
            return null;
        }

        $width = $cropAreaData['width'] ?? 0;
        $height = $cropAreaData['height'] ?? 0;

        if ($height == 0) {
            return null;
        }

        return $width / $height;
    }

    /**
    * Parse ratio string (e.g., "3:2", "16:9") to decimal
    *
    * @param string $ratioString Ratio in format "width:height"
    * @return float|null Aspect ratio as decimal or null if invalid or free selection
    */
    private function parseRatioString(string $ratioString): ?float
    {
        if (!str_contains($ratioString, ':')) {
            return null;
        }

        $parts = explode(':', $ratioString);
        if (count($parts) !== 2) {
            return null;
        }

        $width = (float)$parts[0];
        $height = (float)$parts[1];

        if ($height == 0) {
            return null;
        }

        return $width / $height;
    }

    /**
    * Check if two aspect ratios match within a tolerance
    */
    private function matchesRatio(float $ratio1, float $ratio2): bool
    {
        return abs($ratio1 - $ratio2) < 0.01;
    }

    /**
    * Check if a ratio matches any entry in a list of allowed ratios within a tolerance
    *
    * @param float $existingRatio Ratio to check
    * @param float[] $allowedRatios List of ratios to check against
    */
    private function matchesAnyRatio(float $existingRatio, array $allowedRatios): bool
    {
        foreach ($allowedRatios as $ratio) {
            if ($this->matchesRatio($existingRatio, $ratio)) {
                return true;
            }
        }
        return false;
    }

    /**
    * Resolve a human-readable ratio label from a TCA crop variant config
    *
    * @param array<string, mixed> $variantConfig
    */
    private function getDisplayRatio(array $variantConfig): string
    {
        $selected = $variantConfig['selectedRatio'] ?? null;
        if ($selected !== null && str_contains((string)$selected, ':')) {
            return (string)$selected;
        }
        foreach (array_keys($variantConfig['allowedAspectRatios'] ?? []) as $key) {
            if (str_contains((string)$key, ':')) {
                return (string)$key;
            }
        }
        return 'free';
    }

    /**
    * Persist updated crop configuration JSON to sys_file_reference
    */
    private function saveCropConfiguration(int $referenceUid, string $cropConfiguration): void
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_reference');

        $queryBuilder
            ->update('sys_file_reference')
            ->set('crop', $cropConfiguration)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($referenceUid, Connection::PARAM_INT))
            )
            ->executeStatement();
    }
}
