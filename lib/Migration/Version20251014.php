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

        // IMPORTANT: pas de préfixe "oc_"
        if ($schema->hasTable('emailbridge_parcours')) {
            $table = $schema->getTable('emailbridge_parcours');

            if ($table->hasColumn('bypass_file')) {
                $column = $table->getColumn('bypass_file');

                // Autoriser NULL
                $column->setNotNull(false);

                // Par défaut : false (0) MAIS doit correspondre au type
                $column->setDefault(0);
            }
        }

        return $schema;
    }
}

