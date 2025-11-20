<?php
require_once 'config.php';
requireAdmin(); // Solo administradores pueden refacturar

$config = getConfiguracion($pdo);

// Procesar refacturación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refacturar') {
    $venta_id = $_POST['venta_id'];
    $motivo = $_POST['motivo'];
    $tasa_nueva = $_POST['tasa_nueva'] ?? $config['tasa_cambio'];
    
    try {
        $pdo->beginTransaction();
        
        // Obtener venta original
        $stmt = $pdo->prepare("
            SELECT v.*, c.nombre as cliente_nombre 
            FROM ventas v 
            JOIN clientes c ON v.cliente_id = c.id 
            WHERE v.id = ?
        ");
        $stmt->execute([$venta_id]);
        $venta_original = $stmt->fetch();
        
        if (!$venta_original) {
            throw new Exception('Venta no encontrada');
        }
        
        // Obtener detalles de la venta
        $stmt = $pdo->prepare("
            SELECT dv.*, p.nombre as producto_nombre, p.precio_bs as precio_actual_bs, p.precio_usd as precio_actual_usd
            FROM detalle_ventas dv
            JOIN productos p ON dv.producto_id = p.id
            WHERE dv.venta_id = ?
        ");
        $stmt->execute([$venta_id]);
        $detalles = $stmt->fetchAll();
        
        // Calcular nuevos totales
        $nuevo_total_bs = 0;
        $nuevo_total_usd = 0;
        
        foreach ($detalles as $detalle) {
            // Usar precios actuales del producto
            $nuevo_total_bs += $detalle['cantidad'] * $detalle['precio_actual_bs'];
            $nuevo_total_usd += $detalle['cantidad'] * $detalle['precio_actual_usd'];
        }
        
        // Crear nueva venta (refacturación)
        $stmt = $pdo->prepare("
            INSERT INTO ventas (factura_original_id, tipo_factura, motivo_refacturacion, user_id, cliente_id, total_bs, total_usd, metodo_pago, tasa_cambio) 
            VALUES (?, 'refacturacion', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $venta_id,
            $motivo,
            $venta_original['user_id'],
            $venta_original['cliente_id'],
            $nuevo_total_bs,
            $nuevo_total_usd,
            $venta_original['metodo_pago'],
            $tasa_nueva
        ]);
        
        $nueva_venta_id = $pdo->lastInsertId();
        
        // Copiar detalles con precios actualizados
        foreach ($detalles as $detalle) {
            $stmt = $pdo->prepare("
                INSERT INTO detalle_ventas (venta_id, producto_id, cantidad, precio_bs, precio_usd, subtotal_bs, subtotal_usd) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $subtotal_bs = $detalle['cantidad'] * $detalle['precio_actual_bs'];
            $subtotal_usd = $detalle['cantidad'] * $detalle['precio_actual_usd'];
            
            $stmt->execute([
                $nueva_venta_id,
                $detalle['producto_id'],
                $detalle['cantidad'],
                $detalle['precio_actual_bs'],
                $detalle['precio_actual_usd'],
                $subtotal_bs,
                $subtotal_usd
            ]);
        }
        
        // Si era crédito, actualizar
        if ($venta_original['metodo_pago'] === 'credito') {
            $stmt = $pdo->prepare("UPDATE creditos SET monto_usd = ? WHERE venta_id = ?");
            $stmt->execute([$nuevo_total_usd, $venta_id]);
        }
        
        $pdo->commit();
        
        header('Location: refacturacion.php?success=1&nueva_venta=' . $nueva_venta_id);
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = $e->getMessage();
        header('Location: refacturacion.php');
        exit;
    }
}

// Obtener ventas susceptibles de refacturación
$ventas_refacturables = $pdo->query("
    SELECT 
        v.*,
        c.nombre as cliente_nombre,
        u.name as usuario_nombre,
        DATEDIFF(NOW(), v.created_at) as dias_antiguedad,
        (SELECT COUNT(*) FROM ventas WHERE factura_original_id = v.id) as refacturaciones_count
    FROM ventas v
    JOIN clientes c ON v.cliente_id = c.id
    JOIN users u ON v.user_id = u.id
    WHERE v.tipo_factura = 'original'
    ORDER BY v.created_at DESC
    LIMIT 50
")->fetchAll();

// Obtener historial de refacturaciones
$historial_refacturaciones = $pdo->query("
    SELECT 
        v.*,
        vo.id as factura_original_numero,
        c.nombre as cliente_nombre,
        u.name as usuario_nombre
    FROM ventas v
    JOIN ventas vo ON v.factura_original_id = vo.id
    JOIN clientes c ON v.cliente_id = c.id
    JOIN users u ON v.user_id = u.id
    WHERE v.tipo_factura = 'refacturacion'
    ORDER BY v.created_at DESC
    LIMIT 20
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refacturación - Nine Market</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .diferencia-positiva { color: #198754; font-weight: bold; }
        .diferencia-negativa { color: #dc3545; font-weight: bold; }
        .badge-refacturada { background-color: #6c757d; }
        .alerta-diferencia {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container-fluid" x-data="refacturacionApp()">
        <h2 class="mb-4">🔄 Sistema de Refacturación</h2>

        <div class="alerta-diferencia">
            <h5 class="mb-2">⚠️ Sobre la Refacturación</h5>
            <p class="mb-0">
                Este sistema permite emitir nuevas facturas con precios y tasa de cambio actualizados. 
                La factura original se mantiene como registro histórico y la nueva refleja los valores actuales.
                <br><strong>Tasa actual:</strong> <?= number_format($config['tasa_cambio'], 2) ?> Bs/$
            </p>
        </div>

        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Refacturación completada exitosamente. Nueva factura #<?= $_GET['nueva_venta'] ?>
            <a href="factura_pdf.php?id=<?= $_GET['nueva_venta'] ?>" class="btn btn-sm btn-success ms-3" target="_blank">
                📄 Ver Factura
            </a>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            ❌ Error: <?= htmlspecialchars($_SESSION['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#facturas" type="button">
                    📋 Facturas Originales
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#historial" type="button">
                    📜 Historial de Refacturaciones
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- Tab Facturas Originales -->
            <div class="tab-pane fade show active" id="facturas">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Fecha</th>
                                        <th>Cliente</th>
                                        <th>Total Original Bs</th>
                                        <th>Total Original USD</th>
                                        <th>Tasa Original</th>
                                        <th>Total Actual</th>
                                        <th>Diferencia</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ventas_refacturables as $v): 
                                        // Calcular total con precios actuales
                                        $stmt = $pdo->prepare("
                                            SELECT SUM(dv.cantidad * p.precio_bs) as total_actual_bs,
                                                   SUM(dv.cantidad * p.precio_usd) as total_actual_usd
                                            FROM detalle_ventas dv
                                            JOIN productos p ON dv.producto_id = p.id
                                            WHERE dv.venta_id = ?
                                        ");
                                        $stmt->execute([$v['id']]);
                                        $totales_actuales = $stmt->fetch();
                                        
                                        $diferencia_bs = $totales_actuales['total_actual_bs'] - $v['total_bs'];
                                        $diferencia_pct = $v['total_bs'] > 0 ? ($diferencia_bs / $v['total_bs']) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td><?= $v['id'] ?></td>
                                        <td>
                                            <?= date('d/m/Y', strtotime($v['created_at'])) ?>
                                            <br><small class="text-muted"><?= $v['dias_antiguedad'] ?> días</small>
                                        </td>
                                        <td><?= htmlspecialchars($v['cliente_nombre']) ?></td>
                                        <td><?= formatMoney($v['total_bs']) ?></td>
                                        <td>$<?= number_format($v['total_usd'], 2) ?></td>
                                        <td><?= number_format($v['tasa_cambio'], 2) ?></td>
                                        <td>
                                            <?= formatMoney($totales_actuales['total_actual_bs']) ?>
                                            <br>
                                            <small class="text-muted">$<?= number_format($totales_actuales['total_actual_usd'], 2) ?></small>
                                        </td>
                                        <td>
                                            <span class="<?= $diferencia_bs >= 0 ? 'diferencia-positiva' : 'diferencia-negativa' ?>">
                                                <?= $diferencia_bs >= 0 ? '+' : '' ?><?= formatMoney($diferencia_bs) ?>
                                                <br>
                                                <small>(<?= number_format($diferencia_pct, 1) ?>%)</small>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($v['refacturaciones_count'] > 0): ?>
                                                <span class="badge badge-refacturada">
                                                    🔄 Refacturada (<?= $v['refacturaciones_count'] ?>)
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-primary">Original</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-warning" 
                                                    @click='abrirModalRefacturar(<?= json_encode($v) ?>, <?= $diferencia_bs ?>, <?= $totales_actuales['total_actual_bs'] ?>, <?= $totales_actuales['total_actual_usd'] ?>)'>
                                                🔄 Refacturar
                                            </button>
                                            <a href="factura_pdf.php?id=<?= $v['id'] ?>" 
                                               class="btn btn-sm btn-outline-primary" 
                                               target="_blank">
                                                📄 Ver
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Historial -->
            <div class="tab-pane fade" id="historial">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Nueva Factura</th>
                                        <th>Factura Original</th>
                                        <th>Fecha Refacturación</th>
                                        <th>Cliente</th>
                                        <th>Nuevo Total</th>
                                        <th>Motivo</th>
                                        <th>Usuario</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($historial_refacturaciones)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">
                                                No hay refacturaciones registradas
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($historial_refacturaciones as $r): ?>
                                        <tr>
                                            <td><strong>#<?= $r['id'] ?></strong></td>
                                            <td>#<?= $r['factura_original_numero'] ?></td>
                                            <td><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                                            <td><?= htmlspecialchars($r['cliente_nombre']) ?></td>
                                            <td><?= formatMoney($r['total_bs']) ?></td>
                                            <td>
                                                <small><?= htmlspecialchars($r['motivo_refacturacion']) ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($r['usuario_nombre']) ?></td>
                                            <td>
                                                <a href="factura_pdf.php?id=<?= $r['id'] ?>" 
                                                   class="btn btn-sm btn-outline-primary" 
                                                   target="_blank">
                                                    📄 Ver
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Refacturar -->
        <div x-show="modalRefacturar" 
             class="modal fade" 
             :class="{ 'show d-block': modalRefacturar }"
             style="background: rgba(0,0,0,0.5)"
             @click.self="cerrarModal()">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="action" value="refacturar">
                        <input type="hidden" name="venta_id" x-model="ventaSeleccionada.id">
                        
                        <div class="modal-header bg-warning">
                            <h5 class="modal-title">🔄 Refacturar Venta #<span x-text="ventaSeleccionada.id"></span></h5>
                            <button type="button" class="btn-close" @click="cerrarModal()"></button>
                        </div>
                        
                        <div class="modal-body">
                            <div class="alert alert-info">
                                <strong>Cliente:</strong> <span x-text="ventaSeleccionada.cliente_nombre"></span><br>
                                <strong>Fecha original:</strong> <span x-text="ventaSeleccionada.created_at"></span>
                            </div>

                            <div class="row mb-3">
                                <div class="col-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="text-muted">Total Original</h6>
                                            <div class="fs-4" x-text="'Bs ' + parseFloat(ventaSeleccionada.total_bs).toFixed(2)"></div>
                                            <small class="text-muted">Tasa: <span x-text="parseFloat(ventaSeleccionada.tasa_cambio).toFixed(2)"></span></small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="card bg-success text-white">
                                        <div class="card-body">
                                            <h6>Total Actualizado</h6>
                                            <div class="fs-4" x-text="'Bs ' + totalActualBs.toFixed(2)"></div>
                                            <small>Tasa: <?= $config['tasa_cambio'] ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="alert" :class="diferenciaBs >= 0 ? 'alert-success' : 'alert-danger'">
                                <strong>Diferencia:</strong>
                                <span x-text="(diferenciaBs >= 0 ? '+' : '') + diferenciaBs.toFixed(2)"></span> Bs
                                <span x-text="'(' + ((diferenciaBs / ventaSeleccionada.total_bs) * 100).toFixed(1) + '%)'"></span>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Motivo de la Refacturación *</label>
                                <select name="motivo" class="form-select mb-2" required>
                                    <option value="">Seleccione un motivo...</option>
                                    <option value="Actualización de precios">Actualización de precios</option>
                                    <option value="Ajuste por tasa de cambio">Ajuste por tasa de cambio</option>
                                    <option value="Corrección de datos">Corrección de datos</option>
                                    <option value="Solicitud del cliente">Solicitud del cliente</option>
                                    <option value="Otro">Otro</option>
                                </select>
                                <textarea name="motivo_adicional" 
                                          class="form-control" 
                                          rows="2" 
                                          placeholder="Detalles adicionales (opcional)"></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Tasa de Cambio para Nueva Factura</label>
                                <input type="number" 
                                       name="tasa_nueva" 
                                       class="form-control" 
                                       value="<?= $config['tasa_cambio'] ?>"
                                       step="0.01"
                                       min="0">
                                <small class="text-muted">Dejar como está para usar la tasa actual del sistema</small>
                            </div>

                            <div class="alert alert-warning">
                                <strong>⚠️ Importante:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Se generará una nueva factura con los precios actuales</li>
                                    <li>La factura original se mantendrá como registro histórico</li>
                                    <li>Si es un crédito, el saldo se actualizará</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" @click="cerrarModal()">Cancelar</button>
                            <button type="submit" class="btn btn-warning">
                                🔄 Confirmar Refacturación
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.10/dist/cdn.min.js" defer></script>
    <script>
        function refacturacionApp() {
            return {
                modalRefacturar: false,
                ventaSeleccionada: {},
                diferenciaBs: 0,
                totalActualBs: 0,
                totalActualUsd: 0,

                abrirModalRefacturar(venta, diferencia, totalActualBs, totalActualUsd) {
                    this.ventaSeleccionada = venta;
                    this.diferenciaBs = diferencia;
                    this.totalActualBs = totalActualBs;
                    this.totalActualUsd = totalActualUsd;
                    this.modalRefacturar = true;
                },

                cerrarModal() {
                    this.modalRefacturar = false;
                    this.ventaSeleccionada = {};
                    this.diferenciaBs = 0;
                    this.totalActualBs = 0;
                    this.totalActualUsd = 0;
                }
            }
        }
    </script>
</body>
</html>