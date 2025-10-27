<?php
declare(strict_types=1);

namespace OCA\EmailBridge\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version20251013 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ----------------------------
        // Table: oc_emailbridge_parcours
        // ----------------------------
        if (!$schema->hasTable('oc_emailbridge_parcours')) {
            $table = $schema->createTable('oc_emailbridge_parcours');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $table->addColumn('titre', Types::STRING, ['length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::DATETIME_MUTABLE);
            $table->addColumn('updated_at', Types::DATETIME_MUTABLE);
            $table->addColumn('document_url', Types::STRING, ['length' => 512, 'notnull' => false]);
            $table->addColumn('bypass_file', Types::BOOLEAN, ['notnull' => true,'default' => false,]);
	    $table->addColumn('unsubscribe_text', Types::TEXT, ['notnull' => false]);
	    $table->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->setPrimaryKey(['id'], 'parc_pk');
        }

        // ----------------------------
        // Table: oc_emailbridge_liste
        // ----------------------------
        if (!$schema->hasTable('oc_emailbridge_liste')) {
            $table = $schema->createTable('oc_emailbridge_liste');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $table->addColumn('email', Types::STRING, ['length' => 255]);
            $table->addColumn('parcours_id', Types::INTEGER);
            $table->addColumn('token', Types::STRING, ['length' => 255]);
            $table->addColumn('confirmed', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
            $table->addColumn('created_at', Types::DATETIME);
            $table->addColumn('confirmed_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('document_url', Types::TEXT, ['notnull' => false]);
            $table->setPrimaryKey(['id'], 'liste_pk');
            $table->addUniqueIndex(['token'], 'liste_tok');

            // clé étrangère parcours_id → parcours.id
            $table->addForeignKeyConstraint(
                'oc_emailbridge_parcours',
                ['parcours_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_liste_parcours'
            );
        }

        // ----------------------------
	// Table: oc_emailbridge_inscription
	// ----------------------------
	if (!$schema->hasTable('oc_emailbridge_inscription')) {
	    $table = $schema->createTable('oc_emailbridge_inscription');
	    $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
	    $table->addColumn('parcours_id', Types::INTEGER);
	    $table->addColumn('liste_id', Types::INTEGER);
	    $table->addColumn('email', Types::STRING, ['length' => 255]);
	    $table->addColumn('date_inscription', Types::DATETIME);
	    $table->addColumn('bypass_file', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
	    $table->addColumn('created_at', Types::DATETIME);
	    $table->addColumn('updated_at', Types::DATETIME);
	    $table->addColumn('is_unsubscribed', Types::BOOLEAN, ['default' => false, 'notnull' => false]);
	    $table->setPrimaryKey(['id'], 'insc_pk');
	    $table->addIndex(['parcours_id'], 'ins_par');
	    $table->addIndex(['liste_id'], 'ins_lst');

	    // clé étrangère parcours_id → parcours.id
	    $table->addForeignKeyConstraint(
	        'oc_emailbridge_parcours',
	        ['parcours_id'],
	        ['id'],
	        ['onDelete' => 'CASCADE'],
	        'fk_insc_parcours'
	    );
	
	    // clé étrangère liste_id → liste.id
	    $table->addForeignKeyConstraint(
	        'oc_emailbridge_liste',
	        ['liste_id'],
	        ['id'],
	        ['onDelete' => 'CASCADE'],
	        'fk_insc_liste'
	    );
	}


        // ----------------------------
        // Table: oc_emailbridge_sequence
        // ----------------------------
        if (!$schema->hasTable('oc_emailbridge_sequence')) {
            $table = $schema->createTable('oc_emailbridge_sequence');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $table->addColumn('parcours_id', Types::INTEGER);
            $table->addColumn('sujet', Types::STRING, ['length' => 255]);
            $table->addColumn('contenu', Types::TEXT);
            $table->addColumn('send_day', Types::INTEGER, ['default' => 0]);
            $table->addColumn('send_time', Types::TIME, ['notnull' => false]);
            $table->addColumn('created_at', Types::DATETIME);
            $table->addColumn('updated_at', Types::DATETIME);
            $table->addColumn('delay_minutes', Types::INTEGER, ['default' => 10]);
	    $table->addColumn('rules', Types::TEXT, ['notnull' => false]);
            $table->setPrimaryKey(['id'], 'seq_pk');

            // clé étrangère parcours_id → parcours.id
            $table->addForeignKeyConstraint(
                'oc_emailbridge_parcours',
                ['parcours_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_seq_parcours'
            );
        }

        // ----------------------------
        // Table: oc_emailbridge_envoi
        // ----------------------------
        if (!$schema->hasTable('oc_emailbridge_envoi')) {
            $table = $schema->createTable('oc_emailbridge_envoi');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $table->addColumn('inscription_id', Types::INTEGER);
            $table->addColumn('sequence_id', Types::INTEGER);
            $table->addColumn('send_at', Types::DATETIME);
            $table->addColumn('status', Types::STRING, ['length' => 20, 'default' => 'en_attente']);
            $table->addColumn('attempts', Types::INTEGER, ['default' => 0]);
            $table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::DATETIME);
            $table->addColumn('updated_at', Types::DATETIME);
            $table->setPrimaryKey(['id'], 'env_pk');
            $table->addIndex(['inscription_id'], 'en_ins');
            $table->addIndex(['sequence_id'], 'en_seq');
            $table->addIndex(['send_at'], 'en_snd');

            // clé étrangère inscription_id → inscription.id
            $table->addForeignKeyConstraint(
                'oc_emailbridge_inscription',
                ['inscription_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_env_insc'
            );

            // clé étrangère sequence_id → sequence.id
            $table->addForeignKeyConstraint(
                'oc_emailbridge_sequence',
                ['sequence_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_env_seq'
            );
        }

        // ----------------------------
        // Table: oc_emailbridge_stats
        // ----------------------------
        if (!$schema->hasTable('oc_emailbridge_stats')) {
            $table = $schema->createTable('oc_emailbridge_stats');
            $table->addColumn('email_id', Types::INTEGER);
            $table->addColumn('inscription_id', Types::INTEGER);
            $table->addColumn('sent', Types::INTEGER, ['default' => 0]);
            $table->addColumn('opened', Types::INTEGER, ['default' => 0]);
            $table->addColumn('clicked', Types::INTEGER, ['default' => 0]);
            $table->addColumn('unsubscribed', Types::INTEGER, ['default' => 0]);
            $table->addColumn('stopped', Types::INTEGER, ['default' => 0]);
            $table->addColumn('redirected', Types::INTEGER, ['default' => 0]);
            $table->addColumn('updated_at', Types::DATETIME);
            $table->setPrimaryKey(['email_id', 'inscription_id'], 'stats_pk');
            $table->addIndex(['inscription_id'], 'stats_ins');

            // clé étrangère email_id → sequence.id
            $table->addForeignKeyConstraint(
                'oc_emailbridge_sequence',
                ['email_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_stats_seq'
            );

            // clé étrangère inscription_id → inscription.id
            $table->addForeignKeyConstraint(
                'oc_emailbridge_inscription',
                ['inscription_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_stats_insc'
            );
        }

        // ----------------------------
        // Table: oc_emailbridge_form
        // ----------------------------
        if (!$schema->hasTable('oc_emailbridge_form')) {
            $table = $schema->createTable('oc_emailbridge_form');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $table->addColumn('parcours_id', Types::INTEGER);
            $table->addColumn('titre', Types::STRING, ['length' => 255, 'notnull' => false]);
            $table->addColumn('type', Types::STRING, ['length' => 20]);
            $table->addColumn('contenu_text', Types::TEXT, ['notnull' => false]);
            $table->addColumn('label_bouton', Types::STRING, ['length' => 255, 'notnull' => false]);
            $table->addColumn('document_url', Types::STRING, ['length' => 512, 'notnull' => false]);
            $table->addColumn('ordre', Types::INTEGER, ['default' => 0]);
            $table->addColumn('is_default', Types::BOOLEAN, ['default' => 0, 'notnull' => false]);
            $table->addColumn('created_at', Types::DATETIME);
            $table->addColumn('updated_at', Types::DATETIME);
            $table->setPrimaryKey(['id'], 'form_pk');
            $table->addIndex(['parcours_id'], 'form_par');

            // clé étrangère parcours_id → parcours.id
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
