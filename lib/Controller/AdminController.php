<?php
namespace OCA\EmailBridge\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IDBConnection;
use OCP\IConfig;

class AdminController extends Controller {

    private IDBConnection $db;
    private IConfig $config;

    public function __construct(
        string $appName,
        IRequest $request,
        IDBConnection $db,
        IConfig $config
    ) {
        parent::__construct($appName, $request);
        $this->db = $db;
        $this->config = $config;
    }

/**
 * Page admin
 * @AdminRequired
 */
public function index(): TemplateResponse {

    $delete = $this->config->getAppValue('emailbridge', 'delete_on_uninstall', '0');
    $deleteBool = $delete === '1';

    // 🔹 Récupération config HelloAsso
    $slug = $this->config->getAppValue('emailbridge', 'helloasso_slug', '');
    $clientId = $this->config->getAppValue('emailbridge', 'helloasso_client_id', '');
    $clientSecret = $this->config->getAppValue('emailbridge', 'helloasso_client_secret', '');

    return new TemplateResponse('emailbridge', 'settings/admin', [
        'delete_on_uninstall' => $deleteBool,
        'helloasso_slug' => $slug,
        'helloasso_client_id' => $clientId,
        'helloasso_client_secret' => $clientSecret,
    ],
    ''
    );
}


/**
 * RESET DE TOUTES LES DONNÉES
 * @AdminRequired
 * @NoCSRFRequired
 */
public function reset(): DataResponse {

    $tables = [
        'emailbridge_parcours',
        'emailbridge_liste',
        'emailbridge_inscription',
        'emailbridge_sequence',
        'emailbridge_envoi',
        'emailbridge_stats',
        'emailbridge_form',
    ];

    try {
        $this->db->beginTransaction();

        foreach ($tables as $table) {
            $this->db->executeStatement("DELETE FROM *PREFIX*$table");
        }

        $this->db->commit();

        return new DataResponse([
            'status' => 'ok',
            'message' => 'Toutes les données ont été supprimées.'
        ]);

    } catch (\Throwable $e) {
        $this->db->rollBack();

        return new DataResponse([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
}



	/**
 * Sauvegarde paramètre
 * @AdminRequired
 * @NoCSRFRequired  <-- tu peux laisser pour simplifier, ou gérer le token
 */
public function saveSettings(): DataResponse {
    $params = json_decode(file_get_contents('php://input'), true);

    $delete = !empty($params['delete_on_uninstall']) ? '1' : '0';

    $this->config->setAppValue('emailbridge', 'delete_on_uninstall', $delete);

    return new DataResponse(['status' => 'ok']);
}


    /**
     * EXPORT
     * @NoCSRFRequired
     * @AdminRequired
     */
    public function export(): StreamResponse {

        $tables = [
            'parcours'      => 'emailbridge_parcours',
            'liste'         => 'emailbridge_liste',
            'inscription'   => 'emailbridge_inscription',
            'sequence'      => 'emailbridge_sequence',
            'envoi'         => 'emailbridge_envoi',
            'stats'         => 'emailbridge_stats',
            'form'          => 'emailbridge_form',
        ];

        $data = [];

        foreach ($tables as $key => $table) {
            $result = $this->db->executeQuery("SELECT * FROM *PREFIX*$table");
            $data[$key] = $result->fetchAll();
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $tmpFile = tempnam(sys_get_temp_dir(), 'emailbridge-export');
        file_put_contents($tmpFile, $json);

        return new StreamResponse($tmpFile, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="emailbridge-backup.json"',
        ]);
    }

    /**
     * IMPORT
     * @AdminRequired
     */
    public function import(): DataResponse {

        $json = json_decode(file_get_contents('php://input'), true);

        if (!$json || !is_array($json)) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'JSON invalide'
            ]);
        }

        $tables = [
            'parcours'      => 'emailbridge_parcours',
            'liste'         => 'emailbridge_liste',
            'inscription'   => 'emailbridge_inscription',
            'sequence'      => 'emailbridge_sequence',
            'envoi'         => 'emailbridge_envoi',
            'stats'         => 'emailbridge_stats',
            'form'          => 'emailbridge_form',
        ];

        try {

            $this->db->beginTransaction();

            foreach ($tables as $key => $table) {

                if (empty($json[$key]) || !is_array($json[$key])) {
                    continue;
                }

                foreach ($json[$key] as $row) {

                    $columns = array_keys($row);
                    $placeholders = implode(',', array_fill(0, count($columns), '?'));
                    $updates = implode(',', array_map(fn($c) => "$c = VALUES($c)", $columns));

                    $sql = "INSERT INTO *PREFIX*$table (" . implode(',', $columns) . ")
                            VALUES ($placeholders)
                            ON DUPLICATE KEY UPDATE $updates";

                    $this->db->executeStatement($sql, array_values($row));
                }
            }

            $this->db->commit();

        } catch (\Throwable $e) {

            $this->db->rollBack();

            return new DataResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }

        return new DataResponse(['status' => 'ok']);
    }
    

/**
 * Sauvegarde des paramètres HelloAsso
 * @AdminRequired
 * @NoCSRFRequired
 */
public function saveHelloAssoKey(): DataResponse {

    $params = json_decode(file_get_contents('php://input'), true);

    if (empty($params['slug']) || empty($params['clientId']) || empty($params['clientSecret'])) {
        return new DataResponse([
            'status' => 'error',
            'message' => 'Slug, ClientId ou ClientSecret manquant'
        ]);
    }

    try {

        $this->config->setAppValue('emailbridge', 'helloasso_slug', $params['slug']);
        $this->config->setAppValue('emailbridge', 'helloasso_client_id', $params['clientId']);
        $this->config->setAppValue('emailbridge', 'helloasso_client_secret', $params['clientSecret']);

        return new DataResponse([
            'status' => 'ok',
            'message' => 'Configuration enregistrée'
        ]);

    } catch (\Throwable $e) {

        return new DataResponse([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Récupère les produits HelloAsso et met à jour la table emailbridge_product
 * @AdminRequired
 * @NoCSRFRequired
 */
public function fetchHelloAssoProducts(): DataResponse {
    $clientId        = $this->config->getAppValue('emailbridge', 'helloasso_client_id', '');
    $clientSecret    = $this->config->getAppValue('emailbridge', 'helloasso_client_secret', '');
    $organisationSlug = $this->config->getAppValue('emailbridge', 'helloasso_slug', '');

    if (!$clientId || !$clientSecret || !$organisationSlug) {
        return new DataResponse([
            'status'  => 'error',
            'message' => 'Configuration HelloAsso incomplète'
        ]);
    }

    try {
        // 1️⃣ Récupération du token OAuth
        $tokenResponse = file_get_contents(
            'https://api.helloasso.com/oauth2/token',
            false,
            stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/x-www-form-urlencoded",
                    'content' => http_build_query([
                        'grant_type'    => 'client_credentials',
                        'client_id'     => $clientId,
                        'client_secret' => $clientSecret,
                    ])
                ]
            ])
        );

        if ($tokenResponse === false) {
            throw new \Exception('Erreur lors de la récupération du token');
        }

        $tokenData = json_decode($tokenResponse, true);
        if (empty($tokenData['access_token'])) {
            throw new \Exception('Token OAuth invalide');
        }
        $accessToken = $tokenData['access_token'];

        // 2️⃣ Récupération des formulaires HelloAsso
        $productsResponse = file_get_contents(
            "https://api.helloasso.com/v5/organizations/$organisationSlug/forms",
            false,
            stream_context_create([
                'http' => [
                    'method'  => 'GET',
                    'header'  => "Authorization: Bearer $accessToken\r\nAccept: application/json"
                ]
            ])
        );

        if ($productsResponse === false) {
            throw new \Exception('Erreur lors de la récupération des formulaires');
        }

        $productsData = json_decode($productsResponse, true);
        $forms = $productsData['data'] ?? [];

        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // 3️⃣ Mettre à jour ou insérer les produits
        foreach ($forms as $product) {
            $formSlug = $product['formSlug'] ?? null;
            $name     = $product['title'] ?? '';
            if (!$formSlug) continue;

            $qbCheck = $this->db->getQueryBuilder();
            $qbCheck->select('helloasso_item_id')
                    ->from('emailbridge_product')
                    ->where($qbCheck->expr()->eq('helloasso_item_id', $qbCheck->createNamedParameter($formSlug)));
            $exists = $qbCheck->executeQuery()->fetchOne();

            $qb = $this->db->getQueryBuilder();
            if ($exists) {
                $qb->update('emailbridge_product')
                   ->set('item_name', $qb->createNamedParameter($name))
                   ->set('updated_at', $qb->createNamedParameter($nowUtc))
                   ->where($qb->expr()->eq('helloasso_item_id', $qb->createNamedParameter($formSlug)))
                   ->executeStatement();
            } else {
                $qb->insert('emailbridge_product')
                   ->values([
                       'helloasso_item_id' => $qb->createNamedParameter($formSlug),
                       'item_name' => $qb->createNamedParameter($name),
                       'active' => $qb->createNamedParameter(0),
                       'created_at' => $qb->createNamedParameter($nowUtc),
                       'updated_at' => $qb->createNamedParameter($nowUtc)
                   ])
                   ->executeStatement();
            }
        }

        // 4️⃣ Lire les produits avec leur status actif
$qbSelect = $this->db->getQueryBuilder();
$rowsIndexed = $qbSelect->select('id', 'helloasso_item_id', 'item_name', 'amount', 'active', 'created_at', 'updated_at')
                        ->from('emailbridge_product')
                        ->executeQuery()
                        ->fetchAll();

$rows = [];
foreach ($rowsIndexed as $row) {
    $rows[] = [
        'id' => $row['id'] ?? null,
        'helloasso_item_id' => $row['helloasso_item_id'] ?? null,
        'item_name' => $row['item_name'] ?? null,
        'title' => $row['item_name'] ?? null,
        'amount' => $row['amount'] ?? 0,
        'active' => $row['active'] ?? 0,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

return new DataResponse([
    'status'   => 'ok',
    'products' => ['data' => $rows]
]);

    } catch (\Throwable $e) {
        return new DataResponse([
            'status'  => 'error',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Sauvegarde la sélection des produits HelloAsso et met à jour active
 * @AdminRequired
 */
public function saveHelloAssoSelection(): DataResponse {
    $params = json_decode(file_get_contents('php://input'), true);
    $selected = $params['selected'] ?? [];

    try {
        $this->config->setAppValue(
            'emailbridge',
            'helloasso_selected_products',
            json_encode($selected)
        );

        $qb = $this->db->getQueryBuilder();

        // Désactiver tous les produits
        $qb->update('emailbridge_product')
           ->set('active', $qb->createNamedParameter(0))
           ->executeStatement();

        // Activer uniquement les produits sélectionnés
        foreach ($selected as $formSlug) {
            $qbUpdate = $this->db->getQueryBuilder();
            $qbUpdate->update('emailbridge_product')
                     ->set('active', $qbUpdate->createNamedParameter(1))
                     ->where($qbUpdate->expr()->eq('helloasso_item_id', $qbUpdate->createNamedParameter($formSlug)))
                     ->executeStatement();
        }

        return new DataResponse([
            'status' => 'ok'
        ]);

    } catch (\Throwable $e) {
        return new DataResponse([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
}
}

