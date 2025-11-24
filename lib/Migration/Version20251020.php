<?php

declare(strict_types=1);

namespace OCA\EmailBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version20251020 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $typeDateTime = defined('\OCP\DB\Types::DATETIME_MUTABLE')
            ? \OCP\DB\Types::DATETIME_MUTABLE
            : Types::DATETIME;

        // ----------------------------
        // Table: emailbridge_parcours
        // ----------------------------
        if (!$schema->hasTable('emailbridge_parcours')) {
            $table = $schema->createTable('emailbridge_parcours');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'unsigned' => true]);
            $table->addColumn('titre', Types::STRING, ['length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', $typeDateTime);
            $table->addColumn('updated_at', $typeDateTime);
            $table->addColumn('document_url', Types::STRING, ['length' => 512, 'notnull' => false]);
            $table->addColumn('bypass_file', Types::BOOLEAN, ['notnull' => false, 'default' => 0]);
            $table->addColumn('unsubscribe_text', Types::TEXT, ['notnull' => false]);
            $table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->setPrimaryKey(['id'], 'parcours_pk');
        }

        // ----------------------------
        // Table: emailbridge_liste
        // ----------------------------
        if (!$schema->hasTable('emailbridge_liste')) {
            $table = $schema->createTable('emailbridge_liste');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'unsigned' => true]);
            $table->addColumn('email', Types::STRING, ['length' => 255]);
            $table->addColumn('parcours_id', Types::INTEGER, ['unsigned' => true]);
            $table->addColumn('token', Types::STRING, ['length' => 255]);
            $table->addColumn('confirmed', Types::BOOLEAN, ['default' => 0, 'notnull' => false]);
            $table->addColumn('created_at', $typeDateTime);
            $table->addColumn('confirmed_at', $typeDateTime, ['notnull' => false]);
            $table->addColumn('document_url', Types::TEXT, ['notnull' => false]);

            $table->setPrimaryKey(['id'], 'liste_pk');
            $table->addUniqueIndex(['token'], 'liste_token');

            $table->addForeignKeyConstraint(
                'oc_emailbridge_parcours',
                ['parcours_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_liste_parcours'
            );
        }

        // ----------------------------
        // Table: emailbridge_inscription
        // ----------------------------
        if (!$schema->hasTable('emailbridge_inscription')) {
            $table = $schema->createTable('emailbridge_inscription');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'unsigned' => true]);
            $table->addColumn('parcours_id', Types::INTEGER, ['unsigned' => true]);
            $table->addColumn('liste_id', Types::INTEGER, ['unsigned' => true]);
            $table->addColumn('email', Types::STRING, ['length' => 255]);
            $table->addColumn('date_inscription', $typeDateTime);
            $table->addColumn('bypass_file', Types::BOOLEAN, ['default' => 0, 'notnull' => false]);
            $table->addColumn('created_at', $typeDateTime);
            $table->addColumn('updated_at', $typeDateTime);
            $table->addColumn('is_unsubscribed', Types::BOOLEAN, ['default' => 0, 'notnull' => false]);

            $table->setPrimaryKey(['id'], 'inscription_pk');
            $table->addIndex(['parcours_id'], 'inscription_par');
            $table->addIndex(['liste_id'], 'inscription_lst');

            $table->addForeignKeyConstraint(
                'oc_emailbridge_parcours',
                ['parcours_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_insc_parcours'
            );

            $table->addForeignKeyConstraint(
                'oc_emailbridge_liste',
                ['liste_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_insc_liste'
            );
        }

        // ----------------------------
        // Table: emailbridge_sequence
        // ----------------------------
        if (!$schema->hasTable('emailbridge_sequence')) {
            $table = $schema->createTable('emailbridge_sequence');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'unsigned' => true]);
            $table->addColumn('parcours_id', Types::INTEGER, ['unsigned' => true]);
            $table->addColumn('sujet', Types::STRING, ['length' => 255]);
            $table->addColumn('contenu', Types::TEXT);
            $table->addColumn('send_day', Types::INTEGER, ['default' => 0]);
            $table->addColumn('send_time', Types::TIME, ['notnull' => false]);
            $table->addColumn('created_at', $typeDateTime);
            $table->addColumn('updated_at', $typeDateTime);
            $table->addColumn('delay_minutes', Types::INTEGER, ['default' => 10]);
            $table->addColumn('rules', Types::TEXT, ['notnull' => false]);

            $table->setPrimaryKey(['id'], 'sequence_pk');

            $table->addForeignKeyConstraint(
                'oc_emailbridge_parcours',
                ['parcours_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_seq_parcours'
            );
        }

        // ----------------------------
        // Table: emailbridge_envoi
        // ----------------------------
        if (!$schema->hasTable('emailbridge_envoi')) {
            $table = $schema->createTable('emailbridge_envoi');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'unsigned' => true]);
            $table->addColumn('inscription_id', Types::INTEGER, ['unsigned' => true]);
            $table->addColumn('sequence_id', Types::INTEGER, ['unsigned' => true]);
            $table->addColumn('send_at', $typeDateTime);
            $table->addColumn('status', Types::STRING, ['length' => 20, 'default' => 'en_attente']);
            $table->addColumn('attempts', Types::INTEGER, ['default' => 0]);
            $table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', $typeDateTime);
            $table->addColumn('updated_at', $typeDateTime);

            $table->setPrimaryKey(['id'], 'envoi_pk');
            $table->addIndex(['inscription_id'], 'envoi_ins');
            $table->addIndex(['sequence_id'], 'envoi_seq');
            $table->addIndex(['send_at'], 'envoi_send');

            $table->addForeignKeyConstraint(
                'oc_emailbridge_inscription',
                ['inscription_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_env_inscription'
            );

            $table->addForeignKeyConstraint(
                'oc_emailbridge_sequence',
                ['sequence_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_env_sequence'
            );
        }

        // ----------------------------
        // Table: emailbridge_stats
        // ----------------------------
        if (!$schema->hasTable('emailbridge_stats')) {
            $table = $schema->createTable('emailbridge_stats');
            $table->addColumn('email_id', Types::INTEGER, ['unsigned' => true]);
            $table->addColumn('inscription_id', Types::INTEGER, ['unsigned' => true]);
            $table->addColumn('sent', Types::INTEGER, ['default' => 0]);
            $table->addColumn('opened', Types::INTEGER, ['default' => 0]);
            $table->addColumn('clicked', Types::INTEGER, ['default' => 0]);
            $table->addColumn('unsubscribed', Types::INTEGER, ['default' => 0]);
            $table->addColumn('stopped', Types::INTEGER, ['default' => 0]);
            $table->addColumn('redirected', Types::INTEGER, ['default' => 0]);
            $table->addColumn('updated_at', $typeDateTime);

            $table->setPrimaryKey(['email_id', 'inscription_id'], 'stats_pk');
            $table->addIndex(['inscription_id'], 'stats_ins');

            $table->addForeignKeyConstraint(
                'oc_emailbridge_sequence',
                ['email_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_stats_seq'
            );

            $table->addForeignKeyConstraint(
                'oc_emailbridge_inscription',
                ['inscription_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_stats_insc'
            );
        }

        // ----------------------------
        // Table: emailbridge_form
        // ----------------------------
        if (!$schema->hasTable('emailbridge_form')) {
            $table = $schema->createTable('emailbridge_form');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'unsigned' => true]);
            $table->addColumn('parcours_id', Types::INTEGER, ['unsigned' => true]);
            $table->addColumn('titre', Types::STRING, ['length' => 255, 'notnull' => false]);
            $table->addColumn('type', Types::STRING, ['length' => 20]);
            $table->addColumn('contenu_text', Types::TEXT, ['notnull' => false]);
            $table->addColumn('label_bouton', Types::STRING, ['length' => 255, 'notnull' => false]);
            $table->addColumn('document_url', Types::STRING, ['length' => 512, 'notnull' => false]);
            $table->addColumn('ordre', Types::INTEGER, ['default' => 0]);
            $table->addColumn('is_default', Types::BOOLEAN, ['default' => 0, 'notnull' => false]);
            $table->addColumn('created_at', $typeDateTime);
            $table->addColumn('updated_at', $typeDateTime);

            $table->setPrimaryKey(['id'], 'form_pk');
            $table->addIndex(['parcours_id'], 'form_par');

            $table->addForeignKeyConstraint(
                'oc_emailbridge_parcours',
                ['parcours_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_form_parcours'
            );
        }

        return $schema;
    }
}
