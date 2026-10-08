<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
namespace TYPO3\CMS\Linkvalidator2\Extraction;

use TYPO3\CMS\Core\DataHandling\TableColumnType;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\Field\FieldTranslationBehaviour;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\Field\TextFieldType;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Linkvalidator2\Configuration\ExtensionSettings;

/**
 * Decides which fields may contain links, derived from the schema.
 */
final readonly class CheckableFieldResolver
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
        private ExtensionSettings $extensionSettings,
    ) {}

    /**
     * Only fields shown for the record type of this row are returned. Permissions of
     * the current backend user deliberately play no role: the result is shared by
     * everyone, access is checked when results are displayed.
     *
     * @return list<CheckableField>
     */
    public function getFieldsForRecord(string $table, array $row): array
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return [];
        }
        $schema = $this->tcaSchemaFactory->get($table);
        $isTranslation = $this->isTranslation($schema, $row);
        $result = [];
        foreach ($this->getSchemaForRecord($schema, $row)->getFields() as $field) {
            // Excluded fields of a translation hold the value of the default language record
            if ($isTranslation && $field->getTranslationBehaviour() === FieldTranslationBehaviour::Excluded) {
                continue;
            }
            $checkableField = $this->toCheckableField($table, $field);
            if ($checkableField !== null) {
                $result[] = $checkableField;
            }
        }
        return $result;
    }

    /**
     * @return list<string>
     */
    public function getCheckableTables(): array
    {
        $tables = [];
        foreach ($this->tcaSchemaFactory->all() as $schema) {
            foreach ($schema->getFields() as $field) {
                if ($this->toCheckableField($schema->getName(), $field) !== null) {
                    $tables[] = $schema->getName();
                    break;
                }
            }
        }
        return $tables;
    }

    private function getSchemaForRecord(TcaSchema $schema, array $row): TcaSchema
    {
        if (!$schema->supportsSubSchema()) {
            return $schema;
        }
        $typeInformation = $schema->getSubSchemaTypeInformation();
        if ($typeInformation->isPointerToForeignFieldInForeignSchema()) {
            return $schema;
        }
        $recordType = (string)($row[$typeInformation->getFieldName()] ?? '');
        return $schema->hasSubSchema($recordType) ? $schema->getSubSchema($recordType) : $schema;
    }

    private function isTranslation(TcaSchema $schema, array $row): bool
    {
        if (!$schema->hasCapability(TcaSchemaCapability::Language)) {
            return false;
        }
        $parentField = $schema->getCapability(TcaSchemaCapability::Language)->getTranslationOriginPointerField()->getName();
        return (int)($row[$parentField] ?? 0) > 0;
    }

    private function toCheckableField(string $table, FieldTypeInterface $field): ?CheckableField
    {
        if ($this->extensionSettings->isFieldExcluded($table, $field->getName())) {
            return null;
        }
        $softReferenceParsers = array_map(
            static fn(string $key): string => explode('[', $key, 2)[0],
            $field->getSoftReferenceKeys() ?: []
        );
        if ($field->isType(TableColumnType::LINK) || in_array('typolink', $softReferenceParsers, true)) {
            return new CheckableField($field->getName(), ExtractionMode::Typolink);
        }
        if (($field instanceof TextFieldType && $field->isRichText()) || in_array('typolink_tag', $softReferenceParsers, true)) {
            return new CheckableField($field->getName(), ExtractionMode::Html);
        }
        return null;
    }
}
