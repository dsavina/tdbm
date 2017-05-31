<?php

namespace TheCodingMachine\TDBM\Utils;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Mouf\Database\SchemaAnalyzer\SchemaAnalyzer;
use TheCodingMachine\TDBM\TDBMException;
use TheCodingMachine\TDBM\TDBMSchemaAnalyzer;

/**
 * This class represents a bean.
 */
class BeanDescriptor implements BeanDescriptorInterface
{
    /**
     * @var Table
     */
    private $table;

    /**
     * @var SchemaAnalyzer
     */
    private $schemaAnalyzer;

    /**
     * @var Schema
     */
    private $schema;

    /**
     * @var AbstractBeanPropertyDescriptor[]
     */
    private $beanPropertyDescriptors = [];

    /**
     * @var TDBMSchemaAnalyzer
     */
    private $tdbmSchemaAnalyzer;

    /**
     * @var NamingStrategyInterface
     */
    private $namingStrategy;

    /**
     * @param Table $table
     * @param SchemaAnalyzer $schemaAnalyzer
     * @param Schema $schema
     * @param TDBMSchemaAnalyzer $tdbmSchemaAnalyzer
     * @param NamingStrategyInterface $namingStrategy
     */
    public function __construct(Table $table, SchemaAnalyzer $schemaAnalyzer, Schema $schema, TDBMSchemaAnalyzer $tdbmSchemaAnalyzer, NamingStrategyInterface $namingStrategy)
    {
        $this->table = $table;
        $this->schemaAnalyzer = $schemaAnalyzer;
        $this->schema = $schema;
        $this->tdbmSchemaAnalyzer = $tdbmSchemaAnalyzer;
        $this->namingStrategy = $namingStrategy;
        $this->initBeanPropertyDescriptors();
    }

    private function initBeanPropertyDescriptors()
    {
        $this->beanPropertyDescriptors = $this->getProperties($this->table);
    }

    /**
     * Returns the foreign-key the column is part of, if any. null otherwise.
     *
     * @param Table  $table
     * @param Column $column
     *
     * @return ForeignKeyConstraint|null
     */
    private function isPartOfForeignKey(Table $table, Column $column)
    {
        $localColumnName = $column->getName();
        foreach ($table->getForeignKeys() as $foreignKey) {
            foreach ($foreignKey->getColumns() as $columnName) {
                if ($columnName === $localColumnName) {
                    return $foreignKey;
                }
            }
        }

        return;
    }

    /**
     * @return AbstractBeanPropertyDescriptor[]
     */
    public function getBeanPropertyDescriptors()
    {
        return $this->beanPropertyDescriptors;
    }

    /**
     * Returns the list of columns that are not nullable and not autogenerated for a given table and its parent.
     *
     * @return AbstractBeanPropertyDescriptor[]
     */
    public function getConstructorProperties()
    {
        $constructorProperties = array_filter($this->beanPropertyDescriptors, function (AbstractBeanPropertyDescriptor $property) {
            return $property->isCompulsory();
        });

        return $constructorProperties;
    }

    /**
     * Returns the list of columns that have default values for a given table.
     *
     * @return AbstractBeanPropertyDescriptor[]
     */
    public function getPropertiesWithDefault()
    {
        $properties = $this->getPropertiesForTable($this->table);
        $defaultProperties = array_filter($properties, function (AbstractBeanPropertyDescriptor $property) {
            return $property->hasDefault();
        });

        return $defaultProperties;
    }

    /**
     * Returns the list of properties exposed as getters and setters in this class.
     *
     * @return AbstractBeanPropertyDescriptor[]
     */
    public function getExposedProperties(): array
    {
        $exposedProperties = array_filter($this->beanPropertyDescriptors, function (AbstractBeanPropertyDescriptor $property) {
            return $property->getTable()->getName() == $this->table->getName();
        });

        return $exposedProperties;
    }

    /**
     * Returns the list of properties for this table (including parent tables).
     *
     * @param Table $table
     *
     * @return AbstractBeanPropertyDescriptor[]
     */
    private function getProperties(Table $table)
    {
        $parentRelationship = $this->schemaAnalyzer->getParentRelationship($table->getName());
        if ($parentRelationship) {
            $parentTable = $this->schema->getTable($parentRelationship->getForeignTableName());
            $properties = $this->getProperties($parentTable);
            // we merge properties by overriding property names.
            $localProperties = $this->getPropertiesForTable($table);
            foreach ($localProperties as $name => $property) {
                // We do not override properties if this is a primary key!
                if ($property->isPrimaryKey()) {
                    continue;
                }
                $properties[$name] = $property;
            }
        } else {
            $properties = $this->getPropertiesForTable($table);
        }

        return $properties;
    }

