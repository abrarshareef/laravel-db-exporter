<?php

namespace ViKon\DbExporter\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use ViKon\DbExporter\DatabaseHelper;
use ViKon\DbExporter\ModelMetaData;
use ViKon\DbExporter\TemplateHelper;

class ModelsCommand extends Command {
    use DatabaseHelper, TemplateHelper;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'db-exporter:models';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create models from database tables';

    /**
     * Create a new command instance.
     */
    public function __construct() {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire() {
        $this->info('Creating models from database...');

        $tables = $this->makeMetaData();

        $this->makeRelations($tables);

        foreach ($tables as $table) {
            $foreignKeySource = $table->getRelationsSource();
            $this->writeModelFile($table->getClass(false), snake_case($table->getName(true)), $foreignKeySource);
        }

        $this->info('Models successfully created');
    }

    /**
     * Build up meta data
     *
     * @return \ViKon\DbExporter\ModelMetaData[]
     */
    protected function makeMetaData() {
        $tableNames = $this->getTableNames($this->option('database'));

        $tables = [];
        foreach ($tableNames as $tableName) {
            if (in_array($tableName, $this->option('ignore'))) {
                continue;
            }

            $tables[$tableName] = new ModelMetaData($tableName, $this->option('database'), $this->option('namespace'), $this->option('prefix'));
        }

        return $tables;
    }

    /**
     * @param \ViKon\DbExporter\ModelMetaData[] $tables
     */
    protected function makeRelations(array $tables) {
        foreach ($tables as $table) {
            foreach ($table->getForeignKeys() as $foreignKey) {
                if (!isset($tables[$foreignKey->getForeignTableName()])) {
                    continue;
                }

                $foreignTable = $tables[$foreignKey->getForeignTableName()];

                $localIndex = $table->getIndexByColumn($foreignKey->getLocalColumns());
                $foreignIndex = $foreignTable->getIndexByColumn($foreignKey->getForeignColumns());

                $localTableClass = $table->getClass();
                $foreignTableClass = $foreignTable->getClass();

                $localColumn = $foreignKey->getLocalColumns();
                $localColumn = reset($localColumn);
                $foreignColumn = $foreignKey->getForeignColumns();
                $foreignColumn = reset($foreignColumn);

                // Guess foreign method name
                $localMethodName = str_replace(['_id'], '', snake_case($localColumn));
                $foreignMethodName = snake_case($table->getName());

                // Try to find out connection type
                if ($localIndex !== false && $foreignIndex !== false && $localIndex->isUnique() && $foreignIndex->isUnique()) {
                    // One To One
                    $table->addHasOneRelation($foreignTableClass, $localMethodName, $foreignColumn, $localColumn);
                    $foreignTable->addBelongsToRelation($localTableClass, $foreignMethodName, $localColumn, $foreignColumn);
                } elseif ($foreignIndex !== false && $foreignIndex->isUnique()) {
                    // Many To One
                    $table->addBelongsToRelation($foreignTableClass, $localMethodName, $localColumn, $foreignColumn);
                    $foreignTable->addHasManyRelation($localTableClass, $foreignMethodName, $localColumn, $foreignColumn);
                } elseif ($localIndex !== false && $localIndex->isUnique()) {
                    // One To Many
                    $table->addHasManyRelation($foreignTableClass, $localMethodName, $foreignColumn, $localColumn);
                    $foreignTable->addBelongsToRelation($localTableClass, $foreignMethodName, $localColumn, $foreignColumn);
                } else {
                    // Many To Many without pivot table
                    $table->addHasManyRelation($foreignTableClass, $localMethodName, $foreignColumn, $localColumn);
                    $foreignTable->addHasManyRelation($localTableClass, $foreignMethodName, $localColumn, $foreignColumn);
                }
            }
        }
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions() {
        return [
            ['prefix', null, InputOption::VALUE_OPTIONAL, 'Table prefix in models', config('db-exporter.prefix')],
            ['select', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Select specified database tables only', config('db-exporter.select')],
            ['ignore', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Ignore specified database tables', config('db-exporter.ignore')],
            ['database', null, InputOption::VALUE_OPTIONAL, 'Specify database name', config('db-exporter.database')],
            ['overwrite', null, InputOption::VALUE_NONE, 'Overwrite exists models'],
            ['namespace', null, InputOption::VALUE_OPTIONAL, 'Models base namespace', config('db-exporter.model.namespace')],
            ['path', null, InputOption::VALUE_OPTIONAL, 'Output destination path relative to project root', config('db-exporter.model.path')],
        ];
    }

    /**
     * Write model to file
     *
     * @param string $className   class name (and file names)
     * @param string $tableName   model's table name
     * @param string $foreignKeys rendered foreign keys
     */
    protected function writeModelFile($className, $tableName, $foreignKeys) {
        $this->writeToFileFromTemplate('model', $className . '.php', [
            '{{namespace}}'   => $this->option('namespace'),
            '{{className}}'   => $className,
            '{{tableName}}'   => $tableName,
            '{{foreignKeys}}' => $foreignKeys,
        ]);
    }

}
