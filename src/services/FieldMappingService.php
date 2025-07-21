<?php

namespace jamesedmonston\graphqlauthentication\services;

use Craft;
use craft\base\Component;
use craft\events\FieldLayoutEvent;
use craft\events\ModelEvent;
use craft\models\FieldLayout;
use craft\models\GqlSchema;
use craft\base\Field;
use yii\base\Event;
use craft\helpers\StringHelper;

class FieldMappingService extends Component
{
    private $_fieldMappingCache = [];

    public function init(): void
    {
        parent::init();

        // Invalidate cache when field layouts change
        Event::on(
            FieldLayout::class,
            FieldLayout::EVENT_AFTER_SAVE,
            [$this, 'invalidateFieldMappingCache']
        );

        Event::on(
            Field::class,
            Field::EVENT_AFTER_SAVE,
            [$this, 'invalidateFieldMappingCache']
        );
    }

    /**
     * Get field mapping for a specific GraphQL schema
     * Returns array mapping GraphQL field names to original Craft field handles
     *
     * @param GqlSchema $schema
     * @return array
     */
    public function getFieldMapping(GqlSchema $schema): array
    {
        $cacheKey = 'gql_field_mapping_' . $schema->id;

        if (isset($this->_fieldMappingCache[$cacheKey])) {
            return $this->_fieldMappingCache[$cacheKey];
        }

        $cached = Craft::$app->getCache()->get($cacheKey);
        if ($cached !== false) {
            return $this->_fieldMappingCache[$cacheKey] = $cached;
        }

        $mapping = $this->_buildFieldMapping($schema);

        // Cache for 1 hour, invalidate on field layout changes
        Craft::$app->getCache()->set($cacheKey, $mapping, 3600);
        $this->_fieldMappingCache[$cacheKey] = $mapping;

        return $mapping;
    }

    /**
     * Get all GraphQL field names available in a schema
     * Useful for displaying in admin interface
     *
     * @param GqlSchema $schema
     * @return array
     */
    public function getGraphqlFieldsForSchema(GqlSchema $schema): array
    {
        $cacheKey = 'gql_schema_fields_' . $schema->id;

        if (isset($this->_fieldMappingCache[$cacheKey])) {
            return $this->_fieldMappingCache[$cacheKey];
        }

        $cached = Craft::$app->getCache()->get($cacheKey);
        if ($cached !== false) {
            return $this->_fieldMappingCache[$cacheKey] = $cached;
        }

        $fields = $this->_extractGraphqlFields($schema);

        // Cache for 1 hour
        Craft::$app->getCache()->set($cacheKey, $fields, 3600);
        $this->_fieldMappingCache[$cacheKey] = $fields;

        return $fields;
    }

    /**
     * Invalidate all field mapping caches
     */
    public function invalidateFieldMappingCache(): void
    {
        $this->_fieldMappingCache = [];

        $cache = Craft::$app->getCache();
        $cache->flush();  // Could be more targeted, but this ensures consistency
    }

    /**
     * Migrate existing field restrictions from original handles to GraphQL field names
     * This helps users transition their existing configuration
     *
     * @param array $existingRestrictions
     * @param GqlSchema $schema
     * @return array
     */
    public function migrateFieldRestrictions(array $existingRestrictions, GqlSchema $schema): array
    {
        $fieldMapping = $this->getFieldMapping($schema);
        $migratedRestrictions = [];

        // Reverse mapping to go from original handle to GraphQL field name
        $reverseMapping = array_flip($fieldMapping);

        foreach ($existingRestrictions as $originalHandle => $permission) {
            if (isset($reverseMapping[$originalHandle])) {
                // Field has been renamed in GraphQL, use the new name
                $graphqlFieldName = $reverseMapping[$originalHandle];
                $migratedRestrictions[$graphqlFieldName] = $permission;
            } else {
                // Field name unchanged, keep as is
                $migratedRestrictions[$originalHandle] = $permission;
            }
        }

        return $migratedRestrictions;
    }

