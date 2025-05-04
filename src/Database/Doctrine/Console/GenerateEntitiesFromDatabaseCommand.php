<?php

namespace Metapp\Apollo\Database\Doctrine\Console;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Console\Command\AbstractEntityManagerCommand;
use Doctrine\ORM\Tools\Console\EntityManagerProvider;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateEntitiesFromDatabaseCommand extends AbstractEntityManagerCommand
{
    protected static $defaultName = 'apollo:generate:entities-from-db';

    private EntityManagerInterface $entityManager;
    private EntityManagerProvider $entityManagerProvider;
    private array $generatedEntities = [];

    /**
     * @param EntityManagerProvider $entityManagerProvider
     */
    public function __construct(EntityManagerProvider $entityManagerProvider)
    {
        parent::__construct($entityManagerProvider);
        $this->entityManagerProvider = $entityManagerProvider;
        $this->entityManager = $this->entityManagerProvider->getDefaultManager();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Generate entity classes from existing database tables')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'The namespace to use for generated entities', 'App\\Entity')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Destination path for generated entities', 'src/Entity')
            ->addOption('filter', null, InputOption::VALUE_OPTIONAL, 'Filter to generate specific table only')
            ->addOption('fetch-strategy', null, InputOption::VALUE_OPTIONAL, 'Default fetch strategy (LAZY, EAGER, EXTRA_LAZY)', 'LAZY')
            ->setHelp('This command generates entity classes from your existing database schema');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->entityManager;
        $namespace = $input->getOption('namespace');
        $destPath = $input->getOption('path');
        $filter = $input->getOption('filter');
        $fetchStrategy = $input->getOption('fetch-strategy');

        if (!file_exists($destPath)) {
            mkdir($destPath, 0777, true);
        }

        $connection = $em->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $tables = $schemaManager->listTables();

        if ($filter) {
            $tables = array_filter($tables, function($table) use ($filter) {
                return $table->getName() === $filter;
            });
            if (empty($tables)) {
                $output->writeln(sprintf('<error>No table found with name: %s</error>', $filter));
                return 1;
            }
        }

        $output->writeln(sprintf('Generating entities for %d tables...', count($tables)));

        $this->generatedEntities = [];
        foreach ($tables as $table) {
            $className = $this->convertToClassName($table->getName());
            $this->generatedEntities[$table->getName()] = $className;
        }

        /** @var Table $table */
        foreach ($tables as $table) {
            $className = $this->generatedEntities[$table->getName()];
            $fullClassName = sprintf('%s\\%s', $namespace, $className);

            $fileContent = $this->generateEntityClass($table, $namespace, $className, $output, $fetchStrategy);
            $filePath = sprintf('%s/%s.php', $destPath, $className);

            file_put_contents($filePath, $fileContent);
            $output->writeln(sprintf('Generated entity: %s', $className));
        }

        $output->writeln('<info>Entity generation completed successfully!</info>');
        return 0;
    }

    /**
     * @param Table $table
     * @param string $namespace
     * @param string $className
     * @return string
     */
    private function generateEntityClass(Table $table, string $namespace, string $className, $output, string $fetchStrategy = 'LAZY'): string
    {
        $properties = [];
        $gettersSetters = [];
        $relationshipProperties = [];
        $relationshipGettersSetters = [];

        foreach ($table->getColumns() as $column) {
            $originalType = $this->getColumnType($column);
            $columnType = $this->convertDbTypeToDoctrineType($originalType);
            $phpType = $this->convertDbTypeToPHPType($originalType);

            $propertyName = $column->getName();
            $isNullable = !$column->getNotnull();
            $typeHint = $isNullable ? '?' . $phpType : $phpType;

            $propertyAnnotations = [
                "#[ORM\\Column(name: \"{$propertyName}\", type: \"{$columnType}\"" .
                ($column->getLength() ? ", length: {$column->getLength()}" : "") .
                ($isNullable ? ", nullable: true" : "") .
                ($column->getDefault() !== null ? ", options: ['default' => '{$column->getDefault()}']" : "") .
                ")]"
            ];

            $propertyNameUpper = $this->covertToPascalCase($propertyName);

            if ($column->getAutoincrement()) {
                $propertyAnnotations[] = "#[ORM\\Id]";
                $propertyAnnotations[] = "#[ORM\\GeneratedValue(strategy: \"AUTO\")]";
            } else {
                if($column->getName() == "id"){
                    $propertyAnnotations[] = "#[ORM\\Id]";
                }
            }

            $properties[] = sprintf(
                "    %s\n    private %s $%s;\n",
                implode("\n    ", $propertyAnnotations),
                $typeHint,
                $propertyNameUpper
            );

            // Getter
            $gettersSetters[] = sprintf(
                "    public function get%s(): %s\n    {\n        return \$this->%s;\n    }\n",
                ucfirst($propertyNameUpper),
                $typeHint,
                $propertyNameUpper
            );

            // Setter
            $gettersSetters[] = sprintf(
                "    public function set%s(%s $%s): self\n    {\n        \$this->%s = \$%s;\n        return \$this;\n    }\n",
                ucfirst($propertyNameUpper),
                $typeHint,
                $propertyNameUpper,
                $propertyNameUpper,
                $propertyNameUpper
            );
        }

        $foreignKeys = $table->getForeignKeys();
        foreach ($foreignKeys as $foreignKey) {
            $relatedTable = $foreignKey->getForeignTableName();
            $localColumns = $foreignKey->getLocalColumns();
            $foreignColumns = $foreignKey->getForeignColumns();

            if (!isset($this->generatedEntities[$relatedTable])) {
                $output->writeln(sprintf('<comment>Skipping relationship for unknown table: %s</comment>', $relatedTable));
                continue;
            }

            $relatedClassName = $this->generatedEntities[$relatedTable];
            $relationshipType = 'ManyToOne';

            $localColumn = $localColumns[0];
            $propertyName = $this->covertToPascalCase(str_replace('_id', '', $localColumn));

            $relationshipProperties[] = sprintf(
                "    #[ORM\\%s(targetEntity: %s::class, fetch: ORM\\Mapping\\ClassMetadata::FETCH_%s)]\n" .
                "    #[ORM\\JoinColumn(name: \"%s\", referencedColumnName: \"%s\", nullable: %s)]\n" .
                "    private ?%s $%s = null;\n",
                $relationshipType,
                $namespace . '\\' . $relatedClassName,
                strtoupper($fetchStrategy),
                $localColumn,
                $foreignColumns[0],
                'true',
                $relatedClassName,
                $propertyName
            );

            $relationshipGettersSetters[] = sprintf(
                "    public function get%s(): ?%s\n    {\n        return \$this->%s;\n    }\n",
                ucfirst($propertyName),
                $relatedClassName,
                $propertyName
            );

            $relationshipGettersSetters[] = sprintf(
                "    public function set%s(?%s $%s): self\n    {\n        \$this->%s = \$%s;\n        return \$this;\n    }\n",
                ucfirst($propertyName),
                $relatedClassName,
                $propertyName,
                $propertyName,
                $propertyName
            );
        }


        $allProperties = array_merge($properties, $relationshipProperties);
        $allGettersSetters = array_merge($gettersSetters, $relationshipGettersSetters);

        return sprintf(
            "<?php\n\nnamespace %s;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity]\n#[ORM\\Table(name: \"%s\")]\nclass %s\n{\n%s\n%s}\n",
            $namespace,
            $table->getName(),
            $className,
            implode("\n", $allProperties),
            implode("\n", $allGettersSetters)
        );
    }

    /**
     * @param string $tableName
     * @return string
     */
    private function convertToClassName(string $tableName): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $tableName)));
    }

    /**
     * @param $string
     * @return string
     */
    private function covertToPascalCase($string): string
    {
        $string = str_replace('_', ' ', $string);
        $string = ucwords($string);
        return lcfirst(str_replace(' ', '', $string));
    }

    /**
     * @param string $dbType
     * @return string
     */
    private function convertDbTypeToDoctrineType(string $dbType): string
    {
        $typeMap = [
            'integer' => 'integer',
            'int' => 'integer',
            'varchar' => 'string',
            'text' => 'text',
            'datetime' => 'datetime',
            'date' => 'date',
            'time' => 'time',
            'decimal' => 'decimal',
            'float' => 'float',
            'boolean' => 'boolean',
        ];

        return $typeMap[strtolower($dbType)] ?? 'string';
    }

    /**
     * @param string $dbType
     * @return string
     */
    private function convertDbTypeToPHPType(string $dbType): string
    {
        $typeMap = [
            'integer' => 'int',
            'int' => 'int',
            'varchar' => 'string',
            'text' => 'text',
            'datetime' => '\DateTime',
            'date' => '\DateTime',
            'time' => 'time',
            'decimal' => 'decimal',
            'float' => 'float',
            'boolean' => 'bool',
        ];

        return $typeMap[strtolower($dbType)] ?? 'string';
    }

    /**
     * @param $column
     * @return string
     */
    private function getColumnType($column): string
    {
        $type = $column->getType();

        switch (true) {
            case $type instanceof \Doctrine\DBAL\Types\DateTimeType:
                return 'datetime';
            case $type instanceof \Doctrine\DBAL\Types\DateType:
                return 'date';
            case $type instanceof \Doctrine\DBAL\Types\TimeType:
                return 'time';
            case $type instanceof \Doctrine\DBAL\Types\IntegerType:
                return 'integer';
            case $type instanceof \Doctrine\DBAL\Types\StringType:
                return 'string';
            case $type instanceof \Doctrine\DBAL\Types\TextType:
                return 'text';
            case $type instanceof \Doctrine\DBAL\Types\DecimalType:
                return 'decimal';
            case $type instanceof \Doctrine\DBAL\Types\FloatType:
                return 'float';
            case $type instanceof \Doctrine\DBAL\Types\BooleanType:
                return 'boolean';
            default:
                return strtolower(substr(get_class($type), strrpos(get_class($type), '\\') + 1, -4));
        }
    }
}