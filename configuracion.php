<?php
require_once 'config.php';
requireAdmin(); // Solo administradores

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("
        UPDATE configuracion 
        SET nombre_negocio=?, tasa_cambio=?, iva=?, dias_vencimiento_credito=?
        WHERE id=1
    ");
    $stmt->execute([
        $_POST['nombre_negocio'],
        $_POST['tasa_cambio'],
        $_POST['iva'],
        $_POST['dias_vencimiento_credito']
    ]);
    
    header('Location: configuracion.php?success=1');
    exit;
}

$config = getConfiguracion($pdo);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración - Nine Market</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <h2 class="mb-4">⚙️ Configuración del Sistema</h2>

        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            Configuración actualizada exitosamente
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-4">
                                <label class="form-label fw-bold">Nombre del Negocio</label>
                                <input type="text" name="nombre_negocio" 
                                       value="<?= htmlspecialchars($config['nombre_negocio']) ?>" 
                                       class="form-control" required>
                                <small class="text-muted">Aparece en facturas y reportes</small>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold">Tasa de Cambio (Bs/$)</label>
                                <input type="number" name="tasa_cambio" 
                                       value="<?= $config['tasa_cambio'] ?>" 
                                       class="form-control" step="0.01" min="0" required>
                                <small class="text-muted">
                                    Actual: <?= $config['tasa_cambio'] ?> Bs/$
                                    <br>Los créditos se ajustan automáticamente con este valor
                                </small>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold">IVA (%)</label>
                                <input type="number" name="iva" 
                                       value="<?= $config['iva'] ?>" 
                                       class="form-control" step="0.01" min="0" max="100" required>
                                <small class="text-muted">
                                    Actual: <?= $config['iva'] ?>%
                                    <br>Todos los precios incluyen IVA
                                </small>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold">Días de Vencimiento para Créditos</label>
                                <input type="number" name="dias_vencimiento_credito" 
                                       value="<?= $config['dias_vencimiento_credito'] ?>" 
                                       class="form-control" min="1" required>
                                <small class="text-muted">
                                    Los créditos vencen automáticamente después de estos días
                                </small>
                            </div>

                            <button type="submit" class="btn btn-success btn-lg w-100">
                                💾 Guardar Configuración
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0">ℹ️ Información del Sistema</h6>
                    </div>
                    <div class="card-body">
                        <p><strong>Versión:</strong> 1.0</p>
                        <p><strong>Sistema:</strong> Nine Market</p>
                        <p><strong>Tipo:</strong> Offline-first</p>
                        <p class="mb-0"><strong>Base de datos:</strong> MySQL</p>
                    </div>
                </div>
                
                <div class="card shadow-sm mt-3 border-danger">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0">🔄 Administración del Sistema</h6>
                    </div>
                    <div class="card-body text-center">
                        <p class="text-muted mb-3">Acciones avanzadas de administración del sistema</p>
                        <a href="reset_system.php" class="btn btn-outline-danger btn-sm">
                            🔥 Resetear Sistema Completo
                        </a>
                        <small class="d-block text-muted mt-2">
                            Elimina todos los datos y deja el sistema listo para nuevos registros
                        </small>
                    </div>
                </div>
                
                <div class="card shadow-sm mt-3 border-info">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0">💾 Backup de Base de Datos</h6>
                    </div>
                    <div class="card-body text-center">
                        <p class="text-muted mb-3">Realice copias de seguridad de la base de datos</p>
                        <a href="backup_db.php" class="btn btn-outline-info btn-sm">
                            💾 Gestionar Backups
                        </a>
                        <small class="d-block text-muted mt-2">
                            Exporte e importe la base de datos completa para realizar respaldos y restauraciones
                        </small>
                    </div>
                </div>
                
                <div class="card shadow-sm">
                    <div class="card-header bg-warning text-dark">
                        <h6 class="mb-0">⚠️ Importante</h6>
                    </div>
                    <div class="card-body">
                        <small>
                            <ul class="mb-0 ps-3">
                                <li>Los cambios afectan inmediatamente al sistema</li>
                                <li>La tasa de cambio actualiza los saldos de créditos en tiempo real</li>
                                <li>Stock mínimo está fijo en 3 unidades</li>
                                <li>Todos los precios incluyen IVA</li>
                            </ul>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>