    /**
     * Returns the list of properties for this table (ignoring parent tables).
     *
     * @param Table $table
     *
     * @return AbstractBeanPropertyDescriptor[]
     */
    private function getPropertiesForTable(Table $table)
    {
        $parentRelationship = $this->schemaAnalyzer->getParentRelationship($table->getName());
        if ($parentRelationship) {
            $ignoreColumns = $parentRelationship->getLocalColumns();
        } else {
            $ignoreColumns = [];
        }

        $beanPropertyDescriptors = [];

        foreach ($table->getColumns() as $column) {
            if (array_search($column->getName(), $ignoreColumns) !== false) {
                continue;
            }

            $fk = $this->isPartOfForeignKey($table, $column);
            if ($fk !== null) {
                // Check that previously added descriptors are not added on same FK (can happen with multi key FK).
                foreach ($beanPropertyDescriptors as $beanDescriptor) {
                    if ($beanDescriptor instanceof ObjectBeanPropertyDescriptor && $beanDescriptor->getForeignKey() === $fk) {
                        continue 2;
                    }
                }
                // Check that this property is not an inheritance relationship
                $parentRelationship = $this->schemaAnalyzer->getParentRelationship($table->getName());
                if ($parentRelationship === $fk) {
                    continue;
                }

                $beanPropertyDescriptors[] = new ObjectBeanPropertyDescriptor($table, $fk, $this->schemaAnalyzer, $this->namingStrategy);
            } else {
                $beanPropertyDescriptors[] = new ScalarBeanPropertyDescriptor($table, $column, $this->namingStrategy);
            }
        }

        // Now, let's get the name of all properties and let's check there is no duplicate.
        /** @var $names AbstractBeanPropertyDescriptor[] */
        $names = [];
        foreach ($beanPropertyDescriptors as $beanDescriptor) {
            $name = $beanDescriptor->getGetterName();
            if (isset($names[$name])) {
                $names[$name]->useAlternativeName();
                $beanDescriptor->useAlternativeName();
            } else {
                $names[$name] = $beanDescriptor;
            }
        }

        // Final check (throw exceptions if problem arises)
        $names = [];
        foreach ($beanPropertyDescriptors as $beanDescriptor) {
            $name = $beanDescriptor->getGetterName();
            if (isset($names[$name])) {
                throw new TDBMException('Unsolvable name conflict while generating method name');
            } else {
                $names[$name] = $beanDescriptor;
            }
        }

        // Last step, let's rebuild the list with a map:
        $beanPropertyDescriptorsMap = [];
        foreach ($beanPropertyDescriptors as $beanDescriptor) {
            $beanPropertyDescriptorsMap[$beanDescriptor->getVariableName()] = $beanDescriptor;
        }

        return $beanPropertyDescriptorsMap;
    }

    private function generateBeanConstructor() : string
    {
        $constructorProperties = $this->getConstructorProperties();

        $constructorCode = '    /**
     * The constructor takes all compulsory arguments.
     *
%s
     */
    public function __construct(%s)
    {
%s%s    }
    ';

        $paramAnnotations = [];
        $arguments = [];
        $assigns = [];
        $parentConstructorArguments = [];

        foreach ($constructorProperties as $property) {
            $className = $property->getClassName();
            if ($className) {
                $arguments[] = $className.' '.$property->getVariableName();
            } else {
                $arguments[] = $property->getVariableName();
            }
            $paramAnnotations[] = $property->getParamAnnotation();
            if ($property->getTable()->getName() === $this->table->getName()) {
                $assigns[] = $property->getConstructorAssignCode()."\n";
            } else {
                $parentConstructorArguments[] = $property->getVariableName();
            }
        }

        $parentConstructorCode = sprintf("        parent::__construct(%s);\n", implode(', ', $parentConstructorArguments));

        foreach ($this->getPropertiesWithDefault() as $property) {
            $assigns[] = $property->assignToDefaultCode()."\n";
        }

        return sprintf($constructorCode, implode("\n", $paramAnnotations), implode(', ', $arguments), $parentConstructorCode, implode('', $assigns));
    }

    /**
     * Returns the descriptors of one-to-many relationships (the foreign keys pointing on this beans)
     *
     * @return DirectForeignKeyMethodDescriptor[]
     */
    private function getDirectForeignKeysDescriptors(): array
    {
        $fks = $this->tdbmSchemaAnalyzer->getIncomingForeignKeys($this->table->getName());

        $descriptors = [];

        foreach ($fks as $fk) {
            $descriptors[] = new DirectForeignKeyMethodDescriptor($fk, $this->table, $this->namingStrategy);
        }

        return $descriptors;
    }

