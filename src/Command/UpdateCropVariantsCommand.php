<?php

declare(strict_types=1);

namespace Pixelbrackets\UpdateCropVariants\Command;

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
*   # Update crops in news extension
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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = $input->getArgument('table');
        $field = $input->getArgument('field');
        $updateRatios = $input->getOption('updateRatios');
        $forceOverride = $input->getOption('forceOverride');

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
            if (count($fields) > 1) {
                $output->writeln('<info>=== Processing field: ' . $fieldName . ' ===</info>');
            }

            $result = $this->processField($table, $fieldName, $updateRatios, $forceOverride, $output);
            $totalUpdated += $result['updated'];
            $totalSkipped += $result['skipped'];

            if (count($fields) > 1) {
                $output->writeln('');
            }
        }

        $output->writeln('');
        $output->writeln('<info>Total Updated: ' . $totalUpdated . '</info>');
        $output->writeln('Total Skipped: ' . $totalSkipped);

        return Command::SUCCESS;
    }

    /**
    * Process a single field
    * @return array<string, int>
    */
    private function processField(string $table, string $field, bool $updateRatios, bool $forceOverride, OutputInterface $output): array
    {
        $fileReferences = $this->getFileReferences($table, $field);
        $output->writeln('Processing ' . count($fileReferences) . ' file reference(s)…');

        $referencesByType = $this->groupReferencesByType($fileReferences, $table);
        $output->writeln('Found ' . count($referencesByType) . ' type(s) with different crop variants');

        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($referencesByType as $type => $references) {
            $TCAType = $type !== '' ? (string)$type : null;
            $cropVariantsConfig = $this->getCropVariantsFromTCAForType($table, $field, $TCAType);
            if ($cropVariantsConfig === null) {
                $output->writeln('<comment>No crop variants found for ' . $table . '.' . $field . ($type ? ' (type: ' . $type . ')' : '') . ' - skipping ' . count($references) . ' reference(s)</comment>');
                $skippedCount += count($references);
                continue;
            }

            $output->writeln('');
            $output->writeln('Type: ' . ($type ?: 'default') . ' (' . count($cropVariantsConfig) . ' crop variant(s), ' . count($references) . ' reference(s))');

            foreach ($references as $reference) {
                $result = $this->updateFileReference(
                    $reference,
                    $cropVariantsConfig,
                    $updateRatios,
                    $forceOverride,
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

        $typeField = match ($table) {
            'tt_content' => 'CType',
            'pages' => 'doktype',
            default => null
        };

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
    * Get crop variants from TCA for a specific type (e.g., CType, doktype)
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

        $typeConfig = $GLOBALS['TCA'][$table]['types'][$type] ?? null;
        if ($typeConfig !== null) {
            $overrideCropVariants = $typeConfig['columnsOverrides'][$field]['config']['overrideChildTca']['columns']['crop']['config']['cropVariants'] ?? null;
            if ($overrideCropVariants !== null) {
                return $overrideCropVariants;
            }
        }

        return $this->getCropVariantsFromTCA($table, $field);
    }

    /**
    * @return array<int, array<string, mixed>>
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

        if ($table === 'tt_content') {
            $query->leftJoin(
                'r',
                'tt_content',
                'p',
                $queryBuilder->expr()->eq('p.uid', $queryBuilder->quoteIdentifier('r.uid_foreign'))
            );
            $query->addSelect('p.CType');
        } elseif ($table === 'pages') {
            $query->leftJoin(
                'r',
                'pages',
                'p',
                $queryBuilder->expr()->eq('p.uid', $queryBuilder->quoteIdentifier('r.uid_foreign'))
            );
            $query->addSelect('p.doktype');
        }

        return $query->executeQuery()->fetchAllAssociative();
    }

    /**
    * @param array<string, mixed> $reference
    * @param array<string, mixed> $cropVariantsConfig
    */
    private function updateFileReference(
        array $reference,
        array $cropVariantsConfig,
        bool $updateRatios,
        bool $forceOverride,
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
            $output->writeln('  #' . $reference['uid'] . ' - no update needed');
            return false;
        }

        $updatedCropConfiguration = $this->generateCropConfiguration(
            $variantsToGenerate,
            $existingCropAreas,
            $file
        );

        $this->saveCropConfiguration($reference['uid'], $updatedCropConfiguration);

        $output->writeln('<info>  #' . $reference['uid'] . ' - updated (' . count($variantsToGenerate) . ' variant(s))</info>');

        return true;
    }

    /**
    * @param array<string, mixed> $reference
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
    * @param array<string, mixed> $cropVariantsConfig
    * @param array<string, mixed> $existingCropAreas
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
                if (!isset($existingCropAreas[$variantName])) {
                    $variantsToGenerate[$variantName] = $variantConfig;
                    continue;
                }

                $tcaRatio = $this->extractAspectRatioFromTCA($variantConfig);
                $existingRatio = $this->calculateAspectRatioFromCropArea($existingCropAreas[$variantName]);

                if ($tcaRatio !== null && ($existingRatio === null || !$this->ratiosMatch($tcaRatio, $existingRatio))) {
                    $variantsToGenerate[$variantName] = $variantConfig;
                }
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
    * @param array<string, mixed> $variantsToGenerate
    * @param array<string, mixed> $existingCropAreas
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
    * Extract aspect ratio from TCA crop variant configuration
    *
    * @param array<string, mixed> $variantConfig Crop variant configuration from TCA
    * @return float|null Aspect ratio as decimal (e.g., 1.5 for 3:2) or null if not found
    */
    private function extractAspectRatioFromTCA(array $variantConfig): ?float
    {
        $ratioString = $variantConfig['selectedRatio'] ?? null;

        if ($ratioString === null && isset($variantConfig['allowedAspectRatios'])) {
            $ratioString = array_key_first($variantConfig['allowedAspectRatios']);
        }

        if ($ratioString === null) {
            return null;
        }

        return $this->parseRatioString($ratioString);
    }

    /**
    * Calculate aspect ratio from existing crop area
    *
    * First tries to use the stored selectedRatio (more accurate).
    * Falls back to calculating from actual crop area coordinates.
    *
    * @param array<string, mixed> $cropArea Crop area with selectedRatio, cropArea coordinates
    * @return float|null Aspect ratio as decimal or null if invalid
    */
    private function calculateAspectRatioFromCropArea(array $cropArea): ?float
    {
        $selectedRatio = $cropArea['selectedRatio'] ?? null;
        if ($selectedRatio !== null) {
            return $this->parseRatioString($selectedRatio);
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
    private function ratiosMatch(float $ratio1, float $ratio2): bool
    {
        return abs($ratio1 - $ratio2) < 0.01;
    }

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
