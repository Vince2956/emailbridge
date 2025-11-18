<?php

declare(strict_types=1);

namespace OCA\EmailBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version20251015 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $oldNames = [
            'oc_emailbridge_parcours'     => 'emailbridge_parcours',
            'oc_emailbridge_liste'        => 'emailbridge_liste',
            'oc_emailbridge_inscription'  => 'emailbridge_inscription',
            'oc_emailbridge_sequence'     => 'emailbridge_sequence',
            'oc_emailbridge_envoi'        => 'emailbridge_envoi',
            'oc_emailbridge_stats'        => 'emailbridge_stats',
            'oc_emailbridge_form'         => 'emailbridge_form',
        ];

        foreach ($oldNames as $old => $new) {
            if ($schema->hasTable($old) && !$schema->hasTable($new)) {
                $schema->renameTable($old, $new);
            }
        }

        return $schema;
    }
}

