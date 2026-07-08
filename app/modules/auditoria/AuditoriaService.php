<?php
namespace CJP\Modules\Auditoria;

use Exception;

class AuditoriaService {
    private AuditoriaRepository $repository;

    public function __construct() {
        $this->repository = new AuditoriaRepository();
    }

    public function getAll(array $filtros, int $pagina): array {
        return $this->repository->findAll($filtros, $pagina);
    }

    public function getOne(string $id): array {
        $auditoria = $this->repository->findById($id);
        if (!$auditoria) {
            throw new Exception("Registro de auditoría no encontrado.");
        }
        return $auditoria;
    }
}
