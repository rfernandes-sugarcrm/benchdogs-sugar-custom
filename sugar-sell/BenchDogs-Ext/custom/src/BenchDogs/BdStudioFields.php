<?php

namespace Sugarcrm\Sugarcrm\custom\BenchDogs;

use RuntimeException;

/** The two Sugar calls BdKineticFields makes, through Sugar's own Studio field code (SugarEnt 25.2 / 26.1). */
class BdStudioFields
{
    /**
     * The field names a module has: its merged vardefs, plus every live custom field Studio recorded, so a Studio
     * field whose vardef cache is stale still counts as there. Throws when the module cannot be read.
     * @return string[]
     */
    public function fieldNames(string $module): array
    {
        $object = \BeanFactory::getObjectName($module);
        if (empty($object) || !\BeanFactory::newBean($module)) {
            throw new RuntimeException($module . ' is not a registered module');
        }
        \VardefManager::refreshVardefs($module, $object);
        $names = array_keys($GLOBALS['dictionary'][$object]['fields'] ?? array());
        $meta = \BeanFactory::newBean('EditCustomFields');
        foreach (array_keys(BdKineticFields::FIELDS[$module] ?? array()) as $name) {
            if (!in_array($name, $names, true) && $meta->retrieveByCustomModuleAndName($module, $name) !== null) {
                $names[] = $name;
            }
        }
        if (!isset($names[0])) {
            throw new RuntimeException($module . ' answered no field definitions');
        }

        return $names;
    }

    /**
     * Create one custom field the way Studio's Save Field does: the field template for its type, saved through
     * DynamicField (the fields_meta_data row and the _cstm column), then its label through Studio's label parser.
     * @param array{type: string, len: ?int, precision: ?int, options: ?string, label: string, label_value: string} $spec
     */
    public function create(string $module, string $name, array $spec): void
    {
        require_once 'modules/DynamicFields/FieldCases.php';
        require_once 'modules/DynamicFields/DynamicField.php';
        require_once 'modules/ModuleBuilder/parsers/parser.label.php';

        $field = get_widget($spec['type']);
        if (!$field) {
            throw new RuntimeException('no Studio field template for type ' . $spec['type']);
        }
        $field->name = $name;
        $field->label = $spec['label'];
        if ($spec['len'] !== null) {
            $field->len = $spec['len'];
        }
        if ($spec['precision'] !== null) {
            // A decimal's places ride ext1 (TemplateFloat's vardef map); a currency's its own property.
            $field->precision = $spec['precision'];
            if ($spec['type'] === 'decimal') {
                $field->ext1 = $spec['precision'];
            }
        }
        if ($spec['options'] !== null) {
            $field->ext1 = $spec['options'];
            $field->options = $spec['options'];
        }

        $bean = \BeanFactory::newBean($module);
        $dynamic = new \DynamicField($module);
        $dynamic->setup($bean);
        $field->save($dynamic);
        \ParserLabel::addLabels('en_us', array($spec['label'] => $spec['label_value']), $module);
    }

    /** The label text the tenant shows for $key on $module (Studio's own label included), or null when it has none. */
    public function labelValue(string $module, string $key): ?string
    {
        $strings = return_module_language('en_us', $module, true);
        return isset($strings[$key]) ? (string) $strings[$key] : null;
    }

    /** Set one label the way Studio's label editor does. */
    public function setLabel(string $module, string $key, string $value): void
    {
        require_once 'modules/ModuleBuilder/parsers/parser.label.php';
        \ParserLabel::addLabels('en_us', array($key => $value), $module);
    }
}
