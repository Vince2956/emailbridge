<?php

declare(strict_types=1);

namespace OCA\EmailBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version20260302 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $typeDateTime = defined('\OCP\DB\Types::DATETIME_MUTABLE')
            ? \OCP\DB\Types::DATETIME_MUTABLE
            : Types::DATETIME;

        /*
         * -------------------------------------------------
         * 1️⃣ Ajouter colonne helloasso_item_id dans parcours
         * -------------------------------------------------
         */
        if ($schema->hasTable('emailbridge_parcours')) {
            $table = $schema->getTable('emailbridge_parcours');

            if (!$table->hasColumn('helloasso_item_id')) {
                $table->addColumn('helloasso_item_id', Types::STRING, [
                    'length' => 255,
                    'notnull' => false
                ]);
            }
        }

        /*
         * --------------------------------
         * 2️⃣ Table: emailbridge_product
         * --------------------------------
         */
        if (!$schema->hasTable('emailbridge_product')) {

            $table = $schema->createTable('emailbridge_product');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'unsigned' => true
            ]);

            $table->addColumn('helloasso_item_id', Types::STRING, [
                'length' => 255,
                'notnull' => true
            ]);

            $table->addColumn('item_name', Types::STRING, [
                'length' => 255,
                'notnull' => true
            ]);

            $table->addColumn('amount', Types::INTEGER, [
                'notnull' => true,
                'default' => 0
            ]);

            $table->addColumn('active', Types::BOOLEAN, [
                'notnull' => false,
                'default' => true
            ]);

            $table->addColumn('created_at', $typeDateTime, [
                'notnull' => true
            ]);

            $table->addColumn('updated_at', $typeDateTime, [
                'notnull' => true
            ]);

            $table->setPrimaryKey(['id'], 'ha_product_pk');
            $table->addUniqueIndex(['helloasso_item_id'], 'ha_item_unique');
        }

        /*
         * ----------------------------------------
         * 3️⃣ Table: emailbridge_helloasso_order
         * ----------------------------------------
         */
        if (!$schema->hasTable('emailbridge_helloasso_order')) {

            $table = $schema->createTable('emailbridge_helloasso_order');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'unsigned' => true
            ]);

            $table->addColumn('helloasso_order_id', Types::STRING, [
                'length' => 255,
                'notnull' => true
            ]);

            $table->addColumn('email', Types::STRING, [
                'length' => 255,
                'notnull' => true
            ]);

            $table->addColumn('processed', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false
            ]);

            $table->addColumn('created_at', $typeDateTime, [
                'notnull' => true
            ]);

            $table->setPrimaryKey(['id'], 'ha_order_pk');
            $table->addUniqueIndex(['helloasso_order_id'], 'ha_order_unique');
        }

        return $schema;
    }
}
