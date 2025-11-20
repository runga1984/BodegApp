<?php
require_once 'config.php';
requireAdmin();

// Función para exportar la base de datos
function exportDatabase($pdo) {
    $tables = [];
    $result = $pdo->query("SHOW TABLES");
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    
    $return = '';
    $return .= "-- Nine Market Database Backup\n";
    $return .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $return .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
    
    foreach ($tables as $table) {
        $return .= "-- Table: $table\n";
        
        $result = $pdo->query("SHOW CREATE TABLE `$table`");
        $row = $result->fetch(PDO::FETCH_NUM);
        $return .= "DROP TABLE IF EXISTS `$table`;\n";
        $return .= $row[1] . ";\n\n";
        
        $return .= "-- Data: $table\n";
        $result = $pdo->query("SELECT * FROM `$table`");
        $num_fields = $result->columnCount();
        
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $return .= "INSERT INTO `$table` VALUES(";
            for ($j = 0; $j < $num_fields; $j++) {
                if (isset($row[$j])) {
                    $row[$j] = addslashes($row[$j]);
                    $row[$j] = str_replace("\n", "\\n", $row[$j]);
                    $return .= '"' . $row[$j] . '"';
                } else {
                    $return .= 'NULL';
                }
                if ($j < ($num_fields - 1)) {
                    $return .= ',';
                }
            }
            $return .= ");\n";
        }
        $return .= "\n";
    }
    
    $return .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $return;
}

// Procesar exportación
if (isset($_GET['export'])) {
    $backup_file = 'nine_market_backup_' . date('Y-m-d_H-i-s') . '.sql';
    
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $backup_file . '"');
    
    echo exportDatabase($pdo);
    exit;
}

// Procesar importación
$import_success = '';
$import_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_file'])) {
    if ($_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['backup_file']['tmp_name'];
        $content = file_get_contents($tmp_name);
        
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            
            $queries = explode(';', $content);
            $executed_queries = 0;
            
            foreach ($queries as $query) {
                $query = trim($query);
                if (!empty($query) && strpos($query, '--') !== 0) {
                    $pdo->exec($query);
                    $executed_queries++;
                }
            }
            
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $import_success = "Base de datos importada. Se ejecutaron $executed_queries consultas.";
            
        } catch (Exception $e) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $import_error = 'Error al importar: ' . $e->getMessage();
        }
    } else {
        $import_error = 'Error al subir el archivo';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Base de Datos - Nine Market</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .backup-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <h2 class="mb-4">Backup Base de Datos</h2>

        <?php if ($import_success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo $import_success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($import_error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo $import_error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-md-6">
                <div class="card backup-card h-100">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Exportar Base de Datos</h5>
                    </div>
                    <div class="card-body text-center">
                        <div class="mb-4">
                            <h5>Generar Backup Completo</h5>
                            <p class="text-muted">
                                Crea un archivo SQL con todos los datos del sistema para respaldo o migracion.
                            </p>
                        </div>
                        <a href="?export=1" class="btn btn-success btn-lg w-100">
                            Descargar Backup SQL
                        </a>
                        <small class="text-muted d-block mt-2">
                            Incluye: productos, clientes, ventas, creditos y configuracion
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card backup-card h-100">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">Importar Base de Datos</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-4 text-center">
                            <h5>Restaurar desde Backup</h5>
                            <p class="text-muted">
                                Restaura todos los datos desde un archivo SQL previamente exportado.
                            </p>
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Seleccionar archivo SQL</label>
                                <input type="file" name="backup_file" class="form-control" accept=".sql" required>
                                <small class="text-muted">
                                    Solo archivos .sql generados por el sistema Nine Market
                                </small>
                            </div>
                            
                            <div class="alert alert-warning">
                                <strong>Advertencia:</strong><br>
                                Esta accion reemplazara todos los datos actuales. 
                                Se recomienda hacer un backup antes de proceder.
                            </div>
                            
                            <button type="submit" class="btn btn-warning btn-lg w-100">
                                Restaurar Base de Datos
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="card backup-card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Informacion sobre Backups</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Que se incluye en el backup:</h6>
                                <ul>
                                    <li>Todos los productos y categorias</li>
                                    <li>Clientes y clasificaciones</li>
                                    <li>Ventas e historial completo</li>
                                    <li>Creditos y abonos</li>
                                    <li>Configuracion del sistema</li>
                                    <li>Usuarios y permisos</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6>Recomendaciones:</h6>
                                <ul>
                                    <li>Realiza backups regularmente</li>
                                    <li>Guarda los backups en lugar seguro</li>
                                    <li>Nombra los backups con fecha</li>
                                    <li>Verifica el backup antes de eliminar datos</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>