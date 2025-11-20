<?php
require_once 'config.php';
requireAdmin();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {
    $confirm_text = trim($_POST['confirm_text'] ?? '');
    
    if ($confirm_text !== 'RESET_NINE_MARKET') {
        $error = 'Texto de confirmacion incorrecto. Debe escribir exactamente: RESET_NINE_MARKET';
    } else {
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            
            $tables = ['abonos', 'creditos', 'detalle_ventas', 'ventas', 'productos', 'clientes', 'categorias'];
            foreach ($tables as $table) {
                $pdo->exec("DELETE FROM $table");
            }
            
            $pdo->exec("INSERT INTO clientes (id, nombre, clasificacion) VALUES (1, 'Consumidor Final', 'C')");
            $pdo->exec("INSERT INTO categorias (id, nombre) VALUES (1, 'Bebidas'), (2, 'Alimentos'), (3, 'Limpieza'), (4, 'Otros')");
            
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $success = 'Sistema reseteado exitosamente';
            
        } catch (Exception $e) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $error = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resetear Sistema - Nine Market</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .danger-zone { 
            border: 3px solid #dc3545; 
            border-radius: 15px;
            background: linear-gradient(135deg, #fff5f5 0%, #ffe6e6 100%);
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <h2 class="text-center mb-4">Resetear Sistema</h2>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="card danger-zone shadow-lg">
                    <div class="card-header bg-danger text-white text-center">
                        <h4 class="mb-0">Zona de Peligro</h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <h5>Accion Irreversible</h5>
                            <p class="mb-2"><strong>Esta accion eliminara permanentemente:</strong></p>
                            <ul>
                                <li>Todas las ventas y transacciones</li>
                                <li>Todos los productos y stock</li>
                                <li>Todos los clientes (excepto "Consumidor Final")</li>
                                <li>Todos los creditos y abonos</li>
                                <li>Todo el historial financiero</li>
                                <li>Todas las categorias (se restauran las por defecto)</li>
                            </ul>
                            <p class="mb-0"><strong>Esta accion NO se puede deshacer.</strong></p>
                        </div>

                        <div class="alert alert-info">
                            <h5>Cuando usar esta funcion?</h5>
                            <ul class="mb-0">
                                <li>Al iniciar un nuevo periodo contable</li>
                                <li>Al migrar a un nuevo sistema</li>
                                <li>Para limpiar datos de prueba</li>
                                <li>Al reinstalar el sistema</li>
                            </ul>
                        </div>

                        <form method="POST" id="resetForm">
                            <div class="mb-4">
                                <label class="form-label fw-bold">
                                    Para confirmar, escriba exactamente: 
                                    <code class="bg-dark text-white px-2 py-1 rounded">RESET_NINE_MARKET</code>
                                </label>
                                <input type="text" 
                                       name="confirm_text" 
                                       class="form-control form-control-lg text-center" 
                                       placeholder="Escriba el texto de confirmacion aqui..."
                                       required
                                       autocomplete="off">
                                <small class="text-muted">
                                    Esta medida de seguridad evita resetear el sistema por accidente.
                                </small>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="button" 
                                        class="btn btn-lg btn-danger" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#confirmModal">
                                    Ejecutar Reset Total del Sistema
                                </button>
                                <a href="configuracion.php" class="btn btn-lg btn-secondary">
                                    Volver a Configuracion
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="confirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Confirmacion Final</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="fs-5">¿Esta absolutamente seguro de que desea resetear el sistema?</p>
                    <p class="text-muted">
                        Esta accion eliminara <strong>todos los datos</strong> de forma permanente.
                        No podra recuperar la informacion despues de este punto.
                    </p>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="finalConfirm">
                        <label class="form-check-label" for="finalConfirm">
                            Comprendo que esta accion es irreversible y eliminara todos los datos.
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" 
                            form="resetForm" 
                            name="confirm_reset" 
                            value="1" 
                            class="btn btn-danger"
                            id="confirmResetBtn"
                            disabled>
                        Si, Resetear Todo
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('finalConfirm').addEventListener('change', function() {
            document.getElementById('confirmResetBtn').disabled = !this.checked;
        });

        document.getElementById('resetForm').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>