    /**
     * Build mapping between GraphQL field names and original Craft field handles
     *
     * @param GqlSchema $schema
     * @return array
     */
    private function _buildFieldMapping(GqlSchema $schema): array
    {
        $mapping = [];

        // Get all sections in the schema
        $sectionUids = $this->_getSectionUidsFromSchema($schema);
        $entriesService = Craft::$app->getEntries();

        foreach ($sectionUids as $sectionUid) {
            $section = $entriesService->getSectionByUid($sectionUid);
            if (!$section) {
                continue;
            }

            foreach ($section->getEntryTypes() as $entryType) {
                $fieldLayout = $entryType->getFieldLayout();
                if (!$fieldLayout) {
                    continue;
                }

                foreach ($fieldLayout->getCustomFields() as $field) {
                    // Get the field handle as it appears in the field layout (potentially renamed)
                    $layoutField = $fieldLayout->getFieldByUid($field->uid);
                    $graphqlFieldName = $layoutField->handle ?? $field->handle;

                    // Map GraphQL field name to original field handle
                    $mapping[$graphqlFieldName] = $field->handle;
                }
            }
        }

        // Also include global set fields
        $globalSetUids = $this->_getGlobalSetUidsFromSchema($schema);
        foreach ($globalSetUids as $globalSetUid) {
            $globalSet = Craft::$app->getGlobals()->getSetByUid($globalSetUid);
            if (!$globalSet) {
                continue;
            }

            $fieldLayout = $globalSet->getFieldLayout();
            if (!$fieldLayout) {
                continue;
            }

            foreach ($fieldLayout->getCustomFields() as $field) {
                $layoutField = $fieldLayout->getFieldByUid($field->uid);
                $graphqlFieldName = $layoutField->handle ?? $field->handle;
                $mapping[$graphqlFieldName] = $field->handle;
            }
        }

        return $mapping;
    }

    /**
     * Extract all GraphQL field definitions from a schema
     *
     * @param GqlSchema $schema
     * @return array
     */
    private function _extractGraphqlFields(GqlSchema $schema): array
    {
        $fields = [];

        try {
            $gqlService = Craft::$app->getGql();
            $schemaObj = $gqlService->createFullSchema($schema);
            $typeMap = $schemaObj->getTypeMap();

            foreach ($typeMap as $typeName => $type) {
                // Look for entry types and global set types
                if ((strpos($typeName, 'Entry') !== false || strpos($typeName, 'GlobalSet') !== false) &&
                    method_exists($type, 'getFields')
                ) {

                    $typeFields = $type->getFields();
                    if (is_array($typeFields)) {
                        foreach ($typeFields as $fieldName => $fieldDef) {
                            // Skip system fields
                            if (!in_array($fieldName, ['id', 'uid', 'title', 'slug', 'uri', 'url', 'ref', 'status', 'enabled', 'archived', 'searchScore', 'dateCreated', 'dateUpdated'])) {
                                $fields[$fieldName] = [
                                    'name' => $fieldName,
                                    'type' => $typeName,
                                    'description' => $fieldDef->description ?? ''
                                ];
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // If GraphQL schema parsing fails, fall back to field mapping approach
            $mapping = $this->_buildFieldMapping($schema);
            foreach (array_keys($mapping) as $fieldName) {
                $fields[$fieldName] = [
                    'name' => $fieldName,
                    'type' => 'Unknown',
                    'description' => ''
                ];
            }
        }

        return $fields;
    }

    /**
     * Extract section UIDs from schema scope
     *
     * @param GqlSchema $schema
     * @return array
     */
    private function _getSectionUidsFromSchema(GqlSchema $schema): array
    {
        $sectionUids = [];

        foreach ($schema->scope as $scope) {
            if (StringHelper::contains($scope, 'sections.')) {
                $scopeParts = explode('.', $scope);
                if (isset($scopeParts[1])) {
                    $uidPart = explode(':', $scopeParts[1])[0];
                    $sectionUids[] = $uidPart;
                }
            }
        }

        return array_unique($sectionUids);
    }

    /**
     * Extract global set UIDs from schema scope
     *
     * @param GqlSchema $schema
     * @return array
     */
    private function _getGlobalSetUidsFromSchema(GqlSchema $schema): array
    {
        $globalSetUids = [];

        foreach ($schema->scope as $scope) {
            if (StringHelper::contains($scope, 'globalsets.')) {
                $scopeParts = explode('.', $scope);
                if (isset($scopeParts[1])) {
                    $uidPart = explode(':', $scopeParts[1])[0];
                    $globalSetUids[] = $uidPart;
                }
            }
        }

        return array_unique($globalSetUids);
    }
}
