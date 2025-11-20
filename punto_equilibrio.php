<?php
require_once 'config.php';
requireLogin();

// Obtener configuración primero para evitar errores
$config = getConfiguracion($pdo);

// Procesar acciones de costos fijos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'crear_costo') {
        $stmt = $pdo->prepare("INSERT INTO costos_fijos (nombre, monto, tipo) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['nombre'], $_POST['monto'], $_POST['tipo']]);
        header('Location: punto_equilibrio.php?success=1');
        exit;
    }
    
    if ($_POST['action'] === 'editar_costo') {
        $stmt = $pdo->prepare("UPDATE costos_fijos SET nombre=?, monto=?, tipo=? WHERE id=?");
        $stmt->execute([$_POST['nombre'], $_POST['monto'], $_POST['tipo'], $_POST['id']]);
        header('Location: punto_equilibrio.php?success=2');
        exit;
    }
    
    if ($_POST['action'] === 'eliminar_costo') {
        $stmt = $pdo->prepare("DELETE FROM costos_fijos WHERE id=?");
        $stmt->execute([$_POST['id']]);
        header('Location: punto_equilibrio.php?success=3');
        exit;
    }
}

// Verificar si la tabla costos_fijos existe, si no, crearla
try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'costos_fijos'")->fetch();
    if (!$tableExists) {
        $pdo->exec("
            CREATE TABLE `costos_fijos` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `nombre` VARCHAR(255) NOT NULL,
                `monto` DECIMAL(12,2) NOT NULL,
                `tipo` ENUM('mensual', 'semanal', 'diario') NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
} catch (PDOException $e) {
    // Si hay error, continuar sin la tabla
    error_log("Error creando tabla costos_fijos: " . $e->getMessage());
}

// Calcular CMV (Margen de Contribución Promedio) basado en ventas reales
$stmt = $pdo->query("
    SELECT 
        SUM(dv.cantidad * p.costo_bs) as total_costo_ventas,
        SUM(dv.cantidad * dv.precio_bs) as total_ventas_bs
    FROM detalle_ventas dv
    JOIN productos p ON dv.producto_id = p.id
    JOIN ventas v ON dv.venta_id = v.id
    WHERE v.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$datos_ventas = $stmt->fetch();

$cmv = 0;
if ($datos_ventas['total_ventas_bs'] > 0) {
    $margen_total = $datos_ventas['total_ventas_bs'] - $datos_ventas['total_costo_ventas'];
    $cmv = ($margen_total / $datos_ventas['total_ventas_bs']) * 100;
}

// Si no hay ventas recientes, calcular CMV basado en inventario
if ($cmv <= 0) {
    $stmt = $pdo->query("
        SELECT 
            SUM(costo_bs * stock) as total_costo_inventario,
            SUM(precio_bs * stock) as total_venta_inventario
        FROM productos 
        WHERE stock > 0
    ");
    $datos_inventario = $stmt->fetch();
    
    if ($datos_inventario['total_venta_inventario'] > 0) {
        $margen_total = $datos_inventario['total_venta_inventario'] - $datos_inventario['total_costo_inventario'];
        $cmv = ($margen_total / $datos_inventario['total_venta_inventario']) * 100;
    }
}

// Obtener costos fijos
$costosFijos = $pdo->query("SELECT * FROM costos_fijos ORDER BY nombre")->fetchAll();

// Calcular totales por tipo
$totales = $pdo->query("
    SELECT 
        SUM(CASE WHEN tipo = 'mensual' THEN monto ELSE 0 END) as total_mensual,
        SUM(CASE WHEN tipo = 'diario' THEN monto * 30 ELSE 0 END) as total_diario_mensual,
        SUM(CASE WHEN tipo = 'semanal' THEN monto * 4.33 ELSE 0 END) as total_semanal_mensual
    FROM costos_fijos
")->fetch();

$totalCostosFijos = $totales['total_mensual'] + $totales['total_diario_mensual'] + $totales['total_semanal_mensual'];

// Obtener ventas del mes actual para análisis dinámico
$ventasMesActual = $pdo->query("
    SELECT 
        SUM(total_bs) as total_ventas_bs,
        SUM(total_usd) as total_ventas_usd,
        COUNT(*) as total_ventas
    FROM ventas 
    WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())
")->fetch();

$ventasMesActual['total_ventas_bs'] = $ventasMesActual['total_ventas_bs'] ?: 0;
$ventasMesActual['total_ventas'] = $ventasMesActual['total_ventas'] ?: 0;

// Asegurarse de que la tasa de cambio tenga un valor válido
$tasa_cambio = $config['tasa_cambio'] ?? 36.00;
if ($tasa_cambio <= 0) {
    $tasa_cambio = 36.00; // Valor por defecto si la tasa es inválida
}

// Calcular punto de equilibrio dinámico
$puntoEquilibrioBs = 0;
$puntoEquilibrioUsd = 0;
if ($cmv > 0) {
    $puntoEquilibrioBs = $totalCostosFijos / ($cmv / 100);
    $puntoEquilibrioUsd = $puntoEquilibrioBs / $tasa_cambio;
}

// Calcular progreso hacia el punto de equilibrio
$progresoEquilibrio = 0;
if ($puntoEquilibrioBs > 0) {
    $progresoEquilibrio = min(100, ($ventasMesActual['total_ventas_bs'] / $puntoEquilibrioBs) * 100);
}

// Calcular utilidad/propérdida mensual
$utilidadMensual = ($ventasMesActual['total_ventas_bs'] * ($cmv / 100)) - $totalCostosFijos;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Punto de Equilibrio - Nine Market</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .stat-card {
            border-radius: 15px;
            border: none;
            transition: transform 0.2s;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .stat-card:hover { transform: translateY(-5px); }
        .input-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .resultado-card {
            background: linear-gradient(135deg, #198754 0%, #146c43 100%);
            color: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(25, 135, 84, 0.3);
        }
        .resultado-item {
            border-bottom: 1px solid rgba(255,255,255,0.2);
            padding: 15px 0;
        }
        .resultado-item:last-child { border-bottom: none; }
        .resultado-valor {
            font-size: 1.8rem;
            font-weight: bold;
        }
        .cmv-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 25px;
            border-radius: 10px;
            font-size: 1.2rem;
            display: inline-block;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        .grafico-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-top: 30px;
        }
        .progress-equilibrio {
            height: 30px;
            border-radius: 15px;
            overflow: hidden;
        }
        .card-estado {
            border-left: 5px solid;
            transition: all 0.3s;
        }
        .card-estado.positivo {
            border-left-color: #198754;
            background: linear-gradient(135deg, #f8fff9 0%, #e8f5e8 100%);
        }
        .card-estado.negativo {
            border-left-color: #dc3545;
            background: linear-gradient(135deg, #fff5f5 0%, #ffe6e6 100%);
        }
        .card-estado.neutral {
            border-left-color: #ffc107;
            background: linear-gradient(135deg, #fffbf0 0%, #fff3cd 100%);
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container-fluid" x-data="puntoEquilibrioApp()" x-init="init()">
        <div class="row mb-4">
            <div class="col-12">
                <h2 class="mb-3">📈 Análisis de Punto de Equilibrio Dinámico</h2>
                <div class="alert alert-info">
                    <strong>ℹ️ Sistema Dinámico:</strong><br>
                    El punto de equilibrio se calcula automáticamente en base a tus costos fijos guardados y el margen de contribución real de tus ventas. 
                    Los datos se actualizan en tiempo real según las ventas del sistema.
                </div>
            </div>
        </div>

        <!-- CMV Badge con fuente de datos -->
        <div class="row mb-4">
            <div class="col-12 text-center">
                <div class="cmv-badge">
                    <div>Margen de Contribución Promedio (CMV)</div>
                    <div class="mt-2">
                        <span style="font-size: 2.5rem;" x-text="cmv.toFixed(2)"></span>%
                    </div>
                    <small class="opacity-75">
                        <?php if ($datos_ventas['total_ventas_bs'] > 0): ?>
                            Calculado desde ventas reales (últimos 30 días)
                        <?php else: ?>
                            Calculado desde inventario actual (sin ventas recientes)
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>

        <!-- Gestión de Costos Fijos -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">💰 Costos Fijos Guardados</h5>
                        <button class="btn btn-light btn-sm" @click="abrirModalCosto()">
                            ➕ Agregar Costo
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($costosFijos)): ?>
                            <div class="text-center text-muted py-4">
                                <p>No hay costos fijos registrados</p>
                                <small>Agrega tus costos fijos para calcular el punto de equilibrio</small>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Nombre</th>
                                            <th>Monto</th>
                                            <th>Tipo</th>
                                            <th>Equivalente Mensual</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($costosFijos as $costo): 
                                            $equivalente = $costo['tipo'] === 'diario' ? $costo['monto'] * 30 : 
                                                         ($costo['tipo'] === 'semanal' ? $costo['monto'] * 4.33 : $costo['monto']);
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($costo['nombre']) ?></td>
                                            <td>Bs <?= number_format($costo['monto'], 2) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $costo['tipo'] === 'mensual' ? 'primary' : ($costo['tipo'] === 'diario' ? 'success' : 'warning') ?>">
                                                    <?= $costo['tipo'] ?>
                                                </span>
                                            </td>
                                            <td>Bs <?= number_format($equivalente, 2) ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        @click='editarCosto(<?= json_encode($costo) ?>)'>
                                                    ✏️
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" 
                                                        @click='eliminarCosto(<?= json_encode($costo) ?>)'>
                                                    🗑️
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="fw-bold">
                                        <tr>
                                            <td colspan="3">Total Costos Fijos Mensuales</td>
                                            <td>Bs <?= number_format($totalCostosFijos, 2) ?></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Análisis Dinámico -->
        <div class="row g-4 mb-4">
            <!-- Columna Izquierda: Estado Actual -->
            <div class="col-lg-6">
                <div class="card shadow-sm card-estado <?= $utilidadMensual >= 0 ? 'positivo' : ($utilidadMensual < 0 ? 'negativo' : 'neutral') ?>">
                    <div class="card-body">
                        <h5 class="card-title">📊 Estado Financiero Actual</h5>
                        
                        <div class="row mb-3">
                            <div class="col-6">
                                <small class="text-muted">Ventas del Mes</small>
                                <div class="fs-4 fw-bold text-primary">
                                    Bs <?= number_format($ventasMesActual['total_ventas_bs'], 2) ?>
                                </div>
                                <small class="text-muted"><?= $ventasMesActual['total_ventas'] ?> ventas</small>
                            </div>
                            <div class="col-6">
                                <small class="text-muted">Utilidad/Pérdida</small>
                                <div class="fs-4 fw-bold <?= $utilidadMensual >= 0 ? 'text-success' : 'text-danger' ?>">
                                    Bs <?= number_format($utilidadMensual, 2) ?>
                                </div>
                                <small class="text-muted">Mes actual</small>
                            </div>
                        </div>

                        <!-- Progreso hacia el punto de equilibrio -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-2">
                                <small class="text-muted">Progreso hacia el Punto de Equilibrio</small>
                                <small class="fw-bold"><?= number_format($progresoEquilibrio, 1) ?>%</small>
                            </div>
                            <div class="progress progress-equilibrio">
                                <div class="progress-bar 
                                    <?= $progresoEquilibrio >= 100 ? 'bg-success' : 
                                       ($progresoEquilibrio >= 75 ? 'bg-info' : 
                                       ($progresoEquilibrio >= 50 ? 'bg-warning' : 'bg-danger')) ?>" 
                                    style="width: <?= $progresoEquilibrio ?>%">
                                    <?php if ($progresoEquilibrio >= 10): ?>
                                        <?= number_format($progresoEquilibrio, 0) ?>%
                                    <?php endif; ?>
                                </div>
                            </div>
                            <small class="text-muted mt-1">
                                <?php if ($progresoEquilibrio >= 100): ?>
                                    ✅ Has superado el punto de equilibrio
                                <?php else: ?>
                                    📈 Necesitas vender Bs <?= number_format(max(0, $puntoEquilibrioBs - $ventasMesActual['total_ventas_bs']), 2) ?> más
                                <?php endif; ?>
                            </small>
                        </div>

                        <!-- Indicador de estado -->
                        <div class="text-center p-3 rounded 
                            <?= $utilidadMensual > 0 ? 'bg-success text-white' : 
                               ($utilidadMensual == 0 ? 'bg-warning text-dark' : 'bg-danger text-white') ?>">
                            <h6 class="mb-1">
                                <?php if ($utilidadMensual > 0): ?>
                                    🎉 RENTABLE
                                <?php elseif ($utilidadMensual == 0): ?>
                                    ⚖️ EN EQUILIBRIO
                                <?php else: ?>
                                    ⚠️ EN PÉRDIDAS
                                <?php endif; ?>
                            </h6>
                            <small>
                                <?php if ($utilidadMensual > 0): ?>
                                    El negocio está generando utilidades
                                <?php elseif ($utilidadMensual == 0): ?>
                                    Estás en el punto de equilibrio
                                <?php else: ?>
                                    El negocio está operando con pérdidas
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha: Punto de Equilibrio -->
            <div class="col-lg-6">
                <div class="resultado-card">
                    <h4 class="mb-4">🎯 Punto de Equilibrio Dinámico</h4>

                    <div class="resultado-item">
                        <div class="opacity-75 mb-2">Costos Fijos Mensuales</div>
                        <div class="resultado-valor">
                            Bs <?= number_format($totalCostosFijos, 2) ?>
                        </div>
                    </div>

                    <div class="resultado-item">
                        <div class="opacity-75 mb-2">Margen de Contribución (CMV)</div>
                        <div class="resultado-valor">
                            <?= number_format($cmv, 2) ?>%
                        </div>
                    </div>

                    <div class="resultado-item">
                        <div class="opacity-75 mb-2">Punto de Equilibrio Mensual</div>
                        <div class="resultado-valor">
                            Bs <?= number_format($puntoEquilibrioBs, 2) ?>
                        </div>
                        <small class="opacity-75">
                            Ventas mínimas necesarias para cubrir costos
                        </small>
                    </div>

                    <div class="resultado-item">
                        <div class="opacity-75 mb-2">En Dólares</div>
                        <div class="resultado-valor">
                            $ <?= number_format($puntoEquilibrioUsd, 2) ?>
                        </div>
                        <small class="opacity-75">
                            Tasa: <?= number_format($tasa_cambio, 2) ?> Bs/$
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Métricas Adicionales -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body text-center">
                        <h6 class="text-muted">📅 Días Restantes</h6>
                        <?php 
                        $diasTranscurridos = date('j'); // Día actual del mes
                        $diasTotales = date('t'); // Total de días del mes
                        $diasRestantes = $diasTotales - $diasTranscurridos;
                        ?>
                        <h3 class="text-primary"><?= $diasRestantes ?></h3>
                        <small class="text-muted">días en el mes</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body text-center">
                        <h6 class="text-muted">🎯 Proyección Mensual</h6>
                        <?php 
                        $proyeccionMensual = $diasTranscurridos > 0 ? 
                            ($ventasMesActual['total_ventas_bs'] / $diasTranscurridos) * $diasTotales : 0;
                        ?>
                        <h3 class="text-info">Bs <?= number_format($proyeccionMensual, 2) ?></h3>
                        <small class="text-muted">ventas proyectadas</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body text-center">
                        <h6 class="text-muted">⚡ Velocidad de Ventas</h6>
                        <?php 
                        $velocidadVentas = $diasTranscurridos > 0 ? 
                            $puntoEquilibrioBs / $diasTranscurridos : $puntoEquilibrioBs;
                        ?>
                        <h3 class="text-warning">Bs <?= number_format($velocidadVentas, 2) ?></h3>
                        <small class="text-muted">por día para alcanzar equilibrio</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Visualización gráfica -->
        <div class="row">
            <div class="col-12">
                <div class="grafico-container">
                    <h5 class="mb-4">📈 Visualización del Punto de Equilibrio Dinámico</h5>
                    <canvas id="chartEquilibrio" height="80"></canvas>
                </div>
            </div>
        </div>

        <!-- Modal para Costos Fijos -->
        <div x-show="modalCosto" 
             class="modal fade" 
             :class="{ 'show d-block': modalCosto }"
             style="background: rgba(0,0,0,0.5)"
             @click.self="cerrarModalCosto()">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="action" :value="costoSeleccionado.id ? 'editar_costo' : 'crear_costo'">
                        <input type="hidden" name="id" x-model="costoSeleccionado.id">
                        
                        <div class="modal-header">
                            <h5 class="modal-title" x-text="costoSeleccionado.id ? 'Editar Costo' : 'Nuevo Costo Fijo'"></h5>
                            <button type="button" class="btn-close" @click="cerrarModalCosto()"></button>
                        </div>
                        
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Nombre del Costo *</label>
                                <input type="text" name="nombre" x-model="costoSeleccionado.nombre" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Monto *</label>
                                <input type="number" name="monto" x-model="costoSeleccionado.monto" class="form-control" step="0.01" min="0" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Tipo *</label>
                                <select name="tipo" x-model="costoSeleccionado.tipo" class="form-select" required>
                                    <option value="mensual">Mensual</option>
                                    <option value="semanal">Semanal</option>
                                    <option value="diario">Diario</option>
                                </select>
                                <small class="text-muted">
                                    <span x-show="costoSeleccionado.tipo === 'diario'">Equivalente mensual: Bs </span>
                                    <span x-show="costoSeleccionado.tipo === 'semanal'">Equivalente mensual: Bs </span>
                                    <span x-show="costoSeleccionado.tipo === 'mensual'">Monto mensual: Bs </span>
                                    <span x-text="(costoSeleccionado.tipo === 'diario' ? costoSeleccionado.monto * 30 : 
                                                 costoSeleccionado.tipo === 'semanal' ? costoSeleccionado.monto * 4.33 : 
                                                 costoSeleccionado.monto).toFixed(2)"></span>
                                </small>
                            </div>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" @click="cerrarModalCosto()">Cancelar</button>
                            <button type="submit" class="btn btn-success">Guardar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.10/dist/cdn.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        function puntoEquilibrioApp() {
            return {
                // Datos del sistema
                cmv: <?= number_format($cmv, 2, '.', '') ?>,
                tasaCambio: <?= $tasa_cambio ?>,
                costosFijosMensuales: <?= $totalCostosFijos ?>,
                ventasActuales: <?= $ventasMesActual['total_ventas_bs'] ?>,
                puntoEquilibrio: <?= $puntoEquilibrioBs ?>,

                // Modal costos
                modalCosto: false,
                costoSeleccionado: {
                    id: '',
                    nombre: '',
                    monto: 0,
                    tipo: 'mensual'
                },

                // Chart
                chart: null,

                init() {
                    this.crearGrafico();
                },

                get progresoEquilibrio() {
                    if (this.puntoEquilibrio <= 0) return 0;
                    return Math.min(100, (this.ventasActuales / this.puntoEquilibrio) * 100);
                },

                // Gestión de costos fijos
                abrirModalCosto() {
                    this.costoSeleccionado = { id: '', nombre: '', monto: 0, tipo: 'mensual' };
                    this.modalCosto = true;
                },

                editarCosto(costo) {
                    this.costoSeleccionado = { ...costo };
                    this.modalCosto = true;
                },

                eliminarCosto(costo) {
                    if (confirm(`¿Estás seguro de eliminar el costo "${costo.nombre}"?`)) {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.style.display = 'none';
                        
                        const action = document.createElement('input');
                        action.name = 'action';
                        action.value = 'eliminar_costo';
                        form.appendChild(action);
                        
                        const id = document.createElement('input');
                        id.name = 'id';
                        id.value = costo.id;
                        form.appendChild(id);
                        
                        document.body.appendChild(form);
                        form.submit();
                    }
                },

                cerrarModalCosto() {
                    this.modalCosto = false;
                    this.costoSeleccionado = { id: '', nombre: '', monto: 0, tipo: 'mensual' };
                },

                crearGrafico() {
                    const ctx = document.getElementById('chartEquilibrio');
                    
                    const puntos = 21;
                    const maxIngresos = Math.max(this.puntoEquilibrio * 1.5, this.ventasActuales * 2, 1000);
                    const labels = Array.from({length: puntos}, (_, i) => {
                        return (i * (maxIngresos / (puntos - 1))).toFixed(0);
                    });
                    
                    this.chart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: 'Costos Fijos',
                                    data: Array(puntos).fill(0).map(() => this.costosFijosMensuales),
                                    borderColor: '#dc3545',
                                    backgroundColor: 'rgba(220, 53, 69, 0.15)',
                                    borderWidth: 3,
                                    fill: true,
                                    pointRadius: 0,
                                    tension: 0
                                },
                                {
                                    label: 'Costos Totales',
                                    data: Array.from({length: puntos}, (_, i) => {
                                        const ingresos = i * (maxIngresos / (puntos - 1));
                                        const costosVariables = ingresos * (1 - (this.cmv / 100));
                                        return this.costosFijosMensuales + costosVariables;
                                    }),
                                    borderColor: '#fd7e14',
                                    backgroundColor: 'rgba(253, 126, 20, 0.1)',
                                    borderWidth: 2,
                                    borderDash: [5, 5],
                                    fill: false,
                                    pointRadius: 0,
                                    tension: 0.1
                                },
                                {
                                    label: 'Ingresos',
                                    data: Array.from({length: puntos}, (_, i) => {
                                        return i * (maxIngresos / (puntos - 1));
                                    }),
                                    borderColor: '#198754',
                                    backgroundColor: 'rgba(25, 135, 84, 0.15)',
                                    borderWidth: 4,
                                    fill: true,
                                    pointRadius: 0,
                                    tension: 0
                                },
                                {
                                    label: 'Ventas Actuales',
                                    data: Array(puntos).fill(0).map((_, i) => {
                                        const x = i * (maxIngresos / (puntos - 1));
                                        return x >= this.ventasActuales ? null : this.ventasActuales;
                                    }),
                                    borderColor: '#0d6efd',
                                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                                    borderWidth: 2,
                                    borderDash: [3, 3],
                                    fill: false,
                                    pointRadius: 0,
                                    tension: 0
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            interaction: {
                                mode: 'index',
                                intersect: false
                            },
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Análisis Dinámico de Punto de Equilibrio',
                                    font: { size: 16, weight: 'bold' }
                                },
                                legend: {
                                    position: 'top',
                                    labels: { padding: 15, font: { size: 12 } }
                                },
                                tooltip: {
                                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                    padding: 12,
                                    titleFont: { size: 14 },
                                    bodyFont: { size: 13 },
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.dataset.label || '';
                                            if (label) {
                                                label += ': ';
                                            }
                                            label += 'Bs ' + context.parsed.y.toFixed(2);
                                            return label;
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Costos e Ingresos (Bs)',
                                        font: { size: 14, weight: 'bold' }
                                    },
                                    ticks: {
                                        callback: function(value) {
                                            return 'Bs ' + value.toFixed(0);
                                        }
                                    },
                                    grid: {
                                        color: 'rgba(0, 0, 0, 0.05)'
                                    }
                                },
                                x: {
                                    title: {
                                        display: true,
                                        text: 'Ingresos por Ventas (Bs)',
                                        font: { size: 14, weight: 'bold' }
                                    },
                                    ticks: {
                                        callback: function(value, index) {
                                            return 'Bs ' + this.getLabelForValue(value);
                                        }
                                    },
                                    grid: {
                                        color: 'rgba(0, 0, 0, 0.05)'
                                    }
                                }
                            }
                        }
                    });
                }
            }
        }
    </script>
</body>
</html>