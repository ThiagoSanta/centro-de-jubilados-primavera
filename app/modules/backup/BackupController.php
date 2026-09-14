<?php

namespace CJP\Modules\Backup;

use CJP\Modules\Auth\AuthService;
use CJP\Shared\AuthMiddleware;
use CJP\Shared\Helpers\ResponseHelper;

class BackupController
{
    private BackupService $backupService;
    private AuthService   $authService;

    public function __construct()
    {
        $this->backupService = new BackupService();
        $this->authService   = new AuthService();
    }

    /**
     * POST /api/backup/generar — admin-only
     * Genera el dump y lo envía como descarga directa al navegador.
     */
    public function generar(array $params = []): void
    {
        AuthMiddleware::requireAuth('administrador');

        $session   = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        $sql      = $this->backupService->generarDump($usuarioId);
        $filename = 'backup_' . (new \DateTimeImmutable())->format('Y-m-d_H-i-s') . '.sql';

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        header('Cache-Control: no-cache, no-store, must-revalidate');

        echo $sql;
        exit;
    }

    /**
     * GET /api/backup/ultimo — admin-only
     * Retorna la fecha del último backup registrado.
     */
    public function ultimo(array $params = []): void
    {
        AuthMiddleware::requireAuth('administrador');

        $info = $this->backupService->getUltimoBackup();
        ResponseHelper::success($info, $info ? 'Último backup encontrado.' : 'No hay backups registrados.');
    }
}