    /**
     * @return PivotTableMethodsDescriptor[]
     */
    private function getPivotTableDescriptors(): array
    {
        $descs = [];
        foreach ($this->schemaAnalyzer->detectJunctionTables(true) as $table) {
            // There are exactly 2 FKs since this is a pivot table.
            $fks = array_values($table->getForeignKeys());

            if ($fks[0]->getForeignTableName() === $this->table->getName()) {
                list($localFk, $remoteFk) = $fks;
            } elseif ($fks[1]->getForeignTableName() === $this->table->getName()) {
                list($remoteFk, $localFk) = $fks;
            } else {
                continue;
            }

            $descs[] = new PivotTableMethodsDescriptor($table, $localFk, $remoteFk, $this->namingStrategy);
        }

        return $descs;
    }

    /**
     * Returns the list of method descriptors (and applies the alternative name if needed).
     *
     * @return MethodDescriptorInterface[]
     */
    public function getMethodDescriptors(): array
    {
        $directForeignKeyDescriptors = $this->getDirectForeignKeysDescriptors();
        $pivotTableDescriptors = $this->getPivotTableDescriptors();

        $descriptors = array_merge($directForeignKeyDescriptors, $pivotTableDescriptors);

        // Descriptors by method names
        $descriptorsByMethodName = [];

        foreach ($descriptors as $descriptor) {
            $descriptorsByMethodName[$descriptor->getName()][] = $descriptor;
        }

        foreach ($descriptorsByMethodName as $descriptorsForMethodName) {
            if (count($descriptorsForMethodName) > 1) {
                foreach ($descriptorsForMethodName as $descriptor) {
                    $descriptor->useAlternativeName();
                }
            }
        }

        return $descriptors;
    }

    public function generateJsonSerialize(): string
    {
        $tableName = $this->table->getName();
        $parentFk = $this->schemaAnalyzer->getParentRelationship($tableName);
        if ($parentFk !== null) {
            $initializer = '$array = parent::jsonSerialize($stopRecursion);';
        } else {
            $initializer = '$array = [];';
        }

        $str = '
    /**
     * Serializes the object for JSON encoding.
     *
     * @param bool $stopRecursion Parameter used internally by TDBM to stop embedded objects from embedding other objects.
     * @return array
     */
    public function jsonSerialize($stopRecursion = false)
    {
        %s
%s
%s
        return $array;
    }
';

        $propertiesCode = '';
        foreach ($this->beanPropertyDescriptors as $beanPropertyDescriptor) {
            $propertiesCode .= $beanPropertyDescriptor->getJsonSerializeCode();
        }

        // Many2many relationships
        $methodsCode = '';
        foreach ($this->getMethodDescriptors() as $methodDescriptor) {
            $methodsCode .= $methodDescriptor->getJsonSerializeCode();
        }

        return sprintf($str, $initializer, $propertiesCode, $methodsCode);
    }

    /**
     * Returns as an array the class we need to extend from and the list of use statements.
     *
     * @param ForeignKeyConstraint|null $parentFk
     * @return array
     */
    private function generateExtendsAndUseStatements(ForeignKeyConstraint $parentFk = null): array
    {
        $classes = [];
        if ($parentFk !== null) {
            $extends = $this->namingStrategy->getBeanClassName($parentFk->getForeignTableName());
            $classes[] = $extends;
        }

        foreach ($this->getBeanPropertyDescriptors() as $beanPropertyDescriptor) {
            $className = $beanPropertyDescriptor->getClassName();
            if (null !== $className) {
                $classes[] = $beanPropertyDescriptor->getClassName();
            }
        }

        foreach ($this->getMethodDescriptors() as $descriptor) {
            $classes = array_merge($classes, $descriptor->getUsedClasses());
        }

        $classes = array_unique($classes);

        return $classes;
    }

