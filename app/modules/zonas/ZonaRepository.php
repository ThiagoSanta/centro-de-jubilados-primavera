<?php

namespace CJP\Modules\Zonas;

use PDO;
use CJP\Config\Database;

class ZonaRepository
{
    private PDO $db;

    /**
     * ZonaRepository constructor.
     *
     * @param PDO|null $db
     */
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Retrieve all zones from the database.
     *
     * @return array
     */
    public function findAll(): array
    {
        $sql = "SELECT * FROM zonas ORDER BY nombre";
        $stmt = $this->db->query($sql);

        return $stmt->fetchAll();
    }

    /**
     * Find a zone by its UUID.
     *
     * @param string $id
     * @return array|null
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
     * Find a zone by its name.
     *
     * @param string $nombre
     * @return array|null
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
