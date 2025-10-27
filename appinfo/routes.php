<?php
return [
    'routes' => [
        // Page principale
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Formulaires
        ['name' => 'form#index',   'url' => '/form', 'verb' => 'GET'],
        ['name' => 'form#show',    'url' => '/form/{id}', 'verb' => 'GET'],
        ['name' => 'form#submit','url' => '/form/{id}/submit', 'verb' => 'POST'],
        ['name' => 'form#confirm', 'url' => '/confirm/{token}', 'verb' => 'GET'],

        // Redirections liées à confirmation
        ['name' => 'form#expired',  'url' => '/expired',  'verb' => 'GET'],
        ['name' => 'form#error',    'url' => '/error',    'verb' => 'GET'],

        // Gestion des parcours
        ['name' => 'page#save',           'url' => '/save', 'verb' => 'POST'],
        ['name' => 'page#createParcours', 'url' => '/createParcours', 'verb' => 'POST'],
        ['name' => 'page#saveFile',       'url' => '/parcours/{id}/saveFile', 'verb' => 'POST'],
        ['name' => 'page#deleteParcours', 'url' => '/parcours/{parcoursId}/delete', 'verb' => 'POST'],
        ['name' => 'page#updateBypass',   'url' => '/parcours/{id}/update-bypass', 'verb' => 'POST'],

        // Messages
        ['name' => 'message#getEmailMessage', 'url' => '/message/{parcoursId}', 'verb' => 'GET'],
        ['name' => 'message#saveEmailMessage','url' => '/message/{parcoursId}', 'verb' => 'POST'],
	
	// Gestion des règles d’un email (séquence)
	['name' => 'sequence#getEmailRules',  'url' => '/parcours/{parcoursId}/emails/{emailId}/rules', 'verb' => 'GET'],
	['name' => 'sequence#saveEmailRules', 'url' => '/parcours/{parcoursId}/emails/{emailId}/rules/save', 'verb' => 'POST'],

        // Edition email
        ['name' => 'sequence#editEmail', 'url' => '/parcours/{parcoursId}/emails/{emailId}/edit', 'verb' => 'POST'],

        // Séquence emails
        ['name' => 'sequence#getSequence', 'url' => '/parcours/{parcoursId}/emails', 'verb' => 'GET'],
        ['name' => 'sequence#addEmail',   'url' => '/parcours/{parcoursId}/emails/add', 'verb' => 'POST'],
        ['name' => 'sequence#deleteEmail', 'url' => '/email/{id}', 'verb' => 'DELETE'],
	['name' => 'sequence#getInscriptions', 'url' => '/parcours/{parcoursId}/inscriptions', 'verb' => 'GET'],

        // Désabonnement
	['name' => 'unsubscribe#getText', 'url' => '/parcours/{parcoursId}/unsubscribe-text', 'verb' => 'GET'],
	['name' => 'unsubscribe#saveText','url' => '/parcours/{parcoursId}/unsubscribe-text', 'verb' => 'POST'],
	['name' => 'unsubscribe#process', 'url' => '/unsubscribe', 'verb' => 'GET'],

	//déjà inscrit
	['name' => 'form#alreadyConfirm','url' => '/already-confirmed','verb' => 'GET','controller' => 'FormController','method' => 'alreadyConfirm'],
	['name' => 'form#alreadyRegistered', 'url' => '/already-registered', 'verb' => 'GET'],

	// Stop all sequences pour une inscription (ancien stopSequenceForInscription)
	['name' => 'sequence#stopAllSequence', 'url' => '/inscription/{inscriptionId}/stop-all-sequence', 'verb' => 'POST'],

	// Stop une seule séquence pour une inscription
	['name' => 'sequence#stopSingleSequence', 'url' => '/inscription/{inscriptionId}/stop-single-sequence/{sequenceId}', 'verb' => 'POST'],

	// Rediriger une inscription vers une autre séquence
	['name' => 'sequence#redirectInscription', 'url' => '/inscription/{inscriptionId}/redirect-sequence', 'verb' => 'POST'],
	['name' => 'sequence#getAllParcours', 'url' => '/parcours/all', 'verb' => 'GET'],

	// Tracking (ouverts / clics)
	['name' => 'tracking#trackOpen', 'url' => '/tracking/open', 'verb' => 'GET', 'defaults' => [],],
	['name' => 'tracking#click', 'url' => '/tracking/click', 'verb' => 'GET', 'defaults' => [],]
    ]
];