    /**
     * Writes the PHP bean file with all getters and setters from the table passed in parameter.
     *
     * @param string $beannamespace The namespace of the bean
     * @return string
     */
    public function generatePhpCode($beannamespace): string
    {
        $tableName = $this->table->getName();
        $baseClassName = $this->namingStrategy->getBaseBeanClassName($tableName);
        $className = $this->namingStrategy->getBeanClassName($tableName);
        $parentFk = $this->schemaAnalyzer->getParentRelationship($this->table->getName());

        $classes = $this->generateExtendsAndUseStatements($parentFk);

        $uses = array_map(function ($className) use ($beannamespace) {
            return 'use '.$beannamespace.'\\'.$className.";\n";
        }, $classes);
        $use = implode('', $uses);

        $extends = $this->getExtendedBeanClassName();
        if ($extends === null) {
            $extends = 'AbstractTDBMObject';
            $use .= "use TheCodingMachine\\TDBM\\AbstractTDBMObject;\n";
        }

        $str = "<?php
namespace {$beannamespace}\\Generated;

use TheCodingMachine\\TDBM\\ResultIterator;
use TheCodingMachine\\TDBM\\ResultArray;
use TheCodingMachine\\TDBM\\AlterableResultIterator;
$use
/*
 * This file has been automatically generated by TDBM.
 * DO NOT edit this file, as it might be overwritten.
 * If you need to perform changes, edit the $className class instead!
 */

/**
 * The $baseClassName class maps the '$tableName' table in database.
 */
abstract class $baseClassName extends $extends implements \\JsonSerializable
{
";

        $str .= $this->generateBeanConstructor();

        foreach ($this->getExposedProperties() as $property) {
            $str .= $property->getGetterSetterCode();
        }

        foreach ($this->getMethodDescriptors() as $methodDescriptor) {
            $str .= $methodDescriptor->getCode();
        }
        $str .= $this->generateJsonSerialize();

        $str .= $this->generateGetUsedTablesCode();

        $str .= $this->generateOnDeleteCode();

        $str .= '}
';

        return $str;
    }

    /**
     * @param string $beanNamespace
     * @param string $beanClassName
     *
     * @return array first element: list of used beans, second item: PHP code as a string
     */
    public function generateFindByDaoCode($beanNamespace, $beanClassName)
    {
        $code = '';
        $usedBeans = [];
        foreach ($this->table->getIndexes() as $index) {
            if (!$index->isPrimary()) {
                list($usedBeansForIndex, $codeForIndex) = $this->generateFindByDaoCodeForIndex($index, $beanNamespace, $beanClassName);
                $code .= $codeForIndex;
                $usedBeans = array_merge($usedBeans, $usedBeansForIndex);
            }
        }

        return [$usedBeans, $code];
    }

    /**
     * @param Index  $index
     * @param string $beanNamespace
     * @param string $beanClassName
     *
     * @return array first element: list of used beans, second item: PHP code as a string
     */
    private function generateFindByDaoCodeForIndex(Index $index, $beanNamespace, $beanClassName)
    {
        $columns = $index->getColumns();
        $usedBeans = [];

        /*
         * The list of elements building this index (expressed as columns or foreign keys)
         * @var AbstractBeanPropertyDescriptor[]
         */
        $elements = [];

        foreach ($columns as $column) {
            $fk = $this->isPartOfForeignKey($this->table, $this->table->getColumn($column));
            if ($fk !== null) {
                if (!in_array($fk, $elements)) {
                    $elements[] = new ObjectBeanPropertyDescriptor($this->table, $fk, $this->schemaAnalyzer, $this->namingStrategy);
                }
            } else {
                $elements[] = new ScalarBeanPropertyDescriptor($this->table, $this->table->getColumn($column), $this->namingStrategy);
            }
        }

        // If the index is actually only a foreign key, let's bypass it entirely.
        if (count($elements) === 1 && $elements[0] instanceof ObjectBeanPropertyDescriptor) {
            return [[], ''];
        }

        $functionParameters = [];
        $first = true;
        foreach ($elements as $element) {
            $functionParameter = $element->getClassName();
            if ($functionParameter) {
                $usedBeans[] = $beanNamespace.'\\'.$functionParameter;
                $functionParameter .= ' ';
            }
            $functionParameter .= $element->getVariableName();
            if ($first) {
                $first = false;
            } else {
                $functionParameter .= ' = null';
            }
            $functionParameters[] = $functionParameter;
        }

        $functionParametersString = implode(', ', $functionParameters);

        $count = 0;

        $params = [];
        $filterArrayCode = '';
        $commentArguments = [];
        foreach ($elements as $element) {
            $params[] = $element->getParamAnnotation();
            if ($element instanceof ScalarBeanPropertyDescriptor) {
                $filterArrayCode .= '            '.var_export($element->getColumnName(), true).' => '.$element->getVariableName().",\n";
            } else {
                ++$count;
                $filterArrayCode .= '            '.$count.' => '.$element->getVariableName().",\n";
            }
            $commentArguments[] = substr($element->getVariableName(), 1);
        }
        $paramsString = implode("\n", $params);

        $methodName = $this->namingStrategy->getFindByIndexMethodName($index, $elements);

        if ($index->isUnique()) {
            $returnType = "{$beanClassName}";

            $code = "
    /**
     * Get a $beanClassName filtered by ".implode(', ', $commentArguments).".
     *
$paramsString
     * @param array \$additionalTablesFetch A list of additional tables to fetch (for performance improvement)
     * @return $returnType
     */
    public function $methodName($functionParametersString, array \$additionalTablesFetch = array()) : $returnType
    {
        \$filter = [
".$filterArrayCode."        ];
        return \$this->findOne(\$filter, [], \$additionalTablesFetch);
    }
";
        } else {
            $returnType = "{$beanClassName}[]|ResultIterator|ResultArray";

            $code = "
    /**
     * Get a list of $beanClassName filtered by ".implode(', ', $commentArguments).".
     *
$paramsString
     * @param mixed \$orderBy The order string
     * @param array \$additionalTablesFetch A list of additional tables to fetch (for performance improvement)
     * @param string \$mode Either TDBMService::MODE_ARRAY or TDBMService::MODE_CURSOR (for large datasets). Defaults to TDBMService::MODE_ARRAY.
     * @return $returnType
     */
    public function $methodName($functionParametersString, \$orderBy = null, array \$additionalTablesFetch = array(), \$mode = null) : iterable
    {
        \$filter = [
".$filterArrayCode."        ];
        return \$this->find(\$filter, [], \$orderBy, \$additionalTablesFetch, \$mode);
    }
";
        }

        return [$usedBeans, $code];
    }

    /**
     * Generates the code for the getUsedTable protected method.
     *
     * @return string
     */
    private function generateGetUsedTablesCode()
    {
        $hasParentRelationship = $this->schemaAnalyzer->getParentRelationship($this->table->getName()) !== null;
        if ($hasParentRelationship) {
            $code = sprintf('        $tables = parent::getUsedTables();
        $tables[] = %s;

        return $tables;', var_export($this->table->getName(), true));
        } else {
            $code = sprintf('        return [ %s ];', var_export($this->table->getName(), true));
        }

        return sprintf('
    /**
     * Returns an array of used tables by this bean (from parent to child relationship).
     *
     * @return string[]
     */
    protected function getUsedTables() : array
    {
%s
    }
', $code);
    }

    private function generateOnDeleteCode()
    {
        $code = '';
        $relationships = $this->getPropertiesForTable($this->table);
        foreach ($relationships as $relationship) {
            if ($relationship instanceof ObjectBeanPropertyDescriptor) {
                $code .= sprintf('        $this->setRef('.var_export($relationship->getForeignKey()->getName(), true).', null, '.var_export($this->table->getName(), true).");\n");
            }
        }

        if ($code) {
            return sprintf('
    /**
     * Method called when the bean is removed from database.
     *
     */
    protected function onDelete() : void
    {
        parent::onDelete();
%s    }
', $code);
        }

        return '';
    }

    /**
     * Returns the bean class name (without the namespace).
     *
     * @return string
     */
    public function getBeanClassName() : string
    {
        return $this->namingStrategy->getBeanClassName($this->table->getName());
    }

    /**
     * Returns the base bean class name (without the namespace).
     *
     * @return string
     */
    public function getBaseBeanClassName() : string
    {
        return $this->namingStrategy->getBaseBeanClassName($this->table->getName());
    }

    /**
     * Returns the DAO class name (without the namespace).
     *
     * @return string
     */
    public function getDaoClassName() : string
    {
        return $this->namingStrategy->getDaoClassName($this->table->getName());
    }

    /**
     * Returns the base DAO class name (without the namespace).
     *
     * @return string
     */
    public function getBaseDaoClassName() : string
    {
        return $this->namingStrategy->getBaseDaoClassName($this->table->getName());
    }

    /**
     * Returns the table used to build this bean.
     *
     * @return Table
     */
    public function getTable(): Table
    {
        return $this->table;
    }

    /**
     * Returns the extended bean class name (without the namespace), or null if the bean is not extended.
     *
     * @return string
     */
    public function getExtendedBeanClassName(): ?string
    {
        $parentFk = $this->schemaAnalyzer->getParentRelationship($this->table->getName());
        if ($parentFk !== null) {
            return $this->namingStrategy->getBeanClassName($parentFk->getForeignTableName());
        } else {
            return null;
        }
    }
}
