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
    $deleteBool = $delete === '1' ? true : false;

    return new TemplateResponse('emailbridge', 'settings/admin', [
        'delete_on_uninstall' => $deleteBool
    ]);
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
 * RESET DATA
 * @AdminRequired
 * @NoCSRFRequired  <-- ou gérer le token
 */
public function resetData(): DataResponse {

    $tables = [
        'emailbridge_stats',
        'emailbridge_envoi',
        'emailbridge_inscription',
        'emailbridge_sequence',
        'emailbridge_form',
        'emailbridge_liste',
        'emailbridge_parcours'
    ];

    try {
        $this->db->beginTransaction();

        foreach ($tables as $table) {
            $this->db->executeStatement("DELETE FROM *PREFIX*$table");
        }

        // Supprimer les jobs liés à EmailBridge
        $this->db->executeStatement(
            "DELETE FROM *PREFIX*jobs WHERE class LIKE '%emailbridge%' OR argument LIKE '%emailbridge%'"
        );

        $this->db->commit();

        // Supprimer les configs
        foreach ($this->config->getAppKeys('emailbridge') as $key) {
            $this->config->deleteAppValue('emailbridge', $key);
        }

        return new DataResponse([
            'status' => 'ok',
            'message' => 'Toutes les données EmailBridge ont été réinitialisées.'
        ]);

    } catch (\Throwable $e) {
        $this->db->rollBack();
        return new DataResponse([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
}

}

