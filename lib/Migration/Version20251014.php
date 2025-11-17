<?php

declare(strict_types=1);

namespace OCA\EmailBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version20251014 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // IMPORTANT: utiliser exactement le même nom que dans Version20251013
        if ($schema->hasTable('oc_emailbridge_parcours')) {
            $table = $schema->getTable('oc_emailbridge_parcours');

            if ($table->hasColumn('bypass_file')) {
                $column = $table->getColumn('bypass_file');

                // Autoriser NULL
                $column->setNotNull(false);

                // Mettre un défaut compatible
                $column->setDefault(0);
            }
        }

        return $schema;
    }
}

