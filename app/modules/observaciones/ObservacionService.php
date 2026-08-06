<?php
namespace CJP\Modules\Observaciones;

use Exception;
use CJP\Shared\Exceptions\AppException;

class ObservacionService {
    private ObservacionRepository $repository;

    public function __construct() {
        $this->repository = new ObservacionRepository();
    }

    public function getBySocio(string $socioId): array {
        return $this->repository->findBySocio($socioId);
    }

    public function agregar(string $socioId, string $contenido, string $usuarioId): array {
        if (empty(trim($contenido))) {
            throw new AppException("El contenido de la observación no puede estar vacío.", 400);
        }
        if (strlen($contenido) > 1000) {
            throw new AppException("El contenido no puede superar los 1000 caracteres.", 400);
        }

        $id = $this->repository->create([
            'socio_id' => $socioId,
            'usuario_id' => $usuarioId,
            'contenido' => $contenido
        ]);

        $this->repository->registerAuditEvent($usuarioId, 'crear_observacion', 'observaciones', null, json_encode(['id' => $id, 'socio_id' => $socioId, 'contenido' => $contenido]), 'Nueva observación agregada');

        return ['id' => $id, 'socio_id' => $socioId, 'contenido' => $contenido];
    }
}
