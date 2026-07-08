<?php
namespace CJP\Modules\Notificaciones;

use CJP\Config\Database;
use Exception;

class NotificacionService {
    private NotificacionRepository $repository;

    public function __construct() {
        $this->repository = new NotificacionRepository();
    }

    public function getAll(array $filtros): array {
        return $this->repository->findAll($filtros);
    }

    public function marcarLeida(string $id): void {
        $this->repository->updateEstado($id, 'leida');
    }

    public function archivar(string $id): void {
        $this->repository->updateEstado($id, 'archivada');
    }

    public function revertir(string $id, string $usuarioId): void {
        $notificacion = $this->repository->findById($id);
        if (!$notificacion) {
            throw new Exception("Notificación no encontrada.");
        }
        if ($notificacion['estado'] === 'archivada') {
            throw new Exception("No se puede revertir una notificación archivada.");
        }
        if (strtotime($notificacion['fecha_expiracion_reversion']) < time()) {
            throw new Exception("El tiempo para revertir esta acción ha expirado.");
        }

        $referencia = json_decode($notificacion['referencia'], true);
        if (!$referencia || !isset($referencia['entidad']) || !isset($referencia['id'])) {
            throw new Exception("Referencia inválida en la notificación.");
        }

        $entidad = $referencia['entidad'];
        $entidadId = $referencia['id'];
        $db = Database::getInstance()->getConnection();

        $db->beginTransaction();
        try {
            if ($entidad === 'socios') {
                $stmt = $db->prepare("UPDATE socios SET estado = 'activo', motivo_baja = NULL, fecha_baja = NULL WHERE id = :id");
                $stmt->execute([':id' => $entidadId]);
                $this->repository->registerAuditEvent($usuarioId, 'reversion_baja', 'socios', json_encode(['estado'=>'inactivo']), json_encode(['estado'=>'activo']), 'Reversión desde notificaciones');
            } elseif ($entidad === 'pagos') {
                $stmt = $db->prepare("UPDATE pagos SET estado = 'registrado' WHERE id = :id");
                $stmt->execute([':id' => $entidadId]);
                
                // Get the payment date
                $stmtPago = $db->prepare("SELECT fecha_hora FROM pagos WHERE id = :id");
                $stmtPago->execute([':id' => $entidadId]);
                $pago = $stmtPago->fetch(\PDO::FETCH_ASSOC);
                
                $stmtDeudas = $db->prepare("UPDATE deudas SET estado = 'pagada', fecha_pago = :fecha_pago WHERE id IN (SELECT deuda_id FROM pago_deuda WHERE pago_id = :pago_id)");
                $stmtDeudas->execute([':fecha_pago' => $pago['fecha_hora'], ':pago_id' => $entidadId]);
                
                $this->repository->registerAuditEvent($usuarioId, 'reversion_anulacion', 'pagos', json_encode(['estado'=>'anulado']), json_encode(['estado'=>'registrado']), 'Reversión desde notificaciones');
            } else {
                throw new Exception("Entidad no soportada para reversión.");
            }

            $this->archivar($id);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
