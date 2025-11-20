<?php
require_once 'config.php';
requireLogin();

$config = getConfiguracion($pdo);

// Procesar abono
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'abonar') {
    $monto_bs = $_POST['monto_bs'];
    $credito_id = $_POST['credito_id'];
    $tasa = $config['tasa_cambio'];
    $equivalente_usd = $monto_bs / $tasa;
    
    $stmt = $pdo->prepare("
        INSERT INTO abonos (credito_id, monto_bs, tasa_cambio, equivalente_usd) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$credito_id, $monto_bs, $tasa, $equivalente_usd]);
    
    header('Location: creditos.php?success=1');
    exit;
}

// Obtener créditos con saldos
$creditos = $pdo->query("
    SELECT 
        c.*,
        cl.nombre as cliente_nombre,
        v.created_at as fecha_venta,
        IFNULL((SELECT SUM(equivalente_usd) FROM abonos WHERE credito_id = c.id), 0) as abonado_usd,
        (c.monto_usd - IFNULL((SELECT SUM(equivalente_usd) FROM abonos WHERE credito_id = c.id), 0)) as saldo_usd,
        DATEDIFF(c.fecha_vencimiento, CURDATE()) as dias_restantes
    FROM creditos c
    JOIN clientes cl ON c.cliente_id = cl.id
    JOIN ventas v ON c.venta_id = v.id
    HAVING saldo_usd > 0
    ORDER BY c.fecha_vencimiento ASC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Créditos - Nine Market</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .badge-vencido { background-color: #dc3545; }
        .badge-por-vencer { background-color: #ffc107; }
        .badge-activo { background-color: #198754; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container-fluid" x-data="creditosApp()">
        <h2 class="mb-4">💳 Gestión de Créditos</h2>

        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            Abono registrado exitosamente
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Resumen -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h6 class="text-muted">Total Créditos Activos</h6>
                        <h3><?= count($creditos) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h6 class="text-muted">Total por Cobrar (USD)</h6>
                        <h3>$<?= number_format(array_sum(array_column($creditos, 'saldo_usd')), 2) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h6 class="text-muted">Total por Cobrar (Bs)</h6>
                        <h3><?= formatMoney(array_sum(array_column($creditos, 'saldo_usd')) * $config['tasa_cambio']) ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla de créditos -->
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Cliente</th>
                                <th>Fecha Venta</th>
                                <th>Vencimiento</th>
                                <th>Monto Original</th>
                                <th>Abonado</th>
                                <th>Saldo USD</th>
                                <th>Saldo Bs</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($creditos)): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">
                                        No hay créditos activos
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($creditos as $c): 
                                    $saldo_bs = $c['saldo_usd'] * $config['tasa_cambio'];
                                    
                                    if ($c['dias_restantes'] < 0) {
                                        $estado = 'vencido';
                                        $badge_class = 'badge-vencido';
                                    } elseif ($c['dias_restantes'] <= 1) {
                                        $estado = 'por vencer';
                                        $badge_class = 'badge-por-vencer';
                                    } else {
                                        $estado = 'activo';
                                        $badge_class = 'badge-activo';
                                    }
                                ?>
                                <tr>
                                    <td><?= $c['id'] ?></td>
                                    <td><?= htmlspecialchars($c['cliente_nombre']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($c['fecha_venta'])) ?></td>
                                    <td><?= date('d/m/Y', strtotime($c['fecha_vencimiento'])) ?></td>
                                    <td>$<?= number_format($c['monto_usd'], 2) ?></td>
                                    <td>$<?= number_format($c['abonado_usd'], 2) ?></td>
                                    <td class="fw-bold">$<?= number_format($c['saldo_usd'], 2) ?></td>
                                    <td><?= formatMoney($saldo_bs) ?></td>
                                    <td>
                                        <span class="badge <?= $badge_class ?>">
                                            <?= $estado ?>
                                            <?php if ($c['dias_restantes'] >= 0): ?>
                                                (<?= $c['dias_restantes'] ?>d)
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-success" 
                                                @click='abrirModalAbono(<?= json_encode($c) ?>)'>
                                            💵 Abonar
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modal Abono -->
        <div x-show="modalAbono" 
             class="modal fade" 
             :class="{ 'show d-block': modalAbono }"
             style="background: rgba(0,0,0,0.5)"
             @click.self="cerrarModal()">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="action" value="abonar">
                        <input type="hidden" name="credito_id" x-model="creditoSeleccionado.id">
                        
                        <div class="modal-header">
                            <h5 class="modal-title">Registrar Abono</h5>
                            <button type="button" class="btn-close" @click="cerrarModal()"></button>
                        </div>
                        
                        <div class="modal-body">
                            <div class="alert alert-info">
                                <strong>Cliente:</strong> <span x-text="creditoSeleccionado.cliente_nombre"></span><br>
                                <strong>Saldo USD:</strong> $<span x-text="parseFloat(creditoSeleccionado.saldo_usd).toFixed(2)"></span><br>
                                <strong>Saldo Bs:</strong> Bs <span x-text="(creditoSeleccionado.saldo_usd * tasaCambio).toFixed(2)"></span><br>
                                <small class="text-muted">Tasa: <?= $config['tasa_cambio'] ?> Bs/$</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Monto del Abono (Bs) *</label>
                                <input type="number" 
                                       name="monto_bs" 
                                       x-model="montoAbono"
                                       class="form-control" 
                                       step="0.01" 
                                       min="0.01" 
                                       :max="creditoSeleccionado.saldo_usd * tasaCambio"
                                       required
                                       autofocus>
                            </div>

                            <div class="alert alert-secondary">
                                <strong>Equivalente USD:</strong> $<span x-text="(montoAbono / tasaCambio).toFixed(2)"></span>
                            </div>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" @click="cerrarModal()">Cancelar</button>
                            <button type="submit" class="btn btn-success">Registrar Abono</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.10/dist/cdn.min.js" defer></script>
    <script>
        function creditosApp() {
            return {
                modalAbono: false,
                creditoSeleccionado: {},
                montoAbono: 0,
                tasaCambio: <?= $config['tasa_cambio'] ?>,

                abrirModalAbono(credito) {
                    this.creditoSeleccionado = credito;
                    this.montoAbono = (credito.saldo_usd * this.tasaCambio).toFixed(2);
                    this.modalAbono = true;
                },

                cerrarModal() {
                    this.modalAbono = false;
                    this.creditoSeleccionado = {};
                    this.montoAbono = 0;
                }
            }
        }
    </script>
</body>
</html>