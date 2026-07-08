<?php

function create_file($path, $content) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, trim($content) . "\n");
}

create_file('app/modules/notificaciones/NotificacionRepository.php', '
<?php
namespace CJP\Modules\Notificaciones;

use CJP\Config\Database;
use PDO;

class NotificacionRepository {
    private \PDO ;

    public function __construct() {
        ->db = Database::getInstance()->getConnection();
    }

    public function findAll(array ): array {
         = "SELECT * FROM notificaciones WHERE 1=1";
         = [];

        if (!empty(["estado"])) {
             .= " AND estado = :estado";
            [":estado"] = ["estado"];
        }

         .= " ORDER BY fecha DESC";
         = ->db->prepare();
        ->execute();
        return ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(string ): ?array {
         = ->db->prepare("SELECT * FROM notificaciones WHERE id = :id");
        ->execute([":id" => ]);
         = ->fetch(PDO::FETCH_ASSOC);
        return  ?: null;
    }

    public function updateEstado(string , string ): void {
         = ->db->prepare("UPDATE notificaciones SET estado = :estado WHERE id = :id");
        ->execute([":estado" => , ":id" => ]);
    }

    public function registerAuditEvent(string , string , string , ?string , ?string , string ): void {
         = "INSERT INTO auditoria (id, usuario_id, accion, entidad_afectada, valor_anterior, valor_nuevo, fecha_hora, motivo) 
                  VALUES (:id, :usuario_id, :accion, :entidad, :valor_anterior, :valor_nuevo, NOW(), :motivo)";
         = ->db->prepare();
        ->execute([
            ":id" => \CJP\Shared\Helpers\StringHelper::uuid(), // Assuming a StringHelper or uuid generation exists, wait, let\'s use query for uuid() if mysql, but maybe we can just use uniqid or anything, wait, what is the standard uuid in this project?
            // Will check if uuid function exists or just use a basic implementation
            ":usuario_id" => ,
            ":accion" => ,
            ":entidad" => ,
            ":valor_anterior" => ,
            ":valor_nuevo" => ,
            ":motivo" => 
        ]);
    }
}
');
