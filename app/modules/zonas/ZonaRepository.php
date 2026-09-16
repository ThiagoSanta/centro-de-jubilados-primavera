<?php

namespace CJP\Modules\Zonas;

use PDO;
use CJP\Config\Database;

class ZonaRepository
{
    private PDO $db;

    /**
     * Constructor de ZonaRepository.
     * Inyecta la conexión PDO a la base de datos.
     *
     * @param PDO|null $db
     */
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Consulta y retorna todas las zonas registradas en la base de datos.
     *
     * @return array Listado de zonas
     */
    public function findAll(): array
    {
        $sql = "SELECT * FROM zonas ORDER BY nombre";
        $stmt = $this->db->query($sql);

        return $stmt->fetchAll();
    }

    /**
     * Busca una zona por su identificador UUID.
     *
     * @param string $id UUID de la zona
     * @return array|null Datos de la zona o null si no existe
     */
    public function findById(string $id): ?array
    {
        $sql = "SELECT * FROM zonas WHERE id = :id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $zona = $stmt->fetch();

        return $zona ?: null;
    }

    /**
     * Busca una zona por su nombre identificatorio (ej. 'Centro Oeste', 'Norte Este').
     *
     * @param string $nombre Nombre de la zona
     * @return array|null Datos de la zona o null si no existe
     */
    public function findByNombre(string $nombre): ?array
    {
        $sql = "SELECT * FROM zonas WHERE nombre = :nombre LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['nombre' => $nombre]);
        $zona = $stmt->fetch();

        return $zona ?: null;
    }
}